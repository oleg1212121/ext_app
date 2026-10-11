<?php

namespace Database\Factories;

use App\Models\AlignmentTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlignmentTag>
 */
class AlignmentTagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(),
            'name' => fake()->unique()->word(),
        ];
    }
}
