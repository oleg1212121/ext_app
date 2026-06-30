<?php

namespace Database\Factories;

use App\Models\RuTranscriptionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuTranscriptionType>
 */
class RuTranscriptionTypeFactory extends Factory
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
