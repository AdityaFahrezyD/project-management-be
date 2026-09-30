<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'parent_id', 'name', 'description', 'status', 'priority', 'start_date', 'due_date', 'duration_days', 'optimistic_time', 'most_likely_time', 'pessimistic_time'])]
class Task extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'todo',
        'priority' => 'medium',
        'duration_days' => '1.0000',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => Priority::class,
            'start_date' => 'immutable_datetime',
            'due_date' => 'immutable_datetime',
            'duration_days' => 'decimal:4',
            'optimistic_time' => 'decimal:4',
            'most_likely_time' => 'decimal:4',
            'pessimistic_time' => 'decimal:4',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignee::class);
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignees')
            ->using(TaskAssignee::class)
            ->withPivot(['id'])
            ->withTimestamps();
    }

    public function outgoingDependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'predecessor_task_id');
    }

    public function incomingDependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'successor_task_id');
    }

    public function predecessors(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'successor_task_id', 'predecessor_task_id')
            ->using(TaskDependency::class)
            ->withPivot(['id', 'project_id', 'dependency_type'])
            ->withTimestamps();
    }

    public function successors(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'predecessor_task_id', 'successor_task_id')
            ->using(TaskDependency::class)
            ->withPivot(['id', 'project_id', 'dependency_type'])
            ->withTimestamps();
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function resourceAssignments(): HasMany
    {
        return $this->hasMany(TaskResource::class);
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'task_resources')
            ->using(TaskResource::class)
            ->withPivot(['id', 'quantity', 'duration', 'estimated_cost'])
            ->withTimestamps();
    }

    public function budgetItems(): HasMany
    {
        return $this->hasMany(BudgetItem::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function pertResults(): HasMany
    {
        return $this->hasMany(PertResult::class);
    }

    public function cpmResults(): HasMany
    {
        return $this->hasMany(CpmResult::class);
    }

    /**
     * Summary task completion is calculated by the project workflow, not its own status.
     *
     * @return Attribute<int|null, never>
     */
    protected function completion(): Attribute
    {
        return Attribute::get(function (): ?int {
            $hasChildren = $this->relationLoaded('children')
                ? $this->children->isNotEmpty()
                : $this->children()->exists();

            return $hasChildren ? null : $this->status->completion();
        });
    }
}
