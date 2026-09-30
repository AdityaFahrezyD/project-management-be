<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cpm_analysis_id', 'task_id', 'early_start', 'early_finish', 'late_start', 'late_finish', 'slack', 'is_critical'])]
class CpmResult extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'early_start' => 'decimal:8',
            'early_finish' => 'decimal:8',
            'late_start' => 'decimal:8',
            'late_finish' => 'decimal:8',
            'slack' => 'decimal:8',
            'is_critical' => 'boolean',
        ];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(CpmAnalysis::class, 'cpm_analysis_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
