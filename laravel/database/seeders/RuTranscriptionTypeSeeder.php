<?php

namespace Database\Seeders;

use App\Models\RuTranscriptionType;
use Illuminate\Database\Seeder;

class RuTranscriptionTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['slug' => 'ipa', 'title' => 'МФА', 'description' => 'Транскрипция по Международному фонетическому алфавиту'],
        ];

        RuTranscriptionType::upsert($types, ['slug'], ['title', 'description']);
    }
}
