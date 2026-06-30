<?php

namespace Database\Factories;

use App\Models\EnDefinition;
use App\Models\EnWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnDefinition>
 */
class EnDefinitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'definition' => fake()->sentence(),
            'en_word_id' => EnWord::factory(),
        ];
    }
}
