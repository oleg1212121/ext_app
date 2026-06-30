<?php

namespace Database\Factories;

use App\Models\Word;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Word>
 */
class WordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'word' => fake()->unique()->word(),
            'lword' => null,
            'is_full' => fake()->boolean(),
            'is_known' => fake()->boolean(),
            'has_definitions' => fake()->boolean(),
            'for_crossword' => fake()->boolean(),
            'knowledge' => fake()->numberBetween(0, 100),
            'less_100' => 0,
            'less_500' => 0,
            'less_1000' => 0,
            'less_3000' => 0,
            'less_5000' => 0,
            'less_8000' => 0,
            'less_10000' => 0,
            'less_20000' => 0,
            'less_50000' => 0,
            'less_1000000' => 0,
        ];
    }
}
