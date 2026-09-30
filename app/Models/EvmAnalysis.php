<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['project_id', 'version', 'input_snapshot', 'engine_version', 'project_baseline_id', 'analysis_date'])]
class EvmAnalysis extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'running',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => AnalysisStatus::class,
            'analyzed_at' => 'immutable_datetime',
            'input_snapshot' => 'array',
            'error_metadata' => 'array',
            'analysis_date' => 'immutable_date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(ProjectBaseline::class, 'project_baseline_id');
    }

    public function result(): HasOne
    {
        return $this->hasOne(EvmResult::class);
    }
}
