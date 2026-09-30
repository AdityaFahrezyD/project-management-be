<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['evm_analysis_id', 'planned_value', 'earned_value', 'actual_cost', 'cost_variance', 'schedule_variance', 'cost_performance_index', 'schedule_performance_index', 'estimate_at_completion', 'estimate_to_complete', 'variance_at_completion', 'to_complete_performance_index'])]
class EvmResult extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planned_value' => 'decimal:8',
            'earned_value' => 'decimal:8',
            'actual_cost' => 'decimal:8',
            'cost_variance' => 'decimal:8',
            'schedule_variance' => 'decimal:8',
            'cost_performance_index' => 'decimal:8',
            'schedule_performance_index' => 'decimal:8',
            'estimate_at_completion' => 'decimal:8',
            'estimate_to_complete' => 'decimal:8',
            'variance_at_completion' => 'decimal:8',
            'to_complete_performance_index' => 'decimal:8',
        ];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(EvmAnalysis::class, 'evm_analysis_id');
    }
}
