<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class WorkflowService
{
    public function __construct(private SummaryService $summaries) {}

    public function workspace(Workspace $workspace, User $actor, string $ability = 'update', bool $allowArchived = false): Workspace
    {
        $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
        Gate::forUser($actor)->authorize($ability, $workspace);
        abort_if(! $allowArchived && $workspace->archived_at, 409, 'Workspace telah diarsipkan.');

        return $workspace;
    }

    public function project(Project $project, User $actor, string $ability = 'update', bool $allowClosed = false, bool $allowArchived = false): Project
    {
        $workspace = Workspace::query()->lockForUpdate()->findOrFail($project->workspace_id);
        $project = Project::query()->lockForUpdate()->findOrFail($project->id);
        $project->setRelation('workspace', $workspace);
        Gate::forUser($actor)->authorize($ability, $project);
        abort_if($workspace->archived_at, 409, 'Workspace telah diarsipkan.');
        abort_if(! $allowArchived && $project->archived_at, 409, 'Project telah diarsipkan.');
        abort_if(! $allowClosed && in_array($project->status, [ProjectStatus::Completed, ProjectStatus::Cancelled], true), 409, 'Buka kembali project sebelum mengubah data.');

        return $project;
    }

    public function task(Task $task, User $actor, string $ability = 'update'): Task
    {
        $project = $this->project($task->project, $actor, 'view');
        $task = $project->tasks()->findOrFail($task->id);
        $task->setRelation('project', $project);
        Gate::forUser($actor)->authorize($ability, $task);
        $cursor = $task;
        do {
            abort_if($cursor->archived_at, 409, 'Task atau parent telah diarsipkan.');
            $cursor = $cursor->parent;
        } while ($cursor !== null);

        return $task;
    }

    public function audit(User $actor, Model $entity, string $action, string $workspaceId, ?string $projectId = null, ?array $before = null, ?array $after = null): void
    {
        $hidden = ['password', 'remember_token', 'token_hash', 'file_path'];
        $entry = new ActivityLog([
            'workspace_id' => $workspaceId,
            'project_id' => $projectId,
            'action' => $action,
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => $entity->getKey(),
            'old_values' => $before === null ? null : Arr::except($before, $hidden),
            'new_values' => $entity->exists ? Arr::except($after ?? $entity->getAttributes(), $hidden) : null,
        ]);
        $entry->user_id = $actor->id;
        $entry->save();
        $this->summaries->invalidate($workspaceId, $projectId);
    }

    public function dates(array $data, array $columns): array
    {
        foreach ($columns as $column) {
            if (isset($data[$column])) {
                $data[$column] = CarbonImmutable::parse($data[$column])->utc();
            }
        }

        return $data;
    }

    public function hasHistory(Model $entity): bool
    {
        return ActivityLog::query()->where('entity_type', $entity->getMorphClass())->where('entity_id', $entity->getKey())->exists();
    }
}
