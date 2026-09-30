<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    public function __construct(private WorkflowService $workflow, private SummaryService $summaries) {}

    public function list(User $actor, array $filters, ?Workspace $workspace = null): LengthAwarePaginator
    {
        return $this->summaries->visibleProjects($actor)
            ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
            ->when(! ($filters['include_archived'] ?? false), fn ($q) => $q->whereNull('archived_at')->whereHas('workspace', fn ($q) => $q->whereNull('archived_at')))
            ->when($filters['search'] ?? null, fn ($q, $value) => $q->where('name', 'like', '%'.$value.'%'))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['priority'] ?? null, fn ($q, $value) => $q->where('priority', $value))
            ->orderByDesc('created_at')->orderBy('id')->paginate($filters['per_page'] ?? 20);
    }

    public function create(User $actor, Workspace $workspace, array $data): Project
    {
        return DB::transaction(function () use ($actor, $workspace, $data): Project {
            $workspace = $this->workflow->workspace($workspace, $actor);
            $project = new Project($this->workflow->dates($data, ['start_date', 'target_end_date']));
            $project->workspace_id = $workspace->id;
            $project->created_by = $actor->id;
            $this->validateDates($project);
            $project->save();
            if (! $workspace->memberships()->where('user_id', $actor->id)->exists()) {
                $workspace->memberships()->forceCreate(['user_id' => $actor->id, 'role' => 'member']);
            }
            $project->memberships()->forceCreate(['user_id' => $actor->id, 'role' => 'manager']);
            $this->workflow->audit($actor, $project, 'project.created', $workspace->id, $project->id);

            return $project;
        });
    }

    public function update(User $actor, Project $project, array $data): Project
    {
        return DB::transaction(function () use ($actor, $project, $data): Project {
            $project = $this->workflow->project($project, $actor);
            $before = $project->getAttributes();
            if (isset($data['currency']) && $data['currency'] !== $project->currency) {
                abort_if($project->resources()->exists() || $project->budgets()->exists() || $project->expenses()->exists() || $project->baselines()->exists(), 409, 'Currency sudah digunakan oleh data biaya.');
            }
            $project->fill($this->workflow->dates($data, ['start_date', 'target_end_date']));
            $this->validateDates($project);
            $project->save();
            $this->workflow->audit($actor, $project, 'project.updated', $project->workspace_id, $project->id, $before);

            return $project;
        });
    }

    public function status(User $actor, Project $project, string $status): Project
    {
        return DB::transaction(function () use ($actor, $project, $status): Project {
            $project = $this->workflow->project($project, $actor, allowClosed: true);
            $before = $project->getAttributes();
            $target = ProjectStatus::from($status);
            if ($project->status === $target) {
                return $project;
            }
            if (in_array($project->status, [ProjectStatus::Completed, ProjectStatus::Cancelled], true)) {
                abort_unless($target === ProjectStatus::Active, 409, 'Project tertutup hanya dapat dibuka kembali menjadi Active.');
            }
            if ($target === ProjectStatus::Completed) {
                $leaves = $project->tasks()->whereNull('archived_at')->doesntHave('children');
                abort_if(! (clone $leaves)->exists() || (clone $leaves)->where('status', '!=', TaskStatus::Done->value)->exists(), 409, 'Seluruh leaf task aktif harus Done.');
                $project->actual_end_date = now();
            } else {
                $project->actual_end_date = null;
            }
            $project->status = $target;
            $project->save();
            $this->workflow->audit($actor, $project, 'project.status_changed', $project->workspace_id, $project->id, $before);

            return $project;
        });
    }

    public function archive(User $actor, Project $project, bool $archived): Project
    {
        return DB::transaction(function () use ($actor, $project, $archived): Project {
            $project = $this->workflow->project($project, $actor, allowClosed: true, allowArchived: true);
            $before = $project->getAttributes();
            $project->archived_at = $archived ? now() : null;
            $project->save();
            $this->workflow->audit($actor, $project, $archived ? 'project.archived' : 'project.restored', $project->workspace_id, $project->id, $before);

            return $project;
        });
    }

    public function members(Project $project, array $filters): LengthAwarePaginator
    {
        return $project->memberships()->with('user')->orderBy('id')->paginate($filters['per_page'] ?? 20);
    }

    public function addMember(User $actor, Project $project, array $data): ProjectMember
    {
        return DB::transaction(function () use ($actor, $project, $data): ProjectMember {
            $project = $this->workflow->project($project, $actor);
            $user = $project->workspace->members()->where('users.id', $data['user_id'])->firstOrFail();
            abort_unless($user->is_active && $user->hasVerifiedEmail(), 409, 'Member harus aktif dan terverifikasi.');
            abort_if($project->memberships()->where('user_id', $user->id)->exists(), 409, 'Member sudah terdaftar.');
            $project->memberships()->forceCreate(['user_id' => $user->id, 'role' => $data['role']]);
            $membership = $project->memberships()->where('user_id', $user->id)->firstOrFail();
            $this->workflow->audit($actor, $membership, 'project.member_added', $project->workspace_id, $project->id);

            return $membership->load('user');
        });
    }

    public function changeMember(User $actor, Project $project, ProjectMember $membership, ?string $role): void
    {
        DB::transaction(function () use ($actor, $project, $membership, $role): void {
            $project = $this->workflow->project($project, $actor);
            $membership = $project->memberships()->findOrFail($membership->id);
            $before = $membership->getAttributes();
            abort_if($membership->role->value === 'manager' && $role !== 'manager'
                && $project->memberships()->where('role', 'manager')->count() <= 1, 409, 'Manager terakhir tidak dapat dihapus atau diturunkan.');
            if ($role === null || ! in_array($role, ['manager', 'member'], true)) {
                abort_if($project->tasks()->whereHas('assignments', fn ($q) => $q->where('user_id', $membership->user_id))->exists(), 409, 'Hapus assignment task terlebih dahulu.');
            }
            if ($role === null) {
                $membership->delete();
            } else {
                $membership->role = $role;
                $membership->save();
            }
            $this->workflow->audit($actor, $membership, $role === null ? 'project.member_removed' : 'project.member_updated', $project->workspace_id, $project->id, $before);
        });
    }

    public function delete(User $actor, Project $project): void
    {
        DB::transaction(function () use ($actor, $project): void {
            $project = $this->workflow->project($project, $actor);
            foreach (['tasks', 'resources', 'budgets', 'expenses', 'baselines', 'pertAnalyses', 'cpmAnalyses', 'evmAnalyses', 'activityLogs'] as $relation) {
                abort_if($project->$relation()->exists(), 409, 'Project memiliki riwayat atau referensi; gunakan arsip.');
            }
            $project->members()->detach();
            $project->delete();
            $this->workflow->audit($actor, $project, 'project.deleted', $project->workspace_id);
        });
    }

    private function validateDates(Project $project): void
    {
        if ($project->start_date && $project->target_end_date && $project->target_end_date->lt($project->start_date)) {
            throw ValidationException::withMessages(['target_end_date' => 'Target selesai harus setelah atau sama dengan tanggal mulai.']);
        }
    }
}
