<?php

namespace Database\Factories;

use App\Models\SavedPhrase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedPhrase>
 */
class SavedPhraseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'phrase' => fake()->sentence(),
        ];
    }
}
