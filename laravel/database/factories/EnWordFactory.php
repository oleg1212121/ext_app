<?php

namespace Database\Factories;

use App\Models\EnWord;
use App\Models\EnWordClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnWord>
 */
class EnWordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'word' => fake()->unique()->word(),
            'l_word' => null,
            'frequency' => fake()->randomFloat(2, 0, 100),
            'en_word_class_id' => EnWordClass::factory(),
        ];
    }
}
