<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Project;
use App\Models\ProjectBaseline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectBaseline>
 */
class ProjectBaselineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_id' => Budget::factory()->approved(),
            'project_id' => fn (array $attributes) => Budget::findOrFail($attributes['budget_id'])->project_id,
            'version' => 1,
            'name' => 'Initial baseline',
            'snapshot_data' => [
                'tasks' => [],
                'dependencies' => [],
                'budget' => ['total_amount' => '0.0000'],
                'budget_periods' => [],
            ],
            'created_by' => fn (array $attributes) => Project::findOrFail($attributes['project_id'])->created_by,
        ];
    }
}
