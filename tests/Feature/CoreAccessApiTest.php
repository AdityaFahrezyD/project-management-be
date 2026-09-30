<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

class CoreAccessApiTest extends ApiTestCase
{
    public function test_workspace_and_project_creation_guard_privilege_and_use_uuid_resources(): void
    {
        $this->postJson('/api/v1/workspaces', ['name' => 'Second', 'slug' => 'second', 'owner_id' => $this->manager->id])
            ->assertUnprocessable()->assertJsonValidationErrors('owner_id');
        $response = $this->postJson('/api/v1/workspaces', ['name' => 'Second', 'slug' => 'second', 'settings' => ['timezone' => 'Asia/Jakarta']])
            ->assertCreated()->assertJsonPath('data.settings.timezone', 'Asia/Jakarta');
        $workspace = Workspace::findOrFail($response->json('data.id'));
        $this->assertSame('owner', $workspace->memberships()->first()->role->value);
        $this->postJson('/api/v1/workspaces/'.$workspace->id.'/projects', ['name' => 'New', 'slug' => 'new', 'currency' => 'IDR'])
            ->assertCreated()->assertJsonPath('data.budget_amount', '0.0000');
        $this->postJson('/api/v1/workspaces/'.$workspace->id.'/projects', ['name' => 'Bad', 'slug' => 'bad', 'currency' => 'ZZZ'])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
    }

    public function test_workspace_owner_has_no_implicit_project_access(): void
    {
        $private = Project::factory()->for($this->workspace)->create();
        $this->getJson('/api/v1/projects/'.$private->id)->assertNotFound();
        $this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/workspaces/'.$this->workspace->id.'/summary')->assertOk()->assertJsonPath('data.projects.planning', 1);
    }

    public function test_super_admin_can_access_projects_but_cannot_skip_workflow(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin, 'web')->getJson($this->projectUrl())->assertOk();
        $this->patchJson($this->projectUrl('/status'), ['status' => 'completed'])->assertConflict();
    }

    public function test_nested_routes_reject_cross_project_task_membership_and_comments(): void
    {
        $other = Project::factory()->create();
        $task = Task::factory()->for($other)->create();
        $this->getJson($this->taskUrl($task))->assertNotFound();
        $user = $this->member();
        $foreignMember = $other->memberships()->forceCreate(['user_id' => $user->id, 'role' => 'member']);
        $this->deleteJson($this->projectUrl('/members/'.$foreignMember->id))->assertNotFound();
    }

    public function test_member_only_changes_status_and_collaborates_on_assigned_tasks(): void
    {
        $task = $this->task();
        $other = $this->task();
        $member = $this->member();
        $this->putJson($this->taskUrl($task, '/assignees'), ['user_ids' => [$member->id]])->assertOk();
        $this->actingAs($member, 'web');
        $this->getJson($this->projectUrl('/tasks'))->assertOk();
        $this->patchJson($this->taskUrl($task), ['name' => 'Denied'])->assertForbidden();
        $this->patchJson($this->taskUrl($other, '/status'), ['status' => 'done'])->assertForbidden();
        $this->patchJson($this->taskUrl($task, '/status'), ['status' => 'done'])->assertOk()->assertJsonPath('data.completion', 100);
        $this->postJson($this->taskUrl($task, '/comments'), ['content' => 'Done'])->assertCreated();
        $this->postJson($this->taskUrl($other, '/comments'), ['content' => 'Denied'])->assertForbidden();
        $this->getJson($this->projectUrl('/activity'))->assertForbidden();
        $this->getJson($this->taskUrl($task, '/history'))->assertOk();
    }

    public function test_finance_and_viewer_are_read_only(): void
    {
        $task = $this->task();
        foreach (['finance', 'viewer'] as $role) {
            $this->actingAs($this->member($role), 'web');
            $this->getJson($this->taskUrl($task))->assertOk();
            $this->patchJson($this->taskUrl($task, '/status'), ['status' => 'done'])->assertForbidden();
            $this->postJson($this->taskUrl($task, '/comments'), ['content' => 'Denied'])->assertForbidden();
        }
    }

    public function test_last_manager_owner_and_assigned_member_are_protected(): void
    {
        $managerMembership = $this->project->memberships()->where('user_id', $this->manager->id)->first();
        $this->deleteJson($this->projectUrl('/members/'.$managerMembership->id))->assertConflict();
        $this->patchJson($this->projectUrl('/members/'.$managerMembership->id), ['role' => 'viewer'])->assertConflict();
        $ownerMembership = $this->workspace->memberships()->first();
        $this->deleteJson('/api/v1/workspaces/'.$this->workspace->id.'/members/'.$ownerMembership->id)->assertConflict();
        $member = $this->member();
        $task = $this->task();
        $task->assignees()->attach($member->id);
        $membership = $this->project->memberships()->where('user_id', $member->id)->first();
        $this->deleteJson($this->projectUrl('/members/'.$membership->id))->assertConflict();
        $this->putJson($this->taskUrl($task, '/assignees'), ['user_ids' => []])->assertOk();
        $this->deleteJson($this->projectUrl('/members/'.$membership->id))->assertNoContent();
        $this->actingAs($member, 'web')->getJson($this->projectUrl())->assertNotFound();
    }

    public function test_workspace_member_removal_requires_project_cleanup_and_owner_can_transfer(): void
    {
        $member = $this->member();
        $membership = $this->workspace->memberships()->where('user_id', $member->id)->first();
        $url = '/api/v1/workspaces/'.$this->workspace->id;
        $this->deleteJson($url.'/members/'.$membership->id)->assertConflict();
        $this->postJson($url.'/transfer-ownership', ['user_id' => $member->id])->assertOk()->assertJsonPath('data.owner_id', $member->id);
        $this->assertSame('admin', $this->workspace->memberships()->where('user_id', $this->manager->id)->first()->role->value);
        $this->patchJson($url.'/members/'.$membership->id, ['role' => 'member'])->assertConflict();
    }

    public function test_archive_blocks_descendant_mutations_and_history_prevents_hard_delete(): void
    {
        $task = $this->task();
        $this->deleteJson($this->taskUrl($task))->assertConflict();
        $this->deleteJson($this->projectUrl())->assertConflict();
        $this->postJson('/api/v1/workspaces/'.$this->workspace->id.'/archive')->assertOk();
        $this->patchJson($this->taskUrl($task), ['name' => 'Denied'])->assertConflict();
        $this->postJson($this->projectUrl('/restore'))->assertConflict();
        $this->postJson('/api/v1/workspaces/'.$this->workspace->id.'/restore')->assertOk();
        $this->patchJson($this->taskUrl($task), ['name' => 'Allowed'])->assertOk();
    }

    public function test_unverified_and_inactive_accounts_cannot_access_business_api(): void
    {
        $this->manager->forceFill(['email_verified_at' => null])->save();
        $this->getJson($this->projectUrl())->assertForbidden();
        $this->manager->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->getJson($this->projectUrl())->assertForbidden();
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $this->getJson('/api/v1/projects')->assertUnauthorized();
    }
}
