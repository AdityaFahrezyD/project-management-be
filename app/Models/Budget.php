<?php

namespace App\Models;

use App\Enums\BudgetStatus;
use App\Enums\BudgetType;
use App\Enums\EstimationMethod;
use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'version', 'name', 'type', 'total_amount', 'currency', 'estimation_method'])]
class Budget extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @use HasFactory<BudgetFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'initial',
        'total_amount' => '0.0000',
        'currency' => 'IDR',
        'estimation_method' => 'manual',
        'status' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'type' => BudgetType::class,
            'total_amount' => 'decimal:4',
            'estimation_method' => EstimationMethod::class,
            'status' => BudgetStatus::class,
            'approved_at' => 'immutable_datetime',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(BudgetPeriod::class);
    }

    public function baselines(): HasMany
    {
        return $this->hasMany(ProjectBaseline::class);
    }
}
