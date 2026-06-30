<?php

namespace Database\Seeders;

use App\Models\EnTranscriptionType;
use Illuminate\Database\Seeder;

class EnTranscriptionTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['slug' => 'ipa', 'title' => 'IPA', 'description' => 'International Phonetic Alphabet transcription'],
            ['slug' => 'enpr', 'title' => 'English Pronunciation', 'description' => 'English pronunciation respelling'],
        ];

        EnTranscriptionType::upsert($types, ['slug'], ['title', 'description']);
    }
}
