<?php

namespace Database\Factories;

use App\Models\Transcription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transcription>
 */
class TranscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pos' => fake()->randomElement(['noun', 'verb', 'adjective', 'adverb']),
            'word' => fake()->word(),
            'transcription' => fake()->lexify('/????/'),
        ];
    }
}
