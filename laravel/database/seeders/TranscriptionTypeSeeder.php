<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\TranscriptionType;
use Illuminate\Database\Seeder;

class TranscriptionTypeSeeder extends Seeder
{
    /**
     * @var array<string, list<array{slug: string, title: string, description: string}>>
     */
    private const TYPES = [
        'en' => [
            ['slug' => 'ipa', 'title' => 'IPA', 'description' => 'International Phonetic Alphabet transcription'],
            ['slug' => 'enpr', 'title' => 'English Pronunciation', 'description' => 'English pronunciation respelling'],
        ],
        'ru' => [
            ['slug' => 'ipa', 'title' => 'МФА', 'description' => 'Транскрипция по Международному фонетическому алфавиту'],
        ],
    ];

    public function run(): void
    {
        $languageIds = Language::query()
            ->whereIn('code', array_keys(self::TYPES))
            ->pluck('id', 'code');

        foreach (self::TYPES as $code => $types) {
            $languageId = $languageIds[$code] ?? null;

            if ($languageId === null) {
                continue;
            }

            foreach ($types as $type) {
                TranscriptionType::query()->updateOrCreate(
                    [
                        'language_id' => $languageId,
                        'slug' => $type['slug'],
                    ],
                    [
                        'title' => $type['title'],
                        'description' => $type['description'],
                    ],
                );
            }
        }
    }
}
