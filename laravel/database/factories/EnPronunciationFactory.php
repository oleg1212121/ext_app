<?php

namespace Database\Factories;

use App\Models\EnPronunciation;
use App\Models\EnTranscriptionType;
use App\Models\EnWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnPronunciation>
 */
class EnPronunciationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'path' => fake()->filePath(),
            'en_word_id' => EnWord::factory(),
            'en_transcription_type_id' => EnTranscriptionType::factory(),
        ];
    }
}
