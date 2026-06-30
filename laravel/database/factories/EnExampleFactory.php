<?php

namespace Database\Factories;

use App\Models\EnExample;
use App\Models\EnWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnExample>
 */
class EnExampleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'example' => fake()->sentence(),
            'en_word_id' => EnWord::factory(),
        ];
    }
}
