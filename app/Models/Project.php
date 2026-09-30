<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'name', 'slug', 'description', 'status', 'priority', 'start_date', 'target_end_date', 'actual_end_date', 'budget_amount', 'currency'])]
class Project extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'planning',
        'priority' => 'medium',
        'budget_amount' => '0.0000',
        'currency' => 'IDR',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'priority' => Priority::class,
            'start_date' => 'immutable_datetime',
            'target_end_date' => 'immutable_datetime',
            'actual_end_date' => 'immutable_datetime',
            'budget_amount' => 'decimal:4',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->using(ProjectMember::class)
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function baselines(): HasMany
    {
        return $this->hasMany(ProjectBaseline::class);
    }

    public function activeBaseline(): BelongsTo
    {
        return $this->belongsTo(ProjectBaseline::class, 'active_baseline_id');
    }

    public function pertAnalyses(): HasMany
    {
        return $this->hasMany(PertAnalysis::class);
    }

    public function cpmAnalyses(): HasMany
    {
        return $this->hasMany(CpmAnalysis::class);
    }

    public function evmAnalyses(): HasMany
    {
        return $this->hasMany(EvmAnalysis::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
