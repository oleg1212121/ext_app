<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkTranslationsCommand extends Command
{
    protected $signature = 'wiktionary:link-translations';

    protected $description = 'Link words across languages through stored translations, stripping stress marks for matching';

    private const BATCH_SIZE = 1000;

    private const STATEMENT_TIMEOUT_MS = 30_000;

    public function handle(): int
    {
        $languages = Language::query()
            ->whereIn('id', Word::query()->select('language_id')->distinct())
            ->get();

        if ($languages->count() < 2) {
            $this->error('At least two languages with imported words are required.');

            return self::FAILURE;
        }

        $this->killStuckDeleteTransactions();
        DB::statement('SET statement_timeout = '.self::STATEMENT_TIMEOUT_MS);

        try {
            $tableRows = [];

            foreach ($languages as $fromLanguage) {
                foreach ($languages as $toLanguage) {
                    if ($fromLanguage->id === $toLanguage->id) {
                        continue;
                    }

                    $this->newLine();
                    $this->info("Linking {$fromLanguage->code} → {$toLanguage->code} translations...");
                    $stats = $this->linkDirection($fromLanguage, $toLanguage);

                    $tableRows[] = [
                        strtoupper($fromLanguage->code).' → '.strtoupper($toLanguage->code),
                        $stats['linked'],
                        $stats['skipped'],
                        $stats['total'],
                    ];
                }
            }

            $this->newLine(2);
            $this->info('Linking completed!');
            $this->table(['Direction', 'Linked', 'Skipped (no match)', 'Total translations'], $tableRows);
        } finally {
            DB::statement('SET statement_timeout = 0');
        }

        return self::SUCCESS;
    }

    private function linkDirection(Language $fromLanguage, Language $toLanguage): array
    {
        $stats = ['linked' => 0, 'skipped' => 0, 'total' => 0];
        $newLinks = [];

        /** @var array<int, int> $fromClassBySlug class id by slug, source language */
        $fromClassBySlug = WordClass::query()
            ->where('language_id', $fromLanguage->id)
            ->pluck('id', 'slug')
            ->toArray();

        /** @var array<int, string> $slugByFromClassId slug by class id, source language */
        $slugByFromClassId = array_flip($fromClassBySlug);

        /** @var array<string, int> $toClassBySlug class id by slug, target language */
        $toClassBySlug = WordClass::query()
            ->where('language_id', $toLanguage->id)
            ->pluck('id', 'slug')
            ->toArray();

        if (empty($toClassBySlug)) {
            return $stats;
        }

        $fromWordIds = Word::query()
            ->where('language_id', $fromLanguage->id)
            ->whereNotNull('translations')
            ->pluck('id');

        $bar = $this->output->createProgressBar($fromWordIds->count());

        foreach ($fromWordIds->chunk(500) as $idChunk) {
            $fromWords = Word::query()
                ->whereIn('id', $idChunk->all())
                ->select(['id', 'word', 'word_class_id', 'translations'])
                ->get();

            foreach ($fromWords as $fromWord) {
                $bar->advance();
                $translations = $fromWord->translations ?? [];

                $slug = $slugByFromClassId[$fromWord->word_class_id] ?? null;
                $toClassId = ($slug !== null && isset($toClassBySlug[$slug])) ? $toClassBySlug[$slug] : reset($toClassBySlug);

                foreach ($translations as $translation) {
                    $stats['total']++;

                    $normalized = $this->normalizeTargetWord((string) $translation, $toLanguage->code);

                    $toWordId = Word::query()
                        ->where('language_id', $toLanguage->id)
                        ->where('l_word', $normalized)
                        ->where('word_class_id', $toClassId)
                        ->value('id');

                    if ($toWordId === null) {
                        $stats['skipped']++;
                        $this->warn("  No match: {$fromLanguage->code} '{$fromWord->word}' → {$toLanguage->code} '{$translation}' (normalized: '{$normalized}')");

                        continue;
                    }

                    [$wordAId, $wordBId] = WordTranslation::canonicalize($fromWord->id, $toWordId);

                    $linkKey = $wordAId.'|'.$wordBId;
                    if (! isset($newLinks[$linkKey]) && ! WordTranslation::isLinked($wordAId, $wordBId)) {
                        $newLinks[$linkKey] = [
                            'word_a_id' => $wordAId,
                            'word_b_id' => $wordBId,
                        ];
                        $stats['linked']++;
                    }

                    if (count($newLinks) >= self::BATCH_SIZE) {
                        WordTranslation::upsert(array_values($newLinks), ['word_a_id', 'word_b_id']);
                        $newLinks = [];
                    }
                }
            }
        }

        $bar->finish();
        $this->newLine();

        if (! empty($newLinks)) {
            WordTranslation::upsert(array_values($newLinks), ['word_a_id', 'word_b_id']);
        }

        return $stats;
    }

    /**
     * Normalize a translation for target-language lookup: Cyrillic targets
     * carry combining stress marks that must be stripped before matching.
     */
    private function normalizeTargetWord(string $word, string $targetCode): string
    {
        if ($targetCode === 'ru') {
            $word = (string) preg_replace('/\p{M}/u', '', $word);
        }

        return mb_strtolower(trim($word));
    }

    private function killStuckDeleteTransactions(): void
    {
        DB::statement(<<<'SQL'
            SELECT pg_terminate_backend(pid)
            FROM pg_stat_activity
            WHERE datname = current_database()
              AND state = 'active'
              AND pid <> pg_backend_pid()
              AND query ~ 'delete from "words"'
            SQL
        );
    }
}
