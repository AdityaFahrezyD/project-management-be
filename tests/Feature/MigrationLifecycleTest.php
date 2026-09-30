<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Project;
use App\Models\ProjectBaseline;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MigrationLifecycleTest extends TestCase
{
    use DatabaseMigrations;

    private const DOMAIN_TABLES = [
        'users',
        'password_reset_tokens',
        'sessions',
        'personal_access_tokens',
        'workspaces',
        'workspace_members',
        'workspace_invitations',
        'projects',
        'project_members',
        'tasks',
        'task_assignees',
        'task_dependencies',
        'task_status_histories',
        'comments',
        'attachments',
        'resources',
        'task_resources',
        'budgets',
        'budget_items',
        'budget_periods',
        'expenses',
        'project_baselines',
        'pert_analyses',
        'pert_results',
        'cpm_analyses',
        'cpm_results',
        'evm_analyses',
        'evm_results',
        'activity_logs',
    ];

    public function test_all_migrations_roll_back_with_linked_data_and_can_run_again(): void
    {
        $project = Project::factory()->create();
        $parent = Task::factory()->for($project)->create();
        Task::factory()->for($project)->create(['parent_id' => $parent->id]);
        $budget = Budget::factory()->for($project)->approved()->create();
        $baseline = ProjectBaseline::factory()->for($budget)->create();
        $project->forceFill(['active_baseline_id' => $baseline->id])->save();
        $project->creator->createToken('migration-test');

        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->artisan('migrate:rollback', ['--no-interaction' => true])->assertExitCode(0);

        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);

        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertSame(0, User::count());
        $this->assertTrue(Schema::hasColumns('users', ['avatar', 'is_super_admin', 'is_active']));
        $this->assertTrue(Schema::hasColumn('projects', 'active_baseline_id'));
    }

    public function test_user_creation_migration_includes_profile_fields_and_defaults(): void
    {
        $this->artisan('migrate:reset', ['--no-interaction' => true])->assertExitCode(0);
        $this->artisan('migrate', [
            '--path' => 'database/migrations/0001_01_01_000000_create_users_table.php',
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $this->assertTrue(Schema::hasColumns('users', ['avatar', 'is_super_admin', 'is_active']));

        $password = Hash::make('existing-password');
        $id = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Existing account',
            'email' => 'existing@example.test',
            'password' => $password,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::findOrFail($id);
        $this->assertSame('existing@example.test', $user->email);
        $this->assertSame($password, $user->password);
        $this->assertNull($user->avatar);
        $this->assertFalse($user->is_super_admin);
        $this->assertTrue($user->is_active);
    }
}
