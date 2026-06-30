<?php

namespace Database\Factories;

use App\Models\Translation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Translation>
 */
class TranslationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pos' => fake()->randomElement(['noun', 'verb', 'adjective', 'adverb']),
            'word' => fake()->word(),
            'translation' => fake()->word(),
        ];
    }
}
