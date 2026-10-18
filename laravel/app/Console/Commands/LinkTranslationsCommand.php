<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
use App\Support\StagedTranslations;
use App\Support\TranslationTargets;
use App\Support\WordLookupKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkTranslationsCommand extends Command
{
    protected $signature = 'dictionary:link-translations';

    protected $description = 'Link words across languages through the staged translations JSON — shared-resolver matching, linked candidates pruned from the JSON, unmatched reported as counts with a capped sample';

    private const BATCH_SIZE = 1000;

    private const STATEMENT_TIMEOUT_MS = 30_000;

    private const SAMPLE_LIMIT = 10;

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
                        $stats['unmatched'],
                        $stats['pruned'],
                        $stats['total'],
                        $this->sampleColumn($stats),
                    ];
                }
            }

            $this->newLine(2);
            $this->info('Linking completed!');
            $this->table(
                ['Direction', 'Linked', 'Unmatched (stay staged)', 'Words pruned', 'Total staged', 'Unmatched sample'],
                $tableRows,
            );
        } finally {
            DB::statement('SET statement_timeout = 0');
        }

        return self::SUCCESS;
    }

    private function linkDirection(Language $fromLanguage, Language $toLanguage): array
    {
        $stats = ['linked' => 0, 'unmatched' => 0, 'pruned' => 0, 'total' => 0, 'sample' => []];
        $newLinks = [];

        /** @var array<int, string> $slugByFromClassId slug by class id, source language */
        $slugByFromClassId = WordClass::query()
            ->where('language_id', $fromLanguage->id)
            ->pluck('slug', 'id')
            ->toArray();

        /** @var array<string, int> $toClassBySlug class id by slug, target language */
        $toClassBySlug = WordClass::query()
            ->where('language_id', $toLanguage->id)
            ->orderBy('id')
            ->pluck('id', 'slug')
            ->toArray();

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

                // One resolver query per staged word instead of the old
                // per-candidate lookup; the wanted-class map follows the
                // shared writers' contract through the shared decision
                // (TranslationTargets::wantedClasses): a multi-word
                // candidate wants the target's phrase class, a single-word
                // one the source word's class slug mapped into the target
                // language, an unmappable slug null (match by l_word alone).
                $wanted = TranslationTargets::wantedClasses(
                    $translations,
                    $toClassBySlug,
                    $slugByFromClassId[$fromWord->word_class_id] ?? null,
                );

                $resolved = TranslationTargets::forLanguage($toLanguage->id, $wanted);

                $linkedStrings = [];
                foreach ($translations as $translation) {
                    $stats['total']++;

                    $normal = WordLookupKey::normalize(trim((string) $translation));

                    if (! isset($resolved[$normal])) {
                        $stats['unmatched']++;
                        if (count($stats['sample']) < self::SAMPLE_LIMIT) {
                            $stats['sample'][] = "{$fromWord->word} → {$translation}";
                        }

                        continue;
                    }

                    // Counted per candidate whether the link row is new or
                    // pre-existing: either way the staged string converts
                    // into a link and leaves the JSON below.
                    $stats['linked']++;
                    $linkedStrings[] = $translation;

                    [$wordAId, $wordBId] = WordTranslation::canonicalize($fromWord->id, $resolved[$normal]);

                    $linkKey = $wordAId.'|'.$wordBId;
                    if (! isset($newLinks[$linkKey]) && ! WordTranslation::isLinked($wordAId, $wordBId)) {
                        $newLinks[$linkKey] = [
                            'word_a_id' => $wordAId,
                            'word_b_id' => $wordBId,
                        ];
                    }

                    if (count($newLinks) >= self::BATCH_SIZE) {
                        WordTranslation::upsert(array_values($newLinks), ['word_a_id', 'word_b_id']);
                        $newLinks = [];
                    }
                }

                // Prune-on-link (the single staged-link pass): the exact
                // stored strings that resolved leave the JSON; a word whose
                // JSON empties stores null. Unmatched candidates stay staged
                // for a later pass once their target words exist.
                if ($linkedStrings !== []) {
                    $pruned = StagedTranslations::prune($translations, $linkedStrings);
                    if ($pruned !== $translations) {
                        $fromWord->forceFill(['translations' => $pruned])->save();
                        $stats['pruned']++;
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
     * The summary-table cell for one direction's unmatched candidates: the
     * first few misses as "source → candidate" pairs, an ellipsis when the
     * count ran past the cap — never a per-candidate warn (millions of
     * lines at real scale). One pair per line: the table wraps long cells
     * mid-string at the terminal width, which would shear the pairs.
     *
     * @param  array{linked: int, unmatched: int, pruned: int, total: int, sample: list<string>}  $stats
     */
    private function sampleColumn(array $stats): string
    {
        if ($stats['unmatched'] === 0) {
            return '';
        }

        $lines = $stats['sample'];
        if ($stats['unmatched'] > count($stats['sample'])) {
            $lines[] = '…';
        }

        return implode("\n", $lines);
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
