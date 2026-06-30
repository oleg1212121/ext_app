<?php

namespace Database\Factories;

use App\Models\Etymology;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Etymology>
 */
class EtymologyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pos' => fake()->randomElement(['noun', 'verb', 'adjective', 'adverb']),
            'word' => fake()->word(),
            'etymology' => fake()->sentence(),
        ];
    }
}
