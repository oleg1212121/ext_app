<?php

namespace Database\Factories;

use App\Models\RuWord;
use App\Models\RuWordClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuWord>
 */
class RuWordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'word' => fake()->unique()->word(),
            'l_word' => null,
            'frequency' => fake()->randomFloat(2, 0, 100),
            'ru_word_class_id' => RuWordClass::factory(),
        ];
    }
}
