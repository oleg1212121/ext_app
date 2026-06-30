<?php

namespace Database\Factories;

use App\Models\RuDefinition;
use App\Models\RuWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuDefinition>
 */
class RuDefinitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'definition' => fake()->sentence(),
            'ru_word_id' => RuWord::factory(),
        ];
    }
}
