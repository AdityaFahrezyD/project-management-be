<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\BaselineStatus;
use App\Enums\BudgetStatus;
use App\Enums\DependencyType;
use App\Enums\Priority;
use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Enums\ResourceType;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\ActivityLog;
use App\Models\Budget;
use App\Models\Project;
use App\Models\ProjectBaseline;
use App\Models\ProjectMember;
use App\Models\Resource;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_roles_are_independent_and_multiple_managers_are_supported(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->memberships()->forceCreate(['user_id' => $user->id, 'role' => WorkspaceRole::Owner]);
        $projects = Project::factory()->for($workspace)->count(2)->create();
        $manager = User::factory()->create();

        $projects[0]->memberships()->forceCreate(['user_id' => $user->id, 'role' => ProjectRole::Manager]);
        $projects[0]->memberships()->forceCreate(['user_id' => $manager->id, 'role' => ProjectRole::Manager]);
        $projects[1]->memberships()->forceCreate(['user_id' => $user->id, 'role' => ProjectRole::Finance]);

        $this->assertSame(WorkspaceRole::Owner, $workspace->memberships->sole()->role);
        $this->assertTrue($workspace->owner->is($user));
        $this->assertTrue($user->workspaces->sole()->is($workspace));
        $this->assertCount(2, $projects[0]->members);
        $this->assertSame([ProjectRole::Manager, ProjectRole::Manager], $projects[0]->memberships->pluck('role')->all());
        $this->assertSame(ProjectRole::Finance, $projects[1]->memberships->sole()->role);
        $this->assertCount(2, $user->projects);
        $this->assertFalse($user->is_super_admin);
    }

    public function test_task_hierarchy_dependencies_assignments_and_resources_have_both_relation_directions(): void
    {
        $project = Project::factory()->create();
        $parent = Task::factory()->for($project)->create();
        $predecessor = Task::factory()->for($project)->create(['parent_id' => $parent->id]);
        $successor = Task::factory()->for($project)->create();
        $resource = Resource::factory()->for($project)->create();
        $user = $project->creator;

        $dependency = $project->dependencies()->create([
            'predecessor_task_id' => $predecessor->id,
            'successor_task_id' => $successor->id,
        ]);
        $assignment = $predecessor->assignments()->create(['user_id' => $user->id]);
        $allocation = $predecessor->resourceAssignments()->create([
            'resource_id' => $resource->id,
            'quantity' => '2.5000',
            'duration' => '0.5000',
            'estimated_cost' => '625000.0000',
        ]);

        $this->assertTrue($parent->children->sole()->is($predecessor));
        $this->assertTrue($predecessor->parent->is($parent));
        $this->assertTrue($predecessor->successors->sole()->is($successor));
        $this->assertTrue($successor->predecessors->sole()->is($predecessor));
        $this->assertTrue($dependency->predecessor->is($predecessor));
        $this->assertTrue($dependency->successor->is($successor));
        $this->assertSame(DependencyType::FinishToStart, $dependency->dependency_type);
        $this->assertTrue($predecessor->assignees->sole()->is($user));
        $this->assertTrue($user->assignedTasks->sole()->is($predecessor));
        $this->assertTrue($assignment->task->is($predecessor));
        $this->assertTrue($assignment->user->is($user));
        $this->assertTrue($predecessor->resources->sole()->is($resource));
        $this->assertTrue($resource->tasks->sole()->is($predecessor));
        $this->assertTrue($allocation->resource->is($resource));
        $this->assertSame('2.5000', $allocation->fresh()->quantity);
        $this->assertSame('0.5000', $allocation->fresh()->duration);
        $this->assertSame('625000.0000', $allocation->fresh()->estimated_cost);
    }

    public function test_dates_decimals_enums_and_json_round_trip(): void
    {
        $project = Project::factory()->create([
            'start_date' => '2026-09-28 00:30:00.123456',
            'budget_amount' => '123456.7891',
        ]);
        $task = Task::factory()->for($project)->create([
            'start_date' => '2026-09-28 00:30:00.123456',
            'due_date' => '2026-09-28 12:30:00.123456',
            'duration_days' => '0.5000',
            'optimistic_time' => '0.2500',
            'most_likely_time' => '0.5000',
            'pessimistic_time' => '0.7500',
        ])->fresh();

        $this->assertSame('UTC', config('app.timezone'));
        $this->assertInstanceOf(CarbonImmutable::class, $task->start_date);
        $this->assertSame('2026-09-28 00:30:00.123456', $task->start_date->format('Y-m-d H:i:s.u'));
        $this->assertSame('0.5000', $task->duration_days);
        $this->assertSame('0.2500', $task->optimistic_time);
        $this->assertSame('123456.7891', $project->fresh()->budget_amount);
        $this->assertSame('IDR', $project->currency);
        $this->assertSame(ProjectStatus::Planning, $project->status);
        $this->assertSame(Priority::Medium, $task->priority);

        $project->workspace->update(['settings' => ['budget_timezone' => 'Asia/Jakarta']]);
        $this->assertSame(['budget_timezone' => 'Asia/Jakarta'], $project->workspace->fresh()->settings);
    }

    #[DataProvider('taskStatuses')]
    public function test_leaf_completion_is_derived_from_status(TaskStatus $status, int $completion): void
    {
        $task = Task::factory()->create(['status' => $status]);

        $this->assertSame($completion, $task->completion);
        $this->assertArrayNotHasKey('completion', $task->getAttributes());
        $this->assertArrayNotHasKey('progress', $task->getAttributes());
    }

    public static function taskStatuses(): array
    {
        return [
            'todo' => [TaskStatus::Todo, 0],
            'in progress' => [TaskStatus::InProgress, 50],
            'review' => [TaskStatus::Review, 75],
            'done' => [TaskStatus::Done, 100],
        ];
    }

    public function test_parent_completion_is_reserved_for_the_aggregate_workflow(): void
    {
        $parent = Task::factory()->create(['status' => TaskStatus::Done]);
        Task::factory()->for($parent->project)->create(['parent_id' => $parent->id]);

        $this->assertNull($parent->completion);
        $this->assertNull($parent->load('children')->completion);
    }

    public function test_privilege_approval_and_activation_fields_are_not_mass_assignable(): void
    {
        $user = new User(['name' => 'Member', 'is_super_admin' => true, 'is_active' => false]);
        $membership = new ProjectMember(['role' => ProjectRole::Manager]);
        $workspaceMembership = new WorkspaceMember(['role' => WorkspaceRole::Owner]);
        $workspace = new Workspace(['name' => 'Workspace', 'owner_id' => (string) Str::uuid7()]);
        $budget = new Budget([
            'name' => 'Draft',
            'status' => BudgetStatus::Approved,
            'approved_by' => (string) Str::uuid7(),
            'approved_at' => now(),
            'created_by' => (string) Str::uuid7(),
        ]);
        $baseline = new ProjectBaseline(['status' => BaselineStatus::Active]);
        $project = new Project(['active_baseline_id' => (string) Str::uuid7()]);
        $invitation = new WorkspaceInvitation(['accepted_at' => now(), 'role' => WorkspaceRole::Admin]);

        $this->assertSame('Member', $user->name);
        $this->assertFalse($user->is_super_admin);
        $this->assertTrue($user->is_active);
        $this->assertSame(ProjectRole::Member, $membership->role);
        $this->assertSame(WorkspaceRole::Member, $workspaceMembership->role);
        $this->assertNull($workspace->owner_id);
        $this->assertSame(BudgetStatus::Draft, $budget->status);
        $this->assertNull($budget->approved_by);
        $this->assertNull($budget->approved_at);
        $this->assertNull($budget->created_by);
        $this->assertSame(BaselineStatus::Draft, $baseline->status);
        $this->assertNull($project->active_baseline_id);
        $this->assertNull($invitation->accepted_at);
        $this->assertSame(WorkspaceRole::Member, $invitation->role);
    }

    public function test_collaboration_and_history_preserve_their_actor_and_timestamps(): void
    {
        $task = Task::factory()->create();
        $user = $task->creator;
        $comment = $task->comments()->forceCreate(['user_id' => $user->id, 'content' => 'Ready for review']);
        $attachment = $task->attachments()->forceCreate([
            'user_id' => $user->id,
            'file_name' => 'brief.pdf',
            'file_path' => 'tasks/brief.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 2048,
        ]);
        $history = $task->statusHistories()->forceCreate([
            'changed_by' => $user->id,
            'from_status' => null,
            'to_status' => TaskStatus::Todo,
        ]);
        $log = ActivityLog::forceCreate([
            'user_id' => $user->id,
            'workspace_id' => $task->project->workspace_id,
            'project_id' => $task->project_id,
            'action' => 'task.created',
            'entity_type' => Task::class,
            'entity_id' => $task->id,
            'old_values' => null,
            'new_values' => ['status' => 'todo'],
        ]);

        $this->assertTrue($comment->user->is($user));
        $this->assertTrue($comment->task->is($task));
        $this->assertTrue($user->comments->sole()->is($comment));
        $this->assertTrue($attachment->task->is($task));
        $this->assertTrue($user->attachments->sole()->is($attachment));
        $this->assertSame(2048, $attachment->fresh()->file_size);
        $this->assertSame(TaskStatus::Todo, $history->fresh()->to_status);
        $this->assertNull($history->from_status);
        $this->assertInstanceOf(CarbonImmutable::class, $history->fresh()->changed_at);
        $this->assertTrue($history->changedBy->is($user));
        $this->assertArrayNotHasKey('created_at', $history->getAttributes());
        $this->assertArrayNotHasKey('updated_at', $history->getAttributes());
        $this->assertNotNull($log->created_at);
        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
        $this->assertTrue($log->entity->is($task));
        $this->assertSame(['status' => 'todo'], $log->fresh()->new_values);
        $this->assertTrue($task->project->activityLogs->sole()->is($log));
        $this->assertTrue($task->project->workspace->activityLogs->sole()->is($log));
    }

    public function test_budget_periods_expenses_and_baseline_links_are_persisted(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create();
        $resource = Resource::factory()->for($project)->create();
        $budget = Budget::factory()->for($project)->approved()->create(['total_amount' => '1000.0000']);
        $item = $budget->items()->create([
            'task_id' => $task->id,
            'resource_id' => $resource->id,
            'description' => 'Delivery',
            'planned_amount' => '1000.0000',
        ]);
        $period = $budget->periods()->create([
            'period_start' => '2026-09-28',
            'period_end' => '2026-09-28',
            'planned_amount' => '1000.0000',
        ]);
        $expense = $project->expenses()->forceCreate([
            'created_by' => $project->created_by,
            'task_id' => $task->id,
            'resource_id' => $resource->id,
            'description' => 'Actual delivery',
            'amount' => '250.1250',
            'expense_date' => '2026-09-28',
        ]);
        $baseline = ProjectBaseline::factory()->for($budget)->create(['status' => BaselineStatus::Active]);
        $project->forceFill(['active_baseline_id' => $baseline->id])->save();

        $this->assertTrue($budget->approver->is($project->creator));
        $this->assertSame(BudgetStatus::Approved, $budget->fresh()->status);
        $this->assertInstanceOf(CarbonImmutable::class, $budget->approved_at);
        $this->assertTrue($item->task->is($task));
        $this->assertTrue($item->resource->is($resource));
        $this->assertNull($item->unit_cost);
        $this->assertSame('1000.0000', $period->fresh()->planned_amount);
        $this->assertSame('2026-09-28', $period->fresh()->period_start->toDateString());
        $this->assertSame('250.1250', $expense->fresh()->amount);
        $this->assertSame('2026-09-28', $expense->fresh()->expense_date->toDateString());
        $this->assertTrue($task->expenses->sole()->is($expense));
        $this->assertTrue($resource->expenses->sole()->is($expense));
        $this->assertTrue($project->fresh()->activeBaseline->is($baseline));
        $this->assertTrue($baseline->budget->is($budget));
        $this->assertTrue($budget->baselines->sole()->is($baseline));
        $this->assertNotNull($baseline->created_at);
        $this->assertArrayNotHasKey('updated_at', $baseline->getAttributes());
    }

    public function test_draft_budget_items_and_project_expenses_can_be_unassigned(): void
    {
        $budget = Budget::factory()->create();
        $item = $budget->items()->create(['description' => 'Unassigned draft', 'planned_amount' => '100.0000']);
        $expense = $budget->project->expenses()->forceCreate([
            'created_by' => $budget->created_by,
            'description' => 'Project expense',
            'amount' => '50.0000',
            'expense_date' => '2026-09-28',
        ]);

        $this->assertNull($item->task);
        $this->assertNull($item->resource);
        $this->assertNull($expense->task);
        $this->assertNull($expense->resource);
        $this->assertSame('IDR', $expense->currency);
    }

    public function test_baseline_and_analysis_snapshots_survive_source_changes(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create(['duration_days' => '2.0000']);
        $resource = Resource::factory()->for($project)->create(['unit_cost' => '100.0000']);
        $budget = Budget::factory()->for($project)->approved()->create(['total_amount' => '200.0000']);
        $snapshot = [
            'tasks' => [['id' => $task->id, 'name' => $task->name, 'duration_days' => '2.0000']],
            'dependencies' => [],
            'budget' => ['id' => $budget->id, 'total_amount' => '200.0000', 'currency' => 'IDR'],
            'resources' => [['id' => $resource->id, 'unit_cost' => '100.0000']],
            'budget_periods' => [['period_start' => '2026-09-28', 'period_end' => '2026-09-28', 'planned_amount' => '200.0000']],
        ];
        $baseline = ProjectBaseline::factory()->for($budget)->create(['snapshot_data' => $snapshot]);
        $analysis = $project->pertAnalyses()->forceCreate([
            'version' => 1,
            'created_by' => $project->created_by,
            'input_snapshot' => $snapshot,
            'engine_version' => 'pert-1.0',
        ]);

        $task->update(['name' => 'Changed', 'duration_days' => '5.0000']);
        $resource->update(['unit_cost' => '999.0000']);
        $budget->update(['total_amount' => '999.0000']);

        $this->assertEquals($snapshot, $baseline->fresh()->snapshot_data);
        $this->assertEquals($snapshot, $analysis->fresh()->input_snapshot);
        $this->assertSame('pert-1.0', $analysis->engine_version);
        $this->assertSame(ResourceType::Labor, $resource->type);
    }

    public function test_pert_and_cpm_versions_keep_separate_results_for_the_same_task(): void
    {
        $task = Task::factory()->create();
        $project = $task->project;

        foreach ([1, 2] as $version) {
            $pert = $project->pertAnalyses()->forceCreate([
                'version' => $version,
                'created_by' => $project->created_by,
                'input_snapshot' => ['tasks' => [['id' => $task->id]]],
            ]);
            $pert->results()->create([
                'task_id' => $task->id,
                'optimistic_time' => '1.00000000',
                'most_likely_time' => '2.00000000',
                'pessimistic_time' => '3.00000000',
                'expected_time' => '2.00000000',
                'variance' => '0.11111111',
                'standard_deviation' => '0.33333333',
            ]);
            $cpm = $project->cpmAnalyses()->forceCreate([
                'version' => $version,
                'created_by' => $project->created_by,
                'input_snapshot' => ['tasks' => [['id' => $task->id]], 'dependencies' => []],
                'project_duration' => '2.00000000',
                'critical_paths' => [[$task->id]],
            ]);
            $cpm->results()->create([
                'task_id' => $task->id,
                'early_start' => '0.00000000',
                'early_finish' => '2.00000000',
                'late_start' => '0.00000000',
                'late_finish' => '2.00000000',
                'slack' => '0.00000000',
                'is_critical' => true,
            ]);
        }

        $this->assertCount(2, $task->pertResults);
        $this->assertCount(2, $task->cpmResults);
        $this->assertSame('0.11111111', $task->pertResults->first()->variance);
        $this->assertTrue($task->pertResults->first()->analysis->project->is($project));
        $this->assertSame([[$task->id]], $task->cpmResults->first()->analysis->critical_paths);
        $this->assertTrue($task->cpmResults->first()->is_critical);
        $this->assertSame('1.0000', $task->fresh()->duration_days);
        $this->assertNull($task->fresh()->optimistic_time);
    }

    public function test_evm_references_its_baseline_and_accepts_undefined_ratios(): void
    {
        $baseline = ProjectBaseline::factory()->create();
        $analysis = $baseline->evmAnalyses()->forceCreate([
            'project_id' => $baseline->project_id,
            'version' => 1,
            'analysis_date' => '2026-09-28',
            'created_by' => $baseline->created_by,
            'input_snapshot' => ['planned_value' => '0', 'earned_value' => '0', 'actual_cost' => '10'],
            'status' => AnalysisStatus::Completed,
            'analyzed_at' => now(),
        ]);
        $result = $analysis->result()->create([
            'planned_value' => '0.00000000',
            'earned_value' => '0.00000000',
            'actual_cost' => '10.00000000',
            'cost_variance' => '-10.00000000',
            'schedule_variance' => '0.00000000',
            'cost_performance_index' => '0.00000000',
            'schedule_performance_index' => null,
        ])->fresh();

        $this->assertTrue($analysis->baseline->is($baseline));
        $this->assertTrue($analysis->creator->is($baseline->creator));
        $this->assertTrue($result->analysis->is($analysis));
        $this->assertSame('2026-09-28', $analysis->fresh()->analysis_date->toDateString());
        $this->assertSame('-10.00000000', $result->cost_variance);
        $this->assertNull($result->schedule_performance_index);
        $this->assertNull($result->estimate_at_completion);
        $this->assertNull($result->estimate_to_complete);
        $this->assertNull($result->variance_at_completion);
        $this->assertNull($result->to_complete_performance_index);
    }

    public function test_failed_analysis_retains_safe_error_metadata_without_results(): void
    {
        $project = Project::factory()->create();
        $analysis = $project->cpmAnalyses()->forceCreate([
            'version' => 1,
            'created_by' => $project->created_by,
            'input_snapshot' => ['tasks' => [], 'dependencies' => []],
            'status' => AnalysisStatus::Failed,
            'error_metadata' => ['code' => 'DEPENDENCY_CYCLE', 'message' => 'Dependencies contain a cycle.'],
        ])->fresh();

        $this->assertSame(AnalysisStatus::Failed, $analysis->status);
        $this->assertSame('DEPENDENCY_CYCLE', $analysis->error_metadata['code']);
        $this->assertNull($analysis->project_duration);
        $this->assertCount(0, $analysis->results);
    }

    public function test_archiving_preserves_records_and_relations(): void
    {
        $task = Task::factory()->create();
        $task->forceFill(['archived_at' => now()])->save();
        $task->project->forceFill(['archived_at' => now()])->save();

        $this->assertNotNull($task->fresh()->archived_at);
        $this->assertTrue($task->project->fresh()->tasks->sole()->is($task));
        $this->assertFalse(method_exists($task, 'trashed'));
    }

    public function test_invitation_tokens_and_account_secrets_are_not_serialized(): void
    {
        $workspace = Workspace::factory()->create();
        $invitation = $workspace->invitations()->forceCreate([
            'email' => 'invitee@example.test',
            'role' => WorkspaceRole::Member,
            'invited_by' => $workspace->owner_id,
            'token_hash' => hash('sha256', 'test-invitation'),
            'expires_at' => now()->addDays(7),
        ]);

        $this->assertTrue($invitation->inviter->is($workspace->owner));
        $this->assertTrue($workspace->owner->sentWorkspaceInvitations->sole()->is($invitation));
        $this->assertInstanceOf(CarbonImmutable::class, $invitation->fresh()->expires_at);
        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());
        $this->assertArrayNotHasKey('password', $workspace->owner->toArray());
        $this->assertArrayNotHasKey('remember_token', $workspace->owner->toArray());
        $this->assertInstanceOf(MustVerifyEmail::class, $workspace->owner);

        $token = $workspace->owner->createToken('model-test');
        $this->assertTrue($token->accessToken->tokenable->is($workspace->owner));
        $this->assertNotSame($token->plainTextToken, $token->accessToken->token);
    }

    public function test_mysql_preserves_full_decimal_precision(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Full fixed-point precision is verified on MySQL.');
        }

        $project = Project::factory()->create(['budget_amount' => '9999999999999999.9999']);
        $baseline = ProjectBaseline::factory()->for(Budget::factory()->for($project)->approved())->create();
        $analysis = $baseline->evmAnalyses()->forceCreate([
            'project_id' => $project->id,
            'created_by' => $project->created_by,
            'version' => 1,
            'analysis_date' => '2026-09-28',
            'input_snapshot' => [],
        ]);
        $result = $analysis->result()->create([
            'planned_value' => '9999999999999999.99999999',
            'earned_value' => '0.00000000',
            'actual_cost' => '9999999999999999.99999999',
            'cost_variance' => '-9999999999999999.99999999',
            'schedule_variance' => '-9999999999999999.99999999',
        ]);

        $this->assertSame('9999999999999999.9999', $project->fresh()->budget_amount);
        $this->assertSame('9999999999999999.99999999', $result->fresh()->planned_value);
        $this->assertSame('-9999999999999999.99999999', $result->fresh()->cost_variance);
    }
}
