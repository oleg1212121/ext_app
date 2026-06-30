<?php

namespace Database\Factories;

use App\Models\EnPartOfSpeech;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnPartOfSpeech>
 */
class EnPartOfSpeechFactory extends Factory
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
