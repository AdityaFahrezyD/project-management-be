<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Resource;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class UuidIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_entity_and_audit_ids_persist_and_serialize_as_uuid_strings(): void
    {
        $task = Task::factory()->create();
        $project = $task->project;
        $user = $task->creator;
        $log = ActivityLog::forceCreate([
            'user_id' => $user->id,
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'action' => 'task.created',
            'entity_type' => Task::class,
            'entity_id' => $task->id,
            'new_values' => ['project_id' => $project->id],
        ]);
        $copy = $task->replicate();
        $copy->save();

        foreach ([$user, $project->workspace, $project, $task, $log, $copy] as $model) {
            $this->assertUuidIdentity($model->fresh());
        }

        $this->assertNotSame($task->id, $copy->id);
        $this->assertSame($project->id, $task->toArray()['project_id']);
        $this->assertSame($task->id, $log->fresh()->toArray()['entity_id']);
        $this->assertSame($project->id, $log->fresh()->new_values['project_id']);
        $this->assertTrue($log->fresh()->entity->is($task));
        $this->assertTrue($user->createdTasks->contains($task));
    }

    public function test_membership_attach_and_sync_generate_and_preserve_pivot_uuids(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->for($workspace)->create();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $workspace->members()->attach($first->id);
        $membership = $workspace->memberships()->sole();
        $this->assertUuidIdentity($membership);
        $workspace->members()->sync([$first->id, $second->id]);
        $this->assertSame($membership->id, $workspace->memberships()->where('user_id', $first->id)->sole()->id);
        $this->assertCount(2, $workspace->memberships()->get());

        $project->members()->attach($first->id);
        $second->projects()->syncWithoutDetaching([$project->id]);

        foreach ($project->members()->get() as $member) {
            $this->assertUuidIdentity($member->pivot);
        }

        $this->assertCount(2, $project->memberships()->get());
        $workspace->members()->sync([$second->id]);
        $this->assertSame($second->id, $workspace->members()->sole()->id);
        $this->assertDatabaseMissing('workspace_members', ['id' => $membership->id]);

        $anotherWorkspace = Workspace::factory()->create();
        $second->workspaces()->syncWithoutDetaching([$anotherWorkspace->id]);
        $this->assertUuidIdentity($anotherWorkspace->memberships()->sole());
    }

    public function test_task_assignee_and_resource_pivots_generate_uuids_from_both_sides(): void
    {
        $task = Task::factory()->create();
        $otherTask = Task::factory()->for($task->project)->create();
        $user = $task->creator;
        $resource = Resource::factory()->for($task->project)->create();

        $task->assignees()->attach($user->id);
        $user->assignedTasks()->syncWithoutDetaching([$otherTask->id]);
        $this->assertUuidIdentity($task->assignments()->sole());
        $this->assertUuidIdentity($otherTask->assignments()->sole());

        $task->resources()->attach($resource->id, [
            'quantity' => '2.0000',
            'duration' => '0.5000',
            'estimated_cost' => '500000.0000',
        ]);
        $allocation = $task->resourceAssignments()->sole();
        $this->assertUuidIdentity($allocation);

        $task->resources()->sync([
            $resource->id => ['quantity' => '3.0000', 'duration' => '0.5000', 'estimated_cost' => '750000.0000'],
        ]);
        $this->assertSame($allocation->id, $task->resourceAssignments()->sole()->id);
        $this->assertSame('3.0000', $allocation->fresh()->quantity);

        $resource->tasks()->syncWithoutDetaching([
            $otherTask->id => ['quantity' => '1.0000', 'duration' => '1.0000', 'estimated_cost' => '500000.0000'],
        ]);
        $this->assertUuidIdentity($otherTask->resourceAssignments()->sole());
        $this->assertCount(2, $resource->tasks()->get());

        $task->assignees()->detach($user->id);
        $this->assertCount(0, $task->assignments()->get());
        $this->assertTrue($user->assignedTasks()->sole()->is($otherTask));
    }

    public function test_dependency_pivots_generate_uuids_in_both_directions(): void
    {
        $project = Project::factory()->create();
        $first = Task::factory()->for($project)->create();
        $second = Task::factory()->for($project)->create();
        $third = Task::factory()->for($project)->create();

        $first->successors()->attach($second->id, ['project_id' => $project->id]);
        $third->predecessors()->attach($second->id, ['project_id' => $project->id]);

        foreach ($project->dependencies()->get() as $dependency) {
            $this->assertUuidIdentity($dependency);
        }

        $this->assertTrue($first->successors()->sole()->is($second));
        $this->assertTrue($third->predecessors()->sole()->is($second));
        $this->assertTrue($second->successors()->sole()->is($third));
        $this->assertSame($project->id, $second->successors()->sole()->pivot->project_id);
    }

    public function test_uuid_sanctum_tokens_authenticate_and_can_be_revoked(): void
    {
        Route::get('/uuid-token-test', fn (Request $request): array => ['id' => $request->user()->id])
            ->middleware('auth:sanctum');

        $user = User::factory()->create();
        $token = $user->createToken('uuid-token');
        $this->assertInstanceOf(PersonalAccessToken::class, $token->accessToken);
        $this->assertUuidIdentity($token->accessToken->fresh());
        $this->assertSame($user->id, $token->accessToken->tokenable_id);
        $this->assertTrue(PersonalAccessToken::findToken($token->plainTextToken)->is($token->accessToken));

        $this->withToken($token->plainTextToken)->getJson('/uuid-token-test')
            ->assertOk()
            ->assertJsonPath('id', $user->id);

        $this->assertNotNull($token->accessToken->fresh()->last_used_at);
        $token->accessToken->delete();
        Auth::forgetGuards();

        $this->withToken($token->plainTextToken)->getJson('/uuid-token-test')->assertUnauthorized();
    }

    public function test_database_sessions_keep_native_session_ids_and_uuid_user_references(): void
    {
        $user = User::factory()->create();
        Auth::guard('web')->setUser($user);
        $handler = new DatabaseSessionHandler(DB::connection(), 'sessions', 120, app());
        $sessionId = Str::random(40);
        $payload = serialize(['user_id' => $user->id]);

        $this->assertTrue($handler->write($sessionId, $payload));
        $this->assertSame($user->id, DB::table('sessions')->where('id', $sessionId)->value('user_id'));
        $this->assertSame($payload, $handler->read($sessionId));
        $this->assertSame(40, strlen($sessionId));
        $this->assertFalse(Str::isUuid($sessionId));
    }

    public function test_implicit_route_binding_resolves_uuid_and_rejects_invalid_ids(): void
    {
        Route::get('/uuid-task-test/{task}', fn (Task $task): array => [
            'id' => $task->id,
            'project_id' => $task->project_id,
        ])->middleware(SubstituteBindings::class);

        $task = Task::factory()->create();

        $this->getJson('/uuid-task-test/'.$task->id)
            ->assertOk()
            ->assertJsonPath('id', $task->id)
            ->assertJsonPath('project_id', $task->project_id);
        $this->getJson('/uuid-task-test/123')->assertNotFound();
        $this->getJson('/uuid-task-test/'.Str::uuid7())->assertNotFound();
    }

    private function assertUuidIdentity(Model $model): void
    {
        $id = $model->getKey();

        $this->assertIsString($id);
        $this->assertTrue(Str::isUuid($id));
        $this->assertSame('7', $id[14]);
        $this->assertSame($id, $model->toArray()['id']);
        $this->assertTrue($model->newQuery()->findOrFail($id)->is($model));
    }
}
