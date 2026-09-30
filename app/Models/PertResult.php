<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pert_analysis_id', 'task_id', 'optimistic_time', 'most_likely_time', 'pessimistic_time', 'expected_time', 'variance', 'standard_deviation'])]
class PertResult extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'optimistic_time' => 'decimal:8',
            'most_likely_time' => 'decimal:8',
            'pessimistic_time' => 'decimal:8',
            'expected_time' => 'decimal:8',
            'variance' => 'decimal:8',
            'standard_deviation' => 'decimal:8',
        ];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(PertAnalysis::class, 'pert_analysis_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
