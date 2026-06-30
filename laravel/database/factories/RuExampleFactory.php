<?php

namespace Database\Factories;

use App\Models\RuExample;
use App\Models\RuWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuExample>
 */
class RuExampleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'example' => fake()->sentence(),
            'ru_word_id' => RuWord::factory(),
        ];
    }
}
