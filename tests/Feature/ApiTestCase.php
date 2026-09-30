<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectService;
use App\Services\TaskService;
use App\Services\WorkspaceService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use DatabaseMigrations;

    protected User $manager;

    protected Workspace $workspace;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        config(['traffic.read' => 1000, 'traffic.write' => 1000]);
        $this->withHeader('Origin', 'http://localhost:3000');
        $this->manager = User::factory()->create();
        $this->workspace = app(WorkspaceService::class)->create($this->manager, ['name' => 'Workspace', 'slug' => 'workspace']);
        $this->project = app(ProjectService::class)->create($this->manager, $this->workspace, ['name' => 'Project', 'slug' => 'project']);
        $this->actingAs($this->manager, 'web');
    }

    public function actingAs(Authenticatable $user, mixed $guard = null): static
    {
        Auth::forgetGuards();

        return parent::actingAs($user, $guard);
    }

    protected function member(string $role = 'member'): User
    {
        $user = User::factory()->create();
        $this->workspace->memberships()->forceCreate(['user_id' => $user->id, 'role' => 'member']);
        $this->project->memberships()->forceCreate(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    protected function task(array $attributes = []): Task
    {
        return app(TaskService::class)->create($this->manager, $this->project, ['name' => 'Task', ...$attributes]);
    }

    protected function projectUrl(string $suffix = ''): string
    {
        return '/api/v1/projects/'.$this->project->id.$suffix;
    }

    protected function taskUrl(Task $task, string $suffix = ''): string
    {
        return $this->projectUrl('/tasks/'.$task->id.$suffix);
    }
}
