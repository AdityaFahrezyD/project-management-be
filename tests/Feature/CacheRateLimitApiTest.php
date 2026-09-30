<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CacheRateLimitApiTest extends ApiTestCase
{
    public function test_summary_cache_avoids_repeated_aggregate_queries_and_invalidates_after_mutation(): void
    {
        $task = $this->task();
        $url = $this->projectUrl('/summary');
        DB::enableQueryLog();
        $this->getJson($url)->assertOk()->assertJsonPath('data.tasks.todo', 1);
        $cold = collect(DB::getQueryLog())->filter(fn ($query) => str_contains(strtolower($query['query']), 'count(*) as total'))->count();
        DB::flushQueryLog();
        $this->getJson($url)->assertOk();
        $warm = collect(DB::getQueryLog())->filter(fn ($query) => str_contains(strtolower($query['query']), 'count(*) as total'))->count();
        DB::disableQueryLog();
        $this->assertGreaterThan(0, $cold);
        $this->assertSame(0, $warm);
        $this->patchJson($this->taskUrl($task, '/status'), ['status' => 'done'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.tasks.done', 1)->assertJsonMissingPath('data.tasks.todo');
    }

    public function test_expiry_and_disabled_cache_read_fresh_data(): void
    {
        config(['traffic.summary_ttl' => 10]);
        $task = $this->task();
        $url = $this->projectUrl('/summary');
        $this->getJson($url)->assertJsonPath('data.tasks.todo', 1);
        Task::whereKey($task->id)->update(['status' => 'done']);
        $this->getJson($url)->assertJsonPath('data.tasks.todo', 1);
        $this->travel(11)->seconds();
        $this->getJson($url)->assertJsonPath('data.tasks.done', 1);
        $this->travelBack();
        config(['traffic.summary_enabled' => false]);
        Task::whereKey($task->id)->update(['status' => 'review']);
        $this->getJson($url)->assertJsonPath('data.tasks.review', 1);
        config(['traffic.summary_enabled' => true, 'traffic.summary_ttl' => 0]);
        Task::whereKey($task->id)->update(['status' => 'in_progress']);
        $this->getJson($url)->assertJsonPath('data.tasks.in_progress', 1);
    }

    public function test_rollback_preserves_cache_version_and_removes_audit_and_task(): void
    {
        $key = 'summary:project:'.$this->project->id.':version';
        $before = Cache::get($key);
        $auditCount = ActivityLog::count();
        try {
            DB::transaction(function (): void {
                app(TaskService::class)->create($this->manager, $this->project, ['name' => 'Rolled back']);
                throw new RuntimeException('Rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback', $exception->getMessage());
        }
        $this->assertSame($before, Cache::get($key));
        $this->assertSame($auditCount, ActivityLog::count());
        $this->assertDatabaseMissing('tasks', ['name' => 'Rolled back']);
        $this->task();
        $this->assertNotSame($before, Cache::get($key));
    }

    public function test_membership_revocation_is_checked_before_serving_cached_summary(): void
    {
        $member = $this->member();
        $this->actingAs($member, 'web')->getJson($this->projectUrl('/summary'))->assertOk();
        $membership = $this->project->memberships()->where('user_id', $member->id)->first();
        $this->actingAs($this->manager, 'web')->deleteJson($this->projectUrl('/members/'.$membership->id))->assertNoContent();
        $this->actingAs($member, 'web')->getJson($this->projectUrl('/summary'))->assertNotFound();
        $this->getJson('/api/v1/workspaces/'.$this->workspace->id.'/summary')->assertOk()->assertJsonPath('data.projects', []);
    }

    public function test_cached_reads_consume_quota_and_writes_have_separate_limits(): void
    {
        config(['traffic.read' => 2, 'traffic.write' => 2]);
        $this->getJson($this->projectUrl('/summary'))->assertOk();
        $this->getJson($this->projectUrl('/summary'))->assertOk();
        $this->getJson($this->projectUrl('/summary'))->assertTooManyRequests()->assertHeader('Retry-After');
        $this->postJson($this->projectUrl('/tasks'), ['name' => 'One'])->assertSuccessful();
        $this->postJson($this->projectUrl('/tasks'), ['name' => 'Two'])->assertSuccessful();
        $this->postJson($this->projectUrl('/tasks'), ['name' => 'Three'])->assertTooManyRequests();
        $this->getJson($this->projectUrl('/summary'))->assertTooManyRequests();
        $this->actingAs($this->member(), 'web')->getJson($this->projectUrl('/summary'))->assertOk();
    }

    public function test_database_cache_store_can_serialize_and_invalidate_summary_arrays(): void
    {
        config(['cache.default' => 'database']);
        $task = $this->task();
        $this->getJson($this->projectUrl('/summary'))->assertOk()->assertJsonPath('data.tasks.todo', 1);
        $this->assertGreaterThan(0, DB::table('cache')->count());
        $this->patchJson($this->taskUrl($task, '/status'), ['status' => 'done'])->assertOk();
        $this->getJson($this->projectUrl('/summary'))->assertOk()->assertJsonPath('data.tasks.done', 1);
    }

    public function test_assignment_and_dependency_changes_rotate_summary_versions(): void
    {
        $a = $this->task();
        $b = $this->task();
        $projectKey = 'summary:project:'.$this->project->id.':version';
        $workspaceKey = 'summary:workspace:'.$this->workspace->id.':version';
        $beforeProject = Cache::get($projectKey);
        $beforeWorkspace = Cache::get($workspaceKey);
        $this->putJson($this->taskUrl($a, '/assignees'), ['user_ids' => [$this->manager->id]])->assertOk();
        $this->assertNotSame($beforeProject, Cache::get($projectKey));
        $this->assertNotSame($beforeWorkspace, Cache::get($workspaceKey));
        $beforeProject = Cache::get($projectKey);
        $this->postJson($this->projectUrl('/dependencies'), ['predecessor_task_id' => $a->id, 'successor_task_id' => $b->id])->assertCreated();
        $this->assertNotSame($beforeProject, Cache::get($projectKey));
    }
}
