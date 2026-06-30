<?php

namespace Database\Factories;

use App\Models\Definition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Definition>
 */
class DefinitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pos' => fake()->randomElement(['noun', 'verb', 'adjective', 'adverb', 'pronoun', 'preposition', 'conjunction', 'interjection']),
            'word' => fake()->word(),
            'lword' => null,
            'word_id' => null,
            'definition' => fake()->sentence(),
            'is_obsolete' => false,
        ];
    }
}
