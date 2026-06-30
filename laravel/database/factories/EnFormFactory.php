<?php

namespace Database\Factories;

use App\Models\EnForm;
use App\Models\EnWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnForm>
 */
class EnFormFactory extends Factory
{
    public function definition(): array
    {
        return [
            'form' => fake()->unique()->word(),
            'l_word' => null,
            'en_word_id' => EnWord::factory(),
        ];
    }
}
