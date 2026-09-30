<?php

namespace Database\Factories;

use App\Enums\BudgetStatus;
use App\Models\Budget;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'version' => 1,
            'name' => 'Initial budget',
            'created_by' => fn (array $attributes) => Project::findOrFail($attributes['project_id'])->created_by,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BudgetStatus::Approved,
            'approved_by' => $attributes['created_by'],
            'approved_at' => now(),
        ]);
    }
}
