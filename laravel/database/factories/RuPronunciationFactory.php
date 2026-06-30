<?php

namespace Database\Factories;

use App\Models\RuPronunciation;
use App\Models\RuTranscriptionType;
use App\Models\RuWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuPronunciation>
 */
class RuPronunciationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'path' => fake()->filePath(),
            'ru_word_id' => RuWord::factory(),
            'ru_transcription_type_id' => RuTranscriptionType::factory(),
        ];
    }
}
