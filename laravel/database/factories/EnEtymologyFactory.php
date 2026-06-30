<?php

namespace Database\Factories;

use App\Models\EnEtymology;
use App\Models\EnWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnEtymology>
 */
class EnEtymologyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'etymology' => fake()->sentence(),
            'en_word_id' => EnWord::factory(),
        ];
    }
}
