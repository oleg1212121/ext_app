<?php

namespace App\Console\Commands;

use App\Classes\RawWiktextractExtractor;
use App\Classes\WiktionaryParser;
use App\Models\Language;
use App\Models\Word;
use Illuminate\Console\Command;

class ImportRawWiktextractCommand extends Command
{
    protected $signature = 'wiktionary:import-raw {file}
                            {--langs=en,ru : Comma-separated language codes to import from the dump}
                            {--target-langs= : Comma-separated override for staged-translation targets (default: every other imported language)}
                            {--batch-size=500 : Number of records per DB flush}
                            {--fresh : Delete all dictionary data for the selected languages before importing}
                            {--force : Skip the --fresh confirmation prompt}
                            {--extract-only : Stop after writing the per-language extract files}
                            {--skip-extract : Reuse existing extract files instead of re-extracting}
                            {--no-link : Skip the wiktionary:link-translations pass}
                            {--max-lines= : Limit raw lines read during extraction (smoke runs)}';

    protected $description = 'Import selected languages from a monolithic kaikki raw-wiktextract dump (.jsonl or .jsonl.gz): extract per-language files, import each, then link translations';

    public function handle(): int
    {
        // One huge JSON line (e.g. the entry for "the") can spike past the CLI
        // default of 128M during json_decode; the streaming guards keep steady
        // state well below this ceiling.
        ini_set('memory_limit', '1G');
        set_time_limit(0);

        $file = $this->argument('file');

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $registryCodes = Language::query()->orderBy('sort_order')->pluck('code')->all();
        if ($registryCodes === []) {
            $this->error('No languages in the registry. Seed languages first.');

            return self::FAILURE;
        }

        $langs = $this->parseCodes($this->option('langs'));
        if ($langs === []) {
            $this->error('No languages selected. Use --langs=code1,code2.');

            return self::FAILURE;
        }

        foreach ($langs as $code) {
            if (! in_array($code, $registryCodes, true)) {
                $this->error("Unknown language: {$code}. Available: ".implode(', ', $registryCodes));

                return self::FAILURE;
            }
        }

        $targetLangs = $this->parseCodes($this->option('target-langs'));
        foreach ($targetLangs as $code) {
            if (! in_array($code, $registryCodes, true)) {
                $this->error("Unknown target language: {$code}. Available: ".implode(', ', $registryCodes));

                return self::FAILURE;
            }
        }

        $extractor = new RawWiktextractExtractor;

        $extractOnly = (bool) $this->option('extract-only');
        $skipExtract = (bool) $this->option('skip-extract');
        $maxLines = $this->option('max-lines') !== null ? (int) $this->option('max-lines') : null;

        $this->info("Importing raw wiktextract dump: {$file}");
        $this->info('Languages: '.implode(', ', $langs).($extractOnly ? ' (extract only)' : ''));

        if (! $skipExtract) {
            $this->info('Extracting per-language files...');
            $extractStats = $extractor->extract($file, $langs, $this->output, $maxLines);

            $this->newLine();
            $this->info('Extraction finished'.($extractStats['truncated'] ? ' (stopped at --max-lines)' : '').':');
            $rows = [
                ['Lines read', $extractStats['lines_read']],
                ['Skipped (other languages)', $extractStats['skipped']],
            ];
            foreach ($extractStats['kept'] as $code => $count) {
                $rows[] = ["Kept {$code}", $count];
                $rows[] = ["Extract file {$code}", $extractStats['files'][$code]];
            }
            $this->table(['Metric', 'Value'], $rows);
        } else {
            $this->info('Skipping extraction (--skip-extract), reusing existing extract files.');
        }

        if ($extractOnly) {
            $this->info('Extract-only run: database untouched.');

            return self::SUCCESS;
        }

        $languageIds = Language::query()->whereIn('code', $langs)->pluck('id', 'code');

        if ($this->option('fresh')) {
            if (! $this->option('force')) {
                // stream_isatty covers detached runs (docker exec -d), CI and
                // programmatic invocations; --force is the explicit opt-out.
                if (! stream_isatty(STDIN)) {
                    $this->error('--fresh requires --force when STDIN is not a terminal.');

                    return self::FAILURE;
                }

                $confirmed = $this->confirm(
                    '--fresh deletes every word, definition, form, transcription, etymology, translation link '
                    .'and user familiarity record for: '.implode(', ', $langs).'. Entity word links are reset. Continue?',
                );
                if (! $confirmed) {
                    $this->error('Aborted.');

                    return self::FAILURE;
                }
            }

            $this->wipeLanguages($languageIds->all());
        }

        $importStats = [];
        foreach ($langs as $code) {
            $targetsForLang = $targetLangs !== [] ? $targetLangs : array_values(array_diff($langs, [$code]));
            $extractPath = $this->extractPathFor($file, $code);

            if (! file_exists($extractPath)) {
                $this->error("Extract file not found: {$extractPath}. Run without --skip-extract first.");

                return self::FAILURE;
            }

            $this->info("Importing {$code} from {$extractPath} (staging targets: ".implode(', ', $targetsForLang).')');

            $parser = new WiktionaryParser($code, $targetsForLang, (int) $this->option('batch-size'));
            $importStats[$code] = $parser->import($extractPath, $this->output);
            $this->newLine();
        }

        $rows = [];
        foreach ($importStats as $code => $stats) {
            foreach ($stats as $metric => $value) {
                $rows[] = ["{$code} {$metric}", $value];
            }
        }
        $this->table(['Metric', 'Count'], $rows);

        if ($this->option('no-link')) {
            $this->info('Skipping translation linking (--no-link). Run wiktionary:link-translations later.');

            return self::SUCCESS;
        }

        $languagesWithWords = Word::query()->select('language_id')->distinct()->count();
        if ($languagesWithWords < 2) {
            $this->warn('Fewer than two languages with imported words — skipping wiktionary:link-translations.');

            return self::SUCCESS;
        }

        $this->info('Linking translations...');
        $exitCode = $this->call('wiktionary:link-translations');
        if ($exitCode !== self::SUCCESS) {
            $this->warn('wiktionary:link-translations failed — run it manually to finish linking.');
        }

        return $exitCode;
    }

    /**
     * @return list<string>
     */
    private function parseCodes(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $code) => trim($code),
            explode(',', $raw),
        ))));
    }

    private function extractPathFor(string $file, string $code): string
    {
        $base = $file;
        if (str_ends_with(strtolower($base), '.gz')) {
            $base = substr($base, 0, -3);
        }
        if (str_ends_with(strtolower($base), '.jsonl')) {
            $base = substr($base, 0, -6);
        }

        return $base.'.'.$code.'.jsonl';
    }

    /**
     * @param  array<int, int>  $languageIds
     */
    private function wipeLanguages(array $languageIds): void
    {
        $this->info('Wiping dictionary data for selected languages (--fresh)...');

        $deleted = 0;
        do {
            // Chunked delete: FK cascades clear satellites and translation
            // links per statement instead of one huge transaction.
            $ids = Word::query()
                ->whereIn('language_id', $languageIds)
                ->limit(5000)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            Word::query()->whereIn('id', $ids)->delete();
            $deleted += $ids->count();
            $this->info("Deleted {$deleted} words...");
        } while (true);

        $this->info("Wiped {$deleted} words (satellites and translation links cascaded).");
    }
}
