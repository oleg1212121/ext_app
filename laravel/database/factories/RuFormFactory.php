<?php

namespace Database\Factories;

use App\Models\RuForm;
use App\Models\RuWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuForm>
 */
class RuFormFactory extends Factory
{
    public function definition(): array
    {
        return [
            'form' => fake()->unique()->word(),
            'l_word' => null,
            'ru_word_id' => RuWord::factory(),
        ];
    }
}
