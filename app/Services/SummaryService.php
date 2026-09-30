<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SummaryService
{
    public function invalidate(string $workspaceId, ?string $projectId = null): void
    {
        DB::afterCommit(function () use ($workspaceId, $projectId): void {
            Cache::forever('summary:workspace:'.$workspaceId.':version', (string) Str::uuid7());
            if ($projectId !== null) {
                Cache::forever('summary:project:'.$projectId.':version', (string) Str::uuid7());
            }
        });
    }

    public function workspace(User $user, Workspace $workspace): array
    {
        return $this->remember('workspace', $workspace->id, $user, function () use ($user, $workspace): array {
            $projects = $this->visibleProjects($user)->where('workspace_id', $workspace->id)->whereNull('archived_at');
            $tasks = Task::query()->whereIn('project_id', (clone $projects)->select('projects.id'))
                ->whereNull('archived_at')->doesntHave('children');

            return [
                'projects' => (clone $projects)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
                ...$this->taskCounts($tasks),
            ];
        });
    }

    public function project(User $user, Project $project): array
    {
        return $this->remember('project', $project->id, $user, fn (): array => $this->taskCounts(
            $project->tasks()->getQuery()->whereNull('archived_at')->doesntHave('children'),
        ));
    }

    public function visibleProjects(User $user): Builder
    {
        return Project::query()->when(! $user->is_super_admin, fn (Builder $query) => $query
            ->whereHas('memberships', fn (Builder $members) => $members->where('user_id', $user->id))
            ->whereHas('workspace.memberships', fn (Builder $members) => $members->where('user_id', $user->id)));
    }

    private function taskCounts(Builder $tasks): array
    {
        return [
            'tasks' => (clone $tasks)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'overdue_tasks' => (clone $tasks)->where('status', '!=', 'done')->where('due_date', '<', now())->count(),
        ];
    }

    private function remember(string $scope, string $id, User $user, Closure $read): array
    {
        if (request()->isMethod('HEAD') || ! config('traffic.summary_enabled') || config('traffic.summary_ttl') <= 0) {
            return $read();
        }

        $versionKey = 'summary:'.$scope.':'.$id.':version';
        Cache::add($versionKey, (string) Str::uuid7());
        $key = 'summary:v1:'.$scope.':'.$id.':'.$user->id.':'.Cache::get($versionKey);

        return Cache::remember($key, config('traffic.summary_ttl'), $read);
    }
}
