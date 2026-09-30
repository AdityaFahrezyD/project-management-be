<?php

namespace App\Models;

use App\Enums\DependencyType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['project_id', 'predecessor_task_id', 'successor_task_id', 'dependency_type'])]
class TaskDependency extends Pivot
{
    use HasUuids;

    protected $table = 'task_dependencies';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var array<string, mixed> */
    protected $attributes = [
        'dependency_type' => 'FS',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dependency_type' => DependencyType::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'predecessor_task_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'successor_task_id');
    }
}
