<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(private WorkflowService $workflow) {}

    public function list(User $actor, Project $project, array $filters): LengthAwarePaginator
    {
        $page = $project->tasks()->with('assignees')->withCount('children')
            ->when(! ($filters['include_archived'] ?? false), fn ($q) => $q->whereNull('archived_at'))
            ->when($filters['search'] ?? null, fn ($q, $value) => $q->where('name', 'like', '%'.$value.'%'))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['priority'] ?? null, fn ($q, $value) => $q->where('priority', $value))
            ->when(array_key_exists('parent_id', $filters), fn ($q) => $q->where('parent_id', $filters['parent_id']))
            ->when($filters['my_tasks'] ?? false, fn ($q) => $q->whereHas('assignments', fn ($q) => $q->where('user_id', $actor->id)))
            ->when($filters['overdue'] ?? false, fn ($q) => $q->doesntHave('children')->where('status', '!=', 'done')->where('due_date', '<', now()))
            ->orderBy('created_at')->orderBy('id')->paginate($filters['per_page'] ?? 20);
        $this->decorate($project, $page->getCollection());

        return $page;
    }

    public function show(Task $task): Task
    {
        $task->load('assignees')->loadCount('children');
        $this->decorate($task->project, new Collection([$task]));

        return $task;
    }

    public function create(User $actor, Project $project, array $data): Task
    {
        return DB::transaction(function () use ($actor, $project, $data): Task {
            $project = $this->workflow->project($project, $actor);
            if (! empty($data['parent_id'])) {
                $parent = $project->tasks()->findOrFail($data['parent_id']);
                $this->workflow->task($parent, $actor);
                $this->assertCanBecomeParent($parent);
            }
            $task = new Task($this->workflow->dates($data, ['start_date']));
            $task->project_id = $project->id;
            $task->created_by = $actor->id;
            $this->schedule($task);
            $this->validatePert($task);
            $task->save();
            $this->workflow->audit($actor, $task, 'task.created', $project->workspace_id, $project->id);
            $this->refreshParents($project, $actor);

            return $this->show($task->fresh());
        });
    }

    public function update(User $actor, Task $task, array $data): Task
    {
        return DB::transaction(function () use ($actor, $task, $data): Task {
            $task = $this->workflow->task($task, $actor);
            $before = $task->getAttributes();
            if ($task->children()->exists()) {
                $invalid = array_intersect(array_keys($data), ['start_date', 'duration_days', 'optimistic_time', 'most_likely_time', 'pessimistic_time']);
                abort_if($invalid !== [], 409, 'Jadwal dan estimasi parent merupakan ringkasan.');
            }
            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== $task->parent_id && $data['parent_id'] !== null) {
                $parent = $task->project->tasks()->findOrFail($data['parent_id']);
                $cursor = $parent;
                do {
                    abort_if($cursor->id === $task->id, 422, 'Hierarchy tidak boleh membentuk siklus.');
                    abort_if($cursor->archived_at, 409, 'Parent telah diarsipkan.');
                    $cursor = $cursor->parent;
                } while ($cursor);
                $this->assertCanBecomeParent($parent);
            }
            $task->fill($this->workflow->dates($data, ['start_date']));
            if (! $task->children()->exists()) {
                $this->schedule($task);
                $this->validatePert($task);
            }
            $task->save();
            $this->workflow->audit($actor, $task, 'task.updated', $task->project->workspace_id, $task->project_id, $before);
            if ($task->parent_id !== $before['parent_id']) {
                $this->resetEmptyParent($task->project, $before['parent_id'], $actor);
            }
            $this->refreshParents($task->project, $actor);

            return $this->show($task->fresh());
        });
    }

    public function status(User $actor, Task $task, string $status): Task
    {
        return DB::transaction(function () use ($actor, $task, $status): Task {
            $task = $this->workflow->task($task, $actor, 'status');
            abort_if($task->project->status === ProjectStatus::OnHold, 409, 'Status task tidak dapat diubah saat project On Hold.');
            abort_if($task->children()->exists(), 409, 'Status parent dihitung otomatis.');
            $target = TaskStatus::from($status);
            if ($target === $task->status) {
                return $this->show($task);
            }
            abort_if($target !== TaskStatus::Todo && $task->predecessors()->where('status', '!=', TaskStatus::Done->value)->exists(), 409, 'Semua predecessor harus Done.');
            abort_if($target !== TaskStatus::Done && $task->successors()->where('status', '!=', TaskStatus::Todo->value)->exists(), 409, 'Successor telah mulai; predecessor tidak dapat dibuka kembali.');
            $before = $task->getAttributes();
            $previous = $task->status;
            $task->status = $target;
            $task->save();
            $this->history($actor, $task, $previous);
            $this->workflow->audit($actor, $task, 'task.status_changed', $task->project->workspace_id, $task->project_id, $before);
            $this->refreshParents($task->project, $actor);

            return $this->show($task->fresh());
        });
    }

    public function assign(User $actor, Task $task, array $userIds): Task
    {
        return DB::transaction(function () use ($actor, $task, $userIds): Task {
            $task = $this->workflow->task($task, $actor);
            abort_if($task->children()->exists(), 409, 'Assignment hanya berlaku untuk leaf task.');
            $valid = $task->project->memberships()->whereIn('user_id', $userIds)->whereIn('role', ['manager', 'member'])
                ->whereHas('user', fn ($q) => $q->where('is_active', true)->whereNotNull('email_verified_at'))
                ->whereHas('user.workspaceMemberships', fn ($q) => $q->where('workspace_id', $task->project->workspace_id))
                ->count();
            abort_unless($valid === count($userIds), 422, 'Assignee harus member/manager aktif di project.');
            $before = ['user_ids' => $task->assignments()->pluck('user_id')->all()];
            $task->assignees()->sync($userIds);
            $this->workflow->audit($actor, $task, 'task.assignment_changed', $task->project->workspace_id, $task->project_id, $before, ['user_ids' => $userIds]);

            return $this->show($task->fresh());
        });
    }

    public function archive(User $actor, Task $task, bool $archived): Task
    {
        return DB::transaction(function () use ($actor, $task, $archived): Task {
            $project = $this->workflow->project($task->project, $actor);
            $task = $project->tasks()->findOrFail($task->id);
            $cursor = $task->parent;
            while ($cursor) {
                abort_if($cursor->archived_at, 409, 'Pulihkan parent terlebih dahulu.');
                $cursor = $cursor->parent;
            }
            if ($archived) {
                abort_if($task->children()->whereNull('archived_at')->exists(), 409, 'Arsipkan anak aktif terlebih dahulu.');
                abort_if($task->predecessors()->whereNull('archived_at')->exists() || $task->successors()->whereNull('archived_at')->exists(), 409, 'Bereskan dependency aktif sebelum mengarsipkan task.');
            } else {
                abort_if($task->predecessors()->whereNotNull('archived_at')->exists() || $task->successors()->whereNotNull('archived_at')->exists(), 409, 'Dependency masih terhubung dengan task arsip.');
            }
            $before = $task->getAttributes();
            $task->archived_at = $archived ? now() : null;
            $task->save();
            $this->workflow->audit($actor, $task, $archived ? 'task.archived' : 'task.restored', $project->workspace_id, $project->id, $before);
            $this->refreshParents($project, $actor);

            return $this->show($task->fresh());
        });
    }

    public function delete(User $actor, Task $task): void
    {
        DB::transaction(function () use ($actor, $task): void {
            $task = $this->workflow->task($task, $actor);
            abort_if($this->workflow->hasHistory($task), 409, 'Task memiliki riwayat; gunakan arsip.');
            foreach (['children', 'comments', 'attachments', 'statusHistories', 'incomingDependencies', 'outgoingDependencies', 'resourceAssignments', 'budgetItems', 'expenses', 'pertResults', 'cpmResults'] as $relation) {
                abort_if($task->$relation()->exists(), 409, 'Task memiliki referensi; gunakan arsip.');
            }
            $task->assignees()->detach();
            $task->delete();
            $this->workflow->audit($actor, $task, 'task.deleted', $task->project->workspace_id, $task->project_id);
            $this->resetEmptyParent($task->project, $task->parent_id, $actor);
            $this->refreshParents($task->project, $actor);
        });
    }

    public function historyList(Task $task, array $filters): LengthAwarePaginator
    {
        return $task->statusHistories()->orderByDesc('changed_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 20);
    }

    private function assertCanBecomeParent(Task $task): void
    {
        if ($task->children()->exists()) {
            return;
        }
        abort_if($task->status !== TaskStatus::Todo, 409, 'Task yang sudah berjalan tidak dapat menjadi parent.');
        foreach (['assignments', 'incomingDependencies', 'outgoingDependencies', 'resourceAssignments', 'budgetItems', 'expenses', 'pertResults', 'cpmResults'] as $relation) {
            abort_if($task->$relation()->exists(), 409, 'Bereskan relasi operasional sebelum menjadikan task sebagai parent.');
        }
    }

    private function schedule(Task $task): void
    {
        $task->start_date = $task->start_date?->utc();
        $dueDate = $task->start_date?->addMicroseconds(
            BigDecimal::of($task->duration_days)->multipliedBy('86400000000')->toInt(),
        );
        if ($dueDate && $dueDate->year > 9999) {
            throw ValidationException::withMessages(['duration_days' => 'Durasi melampaui rentang tanggal database.']);
        }
        $task->due_date = $dueDate;
    }

    private function validatePert(Task $task): void
    {
        $values = array_filter([$task->optimistic_time, $task->most_likely_time, $task->pessimistic_time], fn ($value) => $value !== null);
        $previous = null;
        foreach ($values as $value) {
            if ($previous !== null && BigDecimal::of($previous)->isGreaterThan($value)) {
                throw ValidationException::withMessages(['optimistic_time' => 'Estimasi harus mengikuti O <= M <= P.']);
            }
            $previous = $value;
        }
    }

    private function history(User $actor, Task $task, TaskStatus $previous): void
    {
        $history = new TaskStatusHistory(['task_id' => $task->id, 'from_status' => $previous, 'to_status' => $task->status]);
        $history->changed_by = $actor->id;
        $history->save();
    }

    private function resetEmptyParent(Project $project, ?string $parentId, User $actor): void
    {
        if ($parentId === null) {
            return;
        }
        $parent = $project->tasks()->findOrFail($parentId);
        if ($parent->children()->exists()) {
            return;
        }
        $before = $parent->getAttributes();
        $previous = $parent->status;
        $parent->fill([
            'status' => TaskStatus::Todo,
            'start_date' => null,
            'due_date' => null,
            'duration_days' => '1.0000',
            'optimistic_time' => null,
            'most_likely_time' => null,
            'pessimistic_time' => null,
        ])->save();
        if ($previous !== $parent->status) {
            $this->history($actor, $parent, $previous);
        }
        $this->workflow->audit($actor, $parent, 'task.summary_reset', $project->workspace_id, $project->id, $before);
    }

    private function refreshParents(Project $project, User $actor): void
    {
        $tasks = $project->tasks()->get()->keyBy('id');
        $children = $tasks->groupBy('parent_id');
        $summarize = function (Task $task) use (&$summarize, $children, $actor, $project): array {
            $descendants = $children->get($task->id, collect());
            if ($descendants->isEmpty()) {
                return $task->archived_at ? [] : [$task];
            }
            $leaves = [];
            foreach ($descendants as $child) {
                $childLeaves = $summarize($child);
                if (! $child->archived_at) {
                    array_push($leaves, ...$childLeaves);
                }
            }
            $before = $task->getAttributes();
            $previous = $task->status;
            $statuses = array_unique(array_map(fn (Task $leaf): string => $leaf->status->value, $leaves));
            $task->status = match (true) {
                $statuses === [], $statuses === ['todo'] => TaskStatus::Todo,
                $statuses === ['done'] => TaskStatus::Done,
                array_diff($statuses, ['review', 'done']) === [] => TaskStatus::Review,
                default => TaskStatus::InProgress,
            };
            $scheduled = collect($leaves)->filter(fn (Task $leaf) => $leaf->start_date !== null);
            $task->start_date = $scheduled->min('start_date');
            $task->due_date = $scheduled->max('due_date');
            $task->duration_days = $task->start_date && $task->due_date
                ? (string) BigDecimal::of((string) round($task->start_date->diffInMicroseconds($task->due_date)))->dividedBy('86400000000', 4, RoundingMode::HalfUp)
                : '1.0000';
            if ($task->isDirty()) {
                $task->save();
                if ($previous !== $task->status) {
                    $this->history($actor, $task, $previous);
                }
                $this->workflow->audit($actor, $task, 'task.summary_updated', $project->workspace_id, $project->id, $before);
            }

            return $leaves;
        };
        foreach ($tasks->whereNull('parent_id') as $root) {
            $summarize($root);
        }
    }

    private function decorate(Project $project, Collection $tasks): void
    {
        $all = $project->tasks()->get(['id', 'parent_id', 'archived_at', 'start_date'])->groupBy('parent_id');
        $incomplete = function (Task $task) use (&$incomplete, $all): bool {
            $children = $all->get($task->id, collect());
            if ($children->isEmpty()) {
                return $task->start_date === null;
            }
            $active = $children->whereNull('archived_at');

            return $active->isEmpty() || $active->contains(fn (Task $child): bool => $incomplete($child));
        };
        foreach ($tasks as $task) {
            $task->setAttribute('schedule_incomplete', $incomplete($task));
        }
    }
}
