<?php

namespace Database\Factories;

use App\Models\RuEtymology;
use App\Models\RuWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuEtymology>
 */
class RuEtymologyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'etymology' => fake()->sentence(),
            'ru_word_id' => RuWord::factory(),
        ];
    }
}
