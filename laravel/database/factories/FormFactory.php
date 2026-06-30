<?php

namespace Database\Factories;

use App\Models\Form;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pos' => fake()->randomElement(['noun', 'verb', 'adjective', 'adverb']),
            'word' => fake()->word(),
            'lword' => null,
            'word_id' => null,
            'form' => fake()->word(),
            'lform' => null,
        ];
    }
}
