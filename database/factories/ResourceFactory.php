<?php

namespace Database\Factories;

use App\Enums\ResourceType;
use App\Models\Project;
use App\Models\Resource as ProjectResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectResource>
 */
class ResourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->word(),
            'type' => ResourceType::Labor,
            'unit' => 'person/day',
            'unit_cost' => '500000.0000',
        ];
    }
}
