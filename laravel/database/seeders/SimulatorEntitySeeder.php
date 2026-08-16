<?php

namespace Database\Seeders;

use App\Models\EnEntity;
use App\Models\RuEntity;
use Illuminate\Database\Seeder;

class SimulatorEntitySeeder extends Seeder
{
    public const EXCLUDED_FILES = [
        '001_articles.txt',
        'book_thief_1.txt',
    ];

    public const FILE_PATH_PREFIX = 'texts/simulator/';

    public function run(): void
    {
        $directory = public_path('texts/simulator');

        if (! is_dir($directory)) {
            return;
        }

        $files = glob($directory.'/*.txt') ?: [];

        foreach ($files as $file) {
            $filename = basename($file);

            if (in_array($filename, self::EXCLUDED_FILES, true)) {
                continue;
            }

            $basename = pathinfo($filename, PATHINFO_FILENAME);
            $filePath = self::FILE_PATH_PREFIX.$filename;

            EnEntity::query()->updateOrCreate(
                ['name' => self::enEntityName($basename)],
                [
                    'description' => "English sentences from {$filename}.",
                    'file_path' => $filePath,
                ],
            );

            RuEntity::query()->updateOrCreate(
                ['name' => self::ruEntityName($basename)],
                [
                    'description' => "Russian sentences from {$filename}.",
                    'file_path' => $filePath,
                ],
            );
        }
    }

    public static function enEntityName(string $basename): string
    {
        return "{$basename} (en)";
    }

    public static function ruEntityName(string $basename): string
    {
        return "{$basename} (ru)";
    }
}
