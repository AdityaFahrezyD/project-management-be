<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class ApiIntegrityTest extends ApiTestCase
{
    public function test_partial_project_dates_are_validated_against_existing_values_and_normalized_to_utc(): void
    {
        $this->patchJson($this->projectUrl(), [
            'start_date' => '2026-09-28T09:00:00+07:00', 'target_end_date' => '2026-09-30T09:00:00+07:00',
        ])->assertOk()->assertJsonPath('data.start_date', '2026-09-28T02:00:00.000000Z');
        $this->patchJson($this->projectUrl(), ['target_end_date' => '2026-09-27T00:00:00Z'])->assertUnprocessable();
        $this->patchJson($this->projectUrl(), ['budget_amount' => '10000000000000000.0000'])->assertUnprocessable();
        $this->patchJson($this->projectUrl(), ['actual_end_date' => now()->toISOString()])->assertUnprocessable();
        $this->postJson($this->projectUrl('/tasks'), ['name' => 'Beyond range', 'start_date' => '9999-12-31T00:00:00Z', 'duration_days' => 2])->assertUnprocessable();
    }

    public function test_cross_workspace_project_members_and_nested_invites_cannot_be_used(): void
    {
        $outsider = User::factory()->create();
        $this->postJson($this->projectUrl('/members'), ['user_id' => $outsider->id, 'role' => 'member'])->assertNotFound();
        $other = Workspace::factory()->create();
        $invite = $other->invitations()->forceCreate([
            'email' => $outsider->email, 'role' => 'member', 'invited_by' => $outsider->id,
            'token_hash' => hash('sha256', 'token'), 'expires_at' => now()->addDay(),
        ]);
        $this->deleteJson('/api/v1/workspaces/'.$this->workspace->id.'/invitations/'.$invite->id)->assertNotFound();
    }

    public function test_workspace_audit_does_not_reveal_private_project_changes(): void
    {
        $this->task();
        $response = $this->getJson('/api/v1/workspaces/'.$this->workspace->id.'/activity')->assertOk();
        foreach ($response->json('data') as $entry) {
            $this->assertNull($entry['project_id']);
        }
        $this->getJson($this->projectUrl('/activity'))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($this->projectUrl('/tasks?per_page=101'))->assertUnprocessable();
    }

    public function test_rejected_status_change_leaves_no_audit_or_history(): void
    {
        $task = $this->task();
        $predecessor = $this->task();
        $this->postJson($this->projectUrl('/dependencies'), ['predecessor_task_id' => $predecessor->id, 'successor_task_id' => $task->id])->assertCreated();
        $count = ActivityLog::count();
        $this->patchJson($this->taskUrl($task, '/status'), ['status' => 'done'])->assertConflict();
        $this->assertSame($count, ActivityLog::count());
        $this->assertSame(0, $task->statusHistories()->count());
        $this->assertSame(TaskStatus::Todo, $task->fresh()->status);
    }

    public function test_only_pristine_records_can_be_deleted_and_archived_tasks_can_be_restored(): void
    {
        $pristine = Task::factory()->for($this->project)->create();
        $pristine->assignees()->attach($this->manager->id);
        $this->deleteJson($this->taskUrl($pristine))->assertNoContent();
        $this->assertDatabaseMissing('tasks', ['id' => $pristine->id]);
        $this->assertDatabaseMissing('task_assignees', ['task_id' => $pristine->id]);
        $task = $this->task();
        $this->postJson($this->taskUrl($task, '/archive'))->assertOk();
        $this->getJson($this->projectUrl('/tasks'))->assertJsonCount(0, 'data');
        $this->postJson($this->taskUrl($task, '/restore'))->assertOk();
        $this->patchJson($this->taskUrl($task, '/status'), ['status' => 'done'])->assertOk();
    }

    public function test_structured_invalid_email_returns_validation_error_instead_of_server_error(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => ['invalid'], 'password' => 'password'])->assertUnprocessable();
    }

    public function test_mysql_api_preserves_full_project_budget_decimal_precision(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Presisi DECIMAL penuh diverifikasi pada MySQL.');
        }
        $this->patchJson($this->projectUrl(), ['budget_amount' => '9999999999999999.9999'])
            ->assertOk()->assertJsonPath('data.budget_amount', '9999999999999999.9999');
    }
}
