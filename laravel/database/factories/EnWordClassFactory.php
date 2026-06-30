<?php

namespace Database\Factories;

use App\Models\EnWordClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnWordClass>
 */
class EnWordClassFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(),
            'title' => fake()->word(),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
