<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskStatusHistory;

class TaskWorkflowApiTest extends ApiTestCase
{
    public function test_decimal_duration_derives_utc_due_date_and_rejects_manual_fields(): void
    {
        $response = $this->postJson($this->projectUrl('/tasks'), [
            'name' => 'Timed', 'start_date' => '2026-09-28T09:00:00+07:00', 'duration_days' => '0.0001',
        ])->assertSuccessful()->assertJsonPath('data.start_date', '2026-09-28T02:00:00.000000Z')
            ->assertJsonPath('data.due_date', '2026-09-28T02:00:08.640000Z')
            ->assertJsonPath('data.duration_days', '0.0001');
        $task = Task::findOrFail($response->json('data.id'));
        $this->patchJson($this->taskUrl($task), ['due_date' => '2026-10-01'])->assertUnprocessable();
        $this->patchJson($this->taskUrl($task), ['duration_days' => '0.00001'])->assertUnprocessable();
        $this->patchJson($this->taskUrl($task), ['optimistic_time' => 3, 'pessimistic_time' => 2])->assertUnprocessable();
        $this->patchJson($this->taskUrl($task), ['optimistic_time' => 1, 'most_likely_time' => 2, 'pessimistic_time' => 3])
            ->assertOk()->assertJsonPath('data.duration_days', '0.0001');
    }

    public function test_parent_status_and_schedule_follow_active_leaves(): void
    {
        $parent = $this->task();
        $child = $this->task(['parent_id' => $parent->id, 'start_date' => '2026-09-28T00:00:00Z', 'duration_days' => '0.5']);
        $second = $this->task(['parent_id' => $parent->id]);
        $this->getJson($this->taskUrl($parent))->assertOk()->assertJsonPath('data.is_summary', true)
            ->assertJsonPath('data.schedule_incomplete', true)->assertJsonPath('data.completion', null);
        $this->patchJson($this->taskUrl($child, '/status'), ['status' => 'done'])->assertOk();
        $this->getJson($this->taskUrl($parent))->assertJsonPath('data.status', 'in_progress');
        $this->patchJson($this->taskUrl($second, '/status'), ['status' => 'review'])->assertOk();
        $this->getJson($this->taskUrl($parent))->assertJsonPath('data.status', 'review');
        $this->patchJson($this->taskUrl($second, '/status'), ['status' => 'done'])->assertOk();
        $this->getJson($this->taskUrl($parent))->assertJsonPath('data.status', 'done');
        $this->patchJson($this->taskUrl($parent, '/status'), ['status' => 'todo'])->assertConflict();
        $this->patchJson($this->taskUrl($parent), ['duration_days' => 2])->assertConflict();
        $this->patchJson($this->taskUrl($parent), ['parent_id' => $child->id])->assertUnprocessable();
        $this->postJson($this->taskUrl($parent, '/archive'))->assertConflict();
    }

    public function test_dependency_status_gates_cycles_and_archiving(): void
    {
        $a = $this->task();
        $b = $this->task();
        $c = $this->task();
        $this->postJson($this->projectUrl('/dependencies'), ['predecessor_task_id' => $a->id, 'successor_task_id' => $b->id])->assertCreated();
        $this->postJson($this->projectUrl('/dependencies'), ['predecessor_task_id' => $b->id, 'successor_task_id' => $c->id])->assertCreated();
        $this->postJson($this->projectUrl('/dependencies'), ['predecessor_task_id' => $c->id, 'successor_task_id' => $a->id])->assertUnprocessable();
        $this->patchJson($this->taskUrl($b, '/status'), ['status' => 'in_progress'])->assertConflict();
        $this->patchJson($this->taskUrl($a, '/status'), ['status' => 'done'])->assertOk();
        $this->patchJson($this->taskUrl($b, '/status'), ['status' => 'done'])->assertOk();
        $this->patchJson($this->taskUrl($a, '/status'), ['status' => 'todo'])->assertConflict();
        $this->postJson($this->taskUrl($a, '/archive'))->assertConflict();
        $this->assertSame(2, TaskStatusHistory::whereIn('task_id', [$a->id, $b->id, $c->id])->count());
    }

    public function test_new_dependency_cannot_break_existing_status_or_cross_projects(): void
    {
        $todo = $this->task();
        $done = $this->task();
        $this->patchJson($this->taskUrl($done, '/status'), ['status' => 'done'])->assertOk();
        $this->postJson($this->projectUrl('/dependencies'), ['predecessor_task_id' => $todo->id, 'successor_task_id' => $done->id])->assertConflict();
        $foreign = Task::factory()->create();
        $this->postJson($this->projectUrl('/dependencies'), ['predecessor_task_id' => $todo->id, 'successor_task_id' => $foreign->id])->assertNotFound();
    }

    public function test_used_leaf_cannot_become_parent_and_wrong_assignee_is_rejected(): void
    {
        $task = $this->task();
        $member = $this->member('viewer');
        $this->putJson($this->taskUrl($task, '/assignees'), ['user_ids' => [$member->id]])->assertUnprocessable();
        $this->putJson($this->taskUrl($task, '/assignees'), ['user_ids' => [$this->manager->id]])->assertOk();
        $this->postJson($this->projectUrl('/tasks'), ['name' => 'Child', 'parent_id' => $task->id])->assertConflict();
    }

    public function test_moving_last_child_resets_former_parent_instead_of_counting_old_summary_as_work(): void
    {
        $parent = $this->task();
        $child = $this->task(['parent_id' => $parent->id, 'start_date' => '2026-09-28T00:00:00Z']);
        $this->patchJson($this->taskUrl($child, '/status'), ['status' => 'done'])->assertOk();
        $this->getJson($this->taskUrl($parent))->assertJsonPath('data.status', 'done');
        $this->patchJson($this->taskUrl($child), ['parent_id' => null])->assertOk();
        $this->getJson($this->taskUrl($parent))->assertJsonPath('data.is_summary', false)
            ->assertJsonPath('data.status', 'todo')->assertJsonPath('data.completion', 0)
            ->assertJsonPath('data.start_date', null);
        $this->patchJson($this->taskUrl($child), ['parent_id' => $parent->id])->assertOk();
        $this->getJson($this->taskUrl($parent))->assertJsonPath('data.is_summary', true)->assertJsonPath('data.status', 'done');
    }

    public function test_completion_freezes_project_and_on_hold_stops_status_changes(): void
    {
        $this->patchJson($this->projectUrl('/status'), ['status' => 'completed'])->assertConflict();
        $task = $this->task();
        $this->patchJson($this->projectUrl('/status'), ['status' => 'on_hold'])->assertOk();
        $this->patchJson($this->taskUrl($task), ['name' => 'Planning allowed'])->assertOk();
        $this->patchJson($this->taskUrl($task, '/status'), ['status' => 'done'])->assertConflict();
        $this->patchJson($this->projectUrl('/status'), ['status' => 'active'])->assertOk();
        $this->patchJson($this->taskUrl($task, '/status'), ['status' => 'done'])->assertOk();
        $this->patchJson($this->projectUrl('/status'), ['status' => 'completed'])->assertOk();
        $this->assertNotNull($this->project->fresh()->actual_end_date);
        $this->patchJson($this->taskUrl($task), ['name' => 'Blocked'])->assertConflict();
        $this->patchJson($this->projectUrl('/status'), ['status' => 'active'])->assertOk();
        $this->assertNull($this->project->fresh()->actual_end_date);
        $this->patchJson($this->taskUrl($task, '/status'), ['status' => 'todo'])->assertOk();
    }
}
