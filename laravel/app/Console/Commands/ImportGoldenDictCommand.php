<?php

namespace App\Console\Commands;

use App\Classes\GoldenDictParser;
use App\Models\Language;
use Illuminate\Console\Command;

class ImportGoldenDictCommand extends Command
{
    /** Selector keys a profile may set besides its description. */
    private const PROFILE_SELECTOR_KEYS = ['transcriptions', 'translations', 'definitions', 'forms', 'pos', 'strip'];

    protected $signature = 'goldendict:import {file}
                            {--lang= : Source language code from the languages registry}
                            {--target-lang= : Target language code for bilingual import (links candidates to existing target-language words and stages the raw strings in words.translations — creates no target rows)}
                            {--profile= : Extraction profile slug from config/goldendict.php (declarative XPath selectors layered over the generic heuristics)}
                            {--batch-size=500 : Number of records per DB flush}
                            {--max-entries= : Stop after this many parsed entries (smoke runs)}
                            {--dry-run : Parse and report what would be written, writing nothing}
                            {--no-link : Skip the bilingual phases entirely (no links or staging)}';

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
        $profile = null;
        $profileSlug = '';

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
            $profileSlug = trim((string) $this->option('profile'));
            $profiles = config('goldendict.profiles');
            if (! is_array($profiles) || ! isset($profiles[$profileSlug]) || ! is_array($profiles[$profileSlug])) {
                // Two short lines, not one long one: the error component
                // wraps at the terminal width, which would bury the list.
                $this->error("Unknown extraction profile: {$profileSlug}.");
                $this->line('Known profiles: '.implode(', ', array_keys((array) $profiles)));

                return self::FAILURE;
            }

            $profile = $this->resolveProfile($profileSlug, $profiles[$profileSlug]);
            if ($profile === null) {
                return self::FAILURE;
            }
        }

        $this->info("Importing GoldenDict data from: {$file}");
        $this->info('Language: '.$lang.($targetLang !== '' ? " → Target: {$targetLang}" : '').", Batch size: {$batchSize}");
        if ($profileSlug !== '') {
            $this->info("Extraction profile: {$profileSlug}");
        }
        if ($targetLang !== '' && $noLink) {
            $this->warn('--no-link: skipping the bilingual phases (no links or staging).');
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
                $profile,
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
        $rows = [
            ['Entries read', $stats['entries_read']],
            ['Entries skipped', $stats['entries_skipped']],
            ['Headwords rejected', $stats['headwords_rejected']],
            ['Words imported', $stats['words_imported']],
            ['Definitions imported', $stats['definitions_imported']],
            ['Definitions skipped (foreign language)', $stats['definitions_skipped_foreign']],
            ['Transcriptions imported', $stats['transcriptions_imported']],
            ['Forms imported', $stats['forms_imported']],
            ['Translation candidates', $stats['translation_candidates']],
            ['Unmatched candidates', $stats['translation_targets_unmatched']],
            ['Translation links', $stats['translations_linked']],
            ['Lookups created', $stats['lookups_created']],
            ['Batches flushed', $stats['batches_flushed']],
        ];
        if ($profile !== null) {
            // Samples of these articles are in the log ("Goldendict profile …").
            $rows[] = ['Profile no-match articles', $stats['profile_no_match_articles']];
        }
        $this->table(['Metric', 'Count'], $rows);

        return self::SUCCESS;
    }

    /**
     * Validate one config profile and normalize it for the parser: each
     * selector key becomes a non-empty list of syntactically valid XPath
     * strings; the description is documentation and is dropped.
     */
    private function resolveProfile(string $slug, array $config): ?array
    {
        $allowed = ['description', ...self::PROFILE_SELECTOR_KEYS];
        $unknown = implode(', ', array_keys(array_diff_key($config, array_fill_keys($allowed, true))));
        if ($unknown !== '') {
            $this->error("Profile \"{$slug}\" has unknown key(s): {$unknown}. Allowed keys: ".implode(', ', $allowed).'.');

            return null;
        }

        $profile = ['slug' => $slug];
        foreach (self::PROFILE_SELECTOR_KEYS as $kind) {
            $value = $config[$kind] ?? [];
            if (is_string($value)) {
                $value = [$value];
            }
            if (! is_array($value)) {
                $this->error("Profile \"{$slug}\": the {$kind} selectors must be an XPath string or a list of them.");

                return null;
            }
            foreach ($value as $query) {
                if (! is_string($query) || trim($query) === '') {
                    $this->error("Profile \"{$slug}\": every {$kind} selector must be a non-empty XPath string.");

                    return null;
                }
            }
            if ($value !== [] && ! $this->xpathsAreValid($slug, $kind, array_values($value))) {
                return null;
            }
            $profile[$kind] = array_values($value);
        }

        return $profile;
    }

    /**
     * Compile-check the selectors against a throwaway document, so a typo
     * fails the command up front instead of silently matching nothing
     * mid-import.
     *
     * @param  list<string>  $queries
     */
    private function xpathsAreValid(string $slug, string $kind, array $queries): bool
    {
        $xpath = new \DOMXPath(new \DOMDocument);
        $useInternal = libxml_use_internal_errors(true);
        try {
            foreach ($queries as $query) {
                if (@$xpath->query($query) === false) {
                    $this->error("Profile \"{$slug}\": invalid XPath in the {$kind} selectors: {$query}");

                    return false;
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternal);
        }

        return true;
    }
}
