<?php

namespace App\Console\Commands;

use App\Classes\WiktionaryParser;
use App\Models\Language;
use Illuminate\Console\Command;

class ImportWiktionaryCommand extends Command
{
    protected $signature = 'wiktionary:import {file}
                            {--lang=en : Source language code from the languages registry}
                            {--target-lang=ru : Comma-separated target language codes for staged translations}
                            {--batch-size=500 : Number of records per DB flush}';

    protected $description = 'Import a Kaikki Wiktionary JSONL dump file into the database. Translations are stored for later linking via wiktionary:link-translations';

    public function handle(): int
    {
        $file = $this->argument('file');
        $lang = $this->option('lang');
        $batchSize = (int) $this->option('batch-size');

        $targetLangs = array_values(array_filter(array_map(
            fn (string $code) => trim($code),
            explode(',', (string) $this->option('target-lang')),
        )));

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $registryCodes = Language::query()->orderBy('sort_order')->pluck('code')->all();
        if ($registryCodes === []) {
            $this->error('No languages in the registry. Seed languages first.');

            return self::FAILURE;
        }

        if (! in_array($lang, $registryCodes, true)) {
            $this->error("Unknown source language: {$lang}. Available: ".implode(', ', $registryCodes));

            return self::FAILURE;
        }

        foreach ($targetLangs as $targetLang) {
            if (! in_array($targetLang, $registryCodes, true)) {
                $this->error("Unknown target language: {$targetLang}. Available: ".implode(', ', $registryCodes));

                return self::FAILURE;
            }

            if ($targetLang === $lang) {
                $this->error('Source language and target language must be different.');

                return self::FAILURE;
            }
        }

        $this->info("Importing Wiktionary data from: {$file}");
        $this->info('Source language: '.$lang.', Target languages: '.implode(', ', $targetLangs).", Batch size: {$batchSize}");

        try {
            $parser = new WiktionaryParser($lang, $targetLangs, $batchSize);
            $stats = $parser->import($file, $this->output);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine(2);
        $this->info('Import completed!');
        $this->table(['Metric', 'Count'], [
            ['Lines read', $stats['lines_read']],
            ['Words imported', $stats['words_imported']],
            ['Lookups created', $stats['lookups_created']],
            ['Batches flushed', $stats['batches_flushed']],
        ]);

        return self::SUCCESS;
    }
}
