<?php

namespace Database\Factories;

use App\Models\EnTranscriptionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnTranscriptionType>
 */
class EnTranscriptionTypeFactory extends Factory
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
