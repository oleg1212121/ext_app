<?php

namespace Database\Factories;

use App\Models\EntityTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntityTag>
 */
class EntityTagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(),
            'name' => fake()->unique()->word(),
        ];
    }
}
