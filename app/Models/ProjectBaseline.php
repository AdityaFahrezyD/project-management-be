<?php

namespace App\Models;

use App\Enums\BaselineStatus;
use Database\Factories\ProjectBaselineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'budget_id', 'version', 'name', 'snapshot_data'])]
class ProjectBaseline extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @use HasFactory<ProjectBaselineFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => BaselineStatus::class,
            'snapshot_data' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function evmAnalyses(): HasMany
    {
        return $this->hasMany(EvmAnalysis::class);
    }
}
