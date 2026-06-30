<?php

namespace Database\Factories;

use App\Models\RuTranscription;
use App\Models\RuTranscriptionType;
use App\Models\RuWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuTranscription>
 */
class RuTranscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transcription' => fake()->lexify('/????/'),
            'ru_word_id' => RuWord::factory(),
            'ru_transcription_type_id' => RuTranscriptionType::factory(),
        ];
    }
}
