<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Budget;
use App\Models\CpmAnalysis;
use App\Models\EvmAnalysis;
use App\Models\PertAnalysis;
use App\Models\Project;
use App\Models\ProjectBaseline;
use App\Models\Resource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchemaConstraintTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('uniqueRecords')]
    public function test_database_rejects_duplicate_domain_keys(string $recordType): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create();
        $successor = Task::factory()->for($project)->create();
        $resource = Resource::factory()->for($project)->create();
        $budget = Budget::factory()->for($project)->approved()->create();
        $baseline = ProjectBaseline::factory()->for($budget)->create();
        $analysisAttributes = [
            'project_id' => $project->id,
            'created_by' => $project->created_by,
            'version' => 1,
            'input_snapshot' => [],
        ];

        $record = match ($recordType) {
            'workspace slug' => $project->workspace,
            'project slug' => $project,
            'workspace membership' => $project->workspace->memberships()->forceCreate(['user_id' => $project->created_by]),
            'project membership' => $project->memberships()->forceCreate(['user_id' => $project->created_by, 'role' => ProjectRole::Manager]),
            'invitation token' => $project->workspace->invitations()->forceCreate([
                'email' => 'invitee@example.test',
                'invited_by' => $project->created_by,
                'token_hash' => hash('sha256', 'invitation'),
                'expires_at' => now()->addDay(),
            ]),
            'task assignment' => $task->assignments()->create(['user_id' => $project->created_by]),
            'dependency' => $project->dependencies()->create([
                'predecessor_task_id' => $task->id,
                'successor_task_id' => $successor->id,
            ]),
            'task resource' => $task->resourceAssignments()->create([
                'resource_id' => $resource->id, 'quantity' => '1.0000', 'estimated_cost' => '100.0000',
            ]),
            'budget version' => $budget,
            'baseline version' => $baseline,
            'budget period' => $budget->periods()->create([
                'period_start' => '2026-09-28', 'period_end' => '2026-09-28', 'planned_amount' => '100.0000',
            ]),
            'pert version' => PertAnalysis::forceCreate($analysisAttributes),
            'cpm version' => CpmAnalysis::forceCreate($analysisAttributes),
            'evm version' => EvmAnalysis::forceCreate([
                ...$analysisAttributes, 'project_baseline_id' => $baseline->id, 'analysis_date' => '2026-09-28',
            ]),
            'pert task result' => PertAnalysis::forceCreate($analysisAttributes)->results()->create([
                'task_id' => $task->id,
                'optimistic_time' => '1', 'most_likely_time' => '1', 'pessimistic_time' => '1',
                'expected_time' => '1', 'variance' => '0', 'standard_deviation' => '0',
            ]),
            'cpm task result' => CpmAnalysis::forceCreate($analysisAttributes)->results()->create([
                'task_id' => $task->id,
                'early_start' => '0', 'early_finish' => '1', 'late_start' => '0',
                'late_finish' => '1', 'slack' => '0', 'is_critical' => true,
            ]),
            'evm result' => EvmAnalysis::forceCreate([
                ...$analysisAttributes, 'project_baseline_id' => $baseline->id, 'analysis_date' => '2026-09-28',
            ])->result()->create([
                'planned_value' => '0', 'earned_value' => '0', 'actual_cost' => '0',
                'cost_variance' => '0', 'schedule_variance' => '0',
            ]),
        };

        $this->expectException(QueryException::class);
        $record->replicate()->save();
    }

    public static function uniqueRecords(): array
    {
        return array_combine(
            $keys = [
                'workspace slug', 'project slug', 'workspace membership', 'project membership',
                'invitation token', 'task assignment', 'dependency', 'task resource',
                'budget version', 'baseline version', 'budget period', 'pert version',
                'cpm version', 'evm version', 'pert task result', 'cpm task result', 'evm result',
            ],
            array_map(fn (string $key): array => [$key], $keys),
        );
    }

    #[DataProvider('referencedRecords')]
    public function test_referenced_records_cannot_be_deleted(string $recordType): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create();
        $resource = Resource::factory()->for($project)->create();
        $budget = Budget::factory()->for($project)->approved()->create();
        $baseline = ProjectBaseline::factory()->for($budget)->create();

        $budget->items()->create([
            'task_id' => $task->id,
            'resource_id' => $resource->id,
            'description' => 'Historical allocation',
            'planned_amount' => '100.0000',
        ]);

        if ($recordType === 'active baseline') {
            $project->forceFill(['active_baseline_id' => $baseline->id])->save();
        }

        $analysis = $baseline->evmAnalyses()->forceCreate([
            'project_id' => $project->id,
            'created_by' => $project->created_by,
            'version' => 1,
            'analysis_date' => '2026-09-28',
            'input_snapshot' => [],
        ]);
        $analysis->result()->create([
            'planned_value' => '0', 'earned_value' => '0', 'actual_cost' => '0',
            'cost_variance' => '0', 'schedule_variance' => '0',
        ]);

        $record = match ($recordType) {
            'user' => $project->creator,
            'workspace' => $project->workspace,
            'project' => $project,
            'task' => $task,
            'resource' => $resource,
            'budget' => $budget,
            'baseline', 'active baseline' => $baseline,
            'analysis' => $analysis,
        };

        $this->expectException(QueryException::class);
        $record->delete();
    }

    public static function referencedRecords(): array
    {
        return array_combine(
            $keys = ['user', 'workspace', 'project', 'task', 'resource', 'budget', 'baseline', 'active baseline', 'analysis'],
            array_map(fn (string $key): array => [$key], $keys),
        );
    }

    public function test_active_baseline_cannot_be_deleted_even_without_an_analysis(): void
    {
        $baseline = ProjectBaseline::factory()->create();
        $baseline->project->forceFill(['active_baseline_id' => $baseline->id])->save();

        $this->expectException(QueryException::class);
        $baseline->delete();
    }

    public function test_a_task_with_status_history_cannot_be_deleted(): void
    {
        $task = Task::factory()->create();
        $task->statusHistories()->forceCreate([
            'from_status' => null,
            'to_status' => 'todo',
            'changed_by' => $task->created_by,
        ]);

        $this->expectException(QueryException::class);
        $task->delete();
    }

    public function test_a_record_without_dependents_can_be_deleted_permanently(): void
    {
        $task = Task::factory()->create();
        $project = $task->project;
        $workspace = $project->workspace;
        $user = $project->creator;

        $task->delete();
        $project->delete();
        $workspace->delete();
        $user->delete();

        foreach ([$task, $project, $workspace, $user] as $record) {
            $this->assertModelMissing($record);
        }
    }

    public function test_database_rejects_an_orphan_task(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        Task::factory()->create(['project_id' => (string) Str::uuid7(), 'created_by' => $user->id]);
    }

    public function test_project_slugs_and_versions_are_scoped_to_their_parent(): void
    {
        $first = Project::factory()->create(['slug' => 'shared']);
        $second = Project::factory()->create(['slug' => 'shared']);
        $firstBudget = Budget::factory()->for($first)->create(['version' => 1]);
        $secondBudget = Budget::factory()->for($second)->create(['version' => 1]);

        $this->assertNotSame($first->workspace_id, $second->workspace_id);
        $this->assertModelExists($first);
        $this->assertModelExists($second);
        $this->assertModelExists($firstBudget);
        $this->assertModelExists($secondBudget);
    }
}
