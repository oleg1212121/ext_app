<?php

namespace Database\Factories;

use App\Models\EnTranscription;
use App\Models\EnTranscriptionType;
use App\Models\EnWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnTranscription>
 */
class EnTranscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transcription' => fake()->lexify('/????/'),
            'en_word_id' => EnWord::factory(),
            'en_transcription_type_id' => EnTranscriptionType::factory(),
        ];
    }
}
