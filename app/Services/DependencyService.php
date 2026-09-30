<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\TaskDependency;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DependencyService
{
    public function __construct(private WorkflowService $workflow) {}

    public function list(Project $project, array $filters): LengthAwarePaginator
    {
        return $project->dependencies()->orderBy('id')->paginate($filters['per_page'] ?? 20);
    }

    public function create(User $actor, Project $project, array $data): TaskDependency
    {
        return DB::transaction(function () use ($actor, $project, $data): TaskDependency {
            $project = $this->workflow->project($project, $actor);
            $predecessor = $project->tasks()->findOrFail($data['predecessor_task_id']);
            $successor = $project->tasks()->findOrFail($data['successor_task_id']);
            abort_if($predecessor->is($successor), 422, 'Task tidak dapat bergantung pada dirinya sendiri.');
            foreach ([$predecessor, $successor] as $task) {
                $this->workflow->task($task, $actor);
                abort_if($task->children()->exists(), 409, 'Dependency hanya untuk leaf task.');
            }
            abort_if($successor->status !== TaskStatus::Todo && $predecessor->status !== TaskStatus::Done, 409, 'Dependency melanggar status task yang sedang berjalan.');
            $edges = $project->dependencies()->get();
            abort_if($edges->contains(fn ($edge) => $edge->predecessor_task_id === $predecessor->id && $edge->successor_task_id === $successor->id), 409, 'Dependency sudah tersedia.');
            $adjacency = $edges->groupBy('predecessor_task_id');
            $pending = [$successor->id];
            $visited = [];
            while ($pending !== []) {
                $id = array_pop($pending);
                abort_if($id === $predecessor->id, 422, 'Dependency membentuk siklus.');
                if (isset($visited[$id])) {
                    continue;
                }
                $visited[$id] = true;
                foreach ($adjacency->get($id, collect()) as $edge) {
                    $pending[] = $edge->successor_task_id;
                }
            }
            $dependency = $project->dependencies()->create([...$data, 'dependency_type' => 'FS']);
            $this->workflow->audit($actor, $dependency, 'dependency.created', $project->workspace_id, $project->id);

            return $dependency;
        });
    }

    public function delete(User $actor, Project $project, TaskDependency $dependency): void
    {
        DB::transaction(function () use ($actor, $project, $dependency): void {
            $project = $this->workflow->project($project, $actor);
            $dependency = $project->dependencies()->findOrFail($dependency->id);
            $before = $dependency->getAttributes();
            $dependency->delete();
            $this->workflow->audit($actor, $dependency, 'dependency.deleted', $project->workspace_id, $project->id, $before);
        });
    }
}
