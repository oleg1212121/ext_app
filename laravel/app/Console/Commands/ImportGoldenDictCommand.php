<?php

namespace App\Console\Commands;

use App\Classes\GoldenDictParser;
use App\Models\Language;
use Illuminate\Console\Command;

class ImportGoldenDictCommand extends Command
{
    protected $signature = 'goldendict:import {file}
                            {--lang= : Source language code from the languages registry}
                            {--target-lang= : Target language code for bilingual import (creates target words, word_translations links and words.translations staging)}
                            {--profile= : Extraction profile slug (not implemented yet)}
                            {--batch-size=500 : Number of records per DB flush}
                            {--max-entries= : Stop after this many parsed entries (smoke runs)}
                            {--dry-run : Parse and report what would be written, writing nothing}
                            {--no-link : Skip the bilingual phases entirely (no target words, links or staging)}';

    protected $description = 'Import a converted GoldenDict JSONL ({"word","alts","html"} per line, from ai/goldendict_convert.py) into the dictionary tables';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $lang = trim((string) $this->option('lang'));
        $targetLang = trim((string) $this->option('target-lang'));
        $noLink = (bool) $this->option('no-link');
        $batchSize = max(1, (int) $this->option('batch-size'));
        $maxEntries = $this->option('max-entries');
        $dryRun = (bool) $this->option('dry-run');

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        if ($lang === '') {
            $this->error('The --lang option is required (a language code from the languages registry).');

            return self::FAILURE;
        }

        $registryCodes = Language::query()->orderBy('sort_order')->pluck('code')->all();
        if ($registryCodes === []) {
            $this->error('No languages in the registry. Seed languages first.');

            return self::FAILURE;
        }

        if (! in_array($lang, $registryCodes, true)) {
            $this->error("Unknown language: {$lang}. Available: ".implode(', ', $registryCodes));

            return self::FAILURE;
        }

        if ($targetLang !== '') {
            if (! in_array($targetLang, $registryCodes, true)) {
                $this->error("Unknown target language: {$targetLang}. Available: ".implode(', ', $registryCodes));

                return self::FAILURE;
            }
            if ($targetLang === $lang) {
                $this->error('The --target-lang option must differ from --lang.');

                return self::FAILURE;
            }
        }

        if ((string) $this->option('profile') !== '') {
            $this->warn('--profile not implemented yet (later tickets of this feature); ignoring.');
        }

        $this->info("Importing GoldenDict data from: {$file}");
        $this->info('Language: '.$lang.($targetLang !== '' ? " → Target: {$targetLang}" : '').", Batch size: {$batchSize}");
        if ($targetLang !== '' && $noLink) {
            $this->warn('--no-link: skipping the bilingual phases (no target words, links or staging).');
        }
        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be written.');
        }

        try {
            $parser = new GoldenDictParser(
                $lang,
                $batchSize,
                $dryRun,
                $targetLang !== '' && ! $noLink ? $targetLang : null,
            );
            $stats = $parser->import(
                $file,
                $this->output,
                $maxEntries !== null ? max(0, (int) $maxEntries) : null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine(2);
        $this->info($dryRun ? 'Dry run completed — nothing was written.' : 'Import completed!');
        $this->table(['Metric', 'Count'], [
            ['Entries read', $stats['entries_read']],
            ['Entries skipped', $stats['entries_skipped']],
            ['Words imported', $stats['words_imported']],
            ['Definitions imported', $stats['definitions_imported']],
            ['Transcriptions imported', $stats['transcriptions_imported']],
            ['Forms imported', $stats['forms_imported']],
            ['Translation candidates', $stats['translation_candidates']],
            ['Target words created', $stats['target_words_created']],
            ['Translation links', $stats['translations_linked']],
            ['Lookups created', $stats['lookups_created']],
            ['Batches flushed', $stats['batches_flushed']],
        ]);

        return self::SUCCESS;
    }
}
