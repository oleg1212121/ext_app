<?php

namespace Database\Factories;

use App\Models\WorkTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkTag>
 */
class WorkTagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(),
            'name' => fake()->unique()->word(),
        ];
    }
}
