<?php

namespace Database\Seeders;

use App\Models\Entity;
use App\Models\Language;
use App\Models\Work;
use Illuminate\Database\Seeder;

class SimulatorEntitySeeder extends Seeder
{
    public const EXCLUDED_FILES = [
        '001_articles.txt',
        'book_thief_1.txt',
    ];

    /**
     * Files that carry third-party copyrighted text and must start restricted.
     * Admin publishes them (flips is_restricted to false) once appropriate.
     */
    public const RESTRICTED_FILES = [
        'the_book_thief_5.txt',
    ];

    public const FILE_PATH_PREFIX = 'texts/simulator/';

    /**
     * Simulator texts are English originals with Russian translations; used as
     * the works' original language when seeding.
     */
    public const ORIGINAL_LANGUAGE_CODE = 'en';

    public function run(): void
    {
        $directory = public_path('texts/simulator');

        if (! is_dir($directory)) {
            return;
        }

        $originalLanguage = Language::query()
            ->where('code', self::ORIGINAL_LANGUAGE_CODE)
            ->first();

        if ($originalLanguage === null) {
            return;
        }

        $languageIds = Language::query()->whereIn('code', ['en', 'ru'])->pluck('id', 'code');
        $files = glob($directory.'/*.txt') ?: [];

        foreach ($files as $file) {
            $filename = basename($file);

            if (in_array($filename, self::EXCLUDED_FILES, true)) {
                continue;
            }

            $basename = pathinfo($filename, PATHINFO_FILENAME);
            $filePath = self::FILE_PATH_PREFIX.$filename;
            $isRestricted = in_array($filename, self::RESTRICTED_FILES, true);

            $work = Work::query()->firstOrCreate(
                ['title' => self::workTitle($basename)],
                ['original_language_id' => $originalLanguage->id],
            );

            foreach (['en', 'ru'] as $code) {
                $languageId = $languageIds[$code] ?? null;

                if ($languageId === null) {
                    continue;
                }

                Entity::query()->updateOrCreate(
                    [
                        'work_id' => $work->id,
                        'language_id' => $languageId,
                        'name' => self::entityName($basename, $code),
                    ],
                    [
                        'description' => ucfirst($code === 'en' ? 'English' : 'Russian')." sentences from {$filename}.",
                        'file_path' => $filePath,
                        'is_restricted' => $isRestricted,
                    ],
                );
            }
        }
    }

    public static function workTitle(string $basename): string
    {
        return str_replace('_', ' ', $basename);
    }

    public static function entityName(string $basename, string $languageCode): string
    {
        return "{$basename} ({$languageCode})";
    }
}
