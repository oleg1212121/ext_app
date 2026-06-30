<?php

namespace App\Console\Commands;

use App\Models\EnRuTranslation;
use App\Models\EnWord;
use App\Models\EnWordClass;
use App\Models\RuEnTranslation;
use App\Models\RuWord;
use App\Models\RuWordClass;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkTranslationsCommand extends Command
{
    protected $signature = 'wiktionary:link-translations';

    protected $description = 'Link EN and RU words through stored translations, stripping stress marks for matching';

    private const BATCH_SIZE = 1000;

    private const STATEMENT_TIMEOUT_MS = 30_000;

    public function handle(): int
    {
        $enWordClasses = EnWordClass::pluck('id', 'slug')->toArray();
        $ruWordClasses = RuWordClass::pluck('id', 'slug')->toArray();

        if (empty($enWordClasses) || empty($ruWordClasses)) {
            $this->error('Word classes not found. Run the word class seeders first.');

            return self::FAILURE;
        }

        $this->killStuckDeleteTransactions();
        DB::statement('SET statement_timeout = '.self::STATEMENT_TIMEOUT_MS);

        try {
            $this->info('Linking EN → RU translations...');
            $enRuStats = $this->linkEnToRu($enWordClasses, $ruWordClasses);

            $this->newLine();
            $this->info('Linking RU → EN translations...');
            $ruEnStats = $this->linkRuToEn($ruWordClasses, $enWordClasses);

            $this->newLine(2);
            $this->info('Linking completed!');
            $this->table(['Direction', 'Linked', 'Skipped (no match)', 'Total translations'], [
                ['EN → RU', $enRuStats['linked'], $enRuStats['skipped'], $enRuStats['total']],
                ['RU → EN', $ruEnStats['linked'], $ruEnStats['skipped'], $ruEnStats['total']],
            ]);
        } finally {
            DB::statement('SET statement_timeout = 0');
        }

        return self::SUCCESS;
    }

    private function linkEnToRu(array $enWordClasses, array $ruWordClasses): array
    {
        $stats = ['linked' => 0, 'skipped' => 0, 'total' => 0];
        $newLinks = [];

        $enWordIds = EnWord::query()
            ->whereNotNull('translations')
            ->pluck('id');

        $bar = $this->output->createProgressBar($enWordIds->count());

        foreach ($enWordIds->chunk(500) as $idChunk) {
            $enWords = EnWord::query()
                ->whereIn('id', $idChunk->all())
                ->select(['id', 'word', 'en_word_class_id', 'translations'])
                ->get();

            foreach ($enWords as $enWord) {
                $bar->advance();
                $translations = $enWord->translations ?? [];

                foreach ($translations as $translation) {
                    $stats['total']++;

                    $normalized = $this->normalizeRuWord($translation);
                    $ruClassId = $this->mapWordClassSlug($enWord->en_word_class_id, $enWordClasses, $ruWordClasses);

                    $ruWordId = RuWord::where('l_word', $normalized)
                        ->where('ru_word_class_id', $ruClassId)
                        ->value('id');

                    if ($ruWordId === null) {
                        $stats['skipped']++;
                        $this->warn("  No match: EN '{$enWord->word}' → RU '{$translation}' (normalized: '{$normalized}')");

                        continue;
                    }

                    $linkKey = $enWord->id.'|'.$ruWordId;
                    if (! isset($newLinks[$linkKey]) && ! EnRuTranslation::where('en_word_id', $enWord->id)->where('ru_word_id', $ruWordId)->exists()) {
                        $newLinks[$linkKey] = [
                            'en_word_id' => $enWord->id,
                            'ru_word_id' => $ruWordId,
                        ];
                        $stats['linked']++;
                    }

                    if (count($newLinks) >= self::BATCH_SIZE) {
                        EnRuTranslation::upsert(array_values($newLinks), ['en_word_id', 'ru_word_id']);
                        $newLinks = [];
                    }
                }
            }
        }

        $bar->finish();
        $this->newLine();

        if (! empty($newLinks)) {
            EnRuTranslation::upsert(array_values($newLinks), ['en_word_id', 'ru_word_id']);
        }

        return $stats;
    }

    private function linkRuToEn(array $ruWordClasses, array $enWordClasses): array
    {
        $stats = ['linked' => 0, 'skipped' => 0, 'total' => 0];
        $newLinks = [];

        $ruWordIds = RuWord::query()
            ->whereNotNull('translations')
            ->pluck('id');

        $bar = $this->output->createProgressBar($ruWordIds->count());

        foreach ($ruWordIds->chunk(500) as $idChunk) {
            $ruWords = RuWord::query()
                ->whereIn('id', $idChunk->all())
                ->select(['id', 'word', 'ru_word_class_id', 'translations'])
                ->get();

            foreach ($ruWords as $ruWord) {
                $bar->advance();
                $translations = $ruWord->translations ?? [];

                foreach ($translations as $translation) {
                    $stats['total']++;

                    $normalized = mb_strtolower(trim($translation));
                    $enClassId = $this->mapWordClassSlug($ruWord->ru_word_class_id, $ruWordClasses, $enWordClasses);

                    $enWordId = EnWord::where('l_word', $normalized)
                        ->where('en_word_class_id', $enClassId)
                        ->value('id');

                    if ($enWordId === null) {
                        $stats['skipped']++;
                        $this->warn("  No match: RU '{$ruWord->word}' → EN '{$translation}'");

                        continue;
                    }

                    $linkKey = $ruWord->id.'|'.$enWordId;
                    if (! isset($newLinks[$linkKey]) && ! RuEnTranslation::where('ru_word_id', $ruWord->id)->where('en_word_id', $enWordId)->exists()) {
                        $newLinks[$linkKey] = [
                            'ru_word_id' => $ruWord->id,
                            'en_word_id' => $enWordId,
                        ];
                        $stats['linked']++;
                    }

                    if (count($newLinks) >= self::BATCH_SIZE) {
                        RuEnTranslation::upsert(array_values($newLinks), ['ru_word_id', 'en_word_id']);
                        $newLinks = [];
                    }
                }
            }
        }

        $bar->finish();
        $this->newLine();

        if (! empty($newLinks)) {
            RuEnTranslation::upsert(array_values($newLinks), ['ru_word_id', 'en_word_id']);
        }

        return $stats;
    }

    private function normalizeRuWord(string $word): string
    {
        $normalized = preg_replace('/\p{M}/u', '', $word);

        return mb_strtolower(trim($normalized ?? $word));
    }

    private function mapWordClassSlug(int $sourceClassId, array $sourceClasses, array $targetClasses): int
    {
        $slug = array_search($sourceClassId, $sourceClasses);

        if ($slug !== false && isset($targetClasses[$slug])) {
            return $targetClasses[$slug];
        }

        return reset($targetClasses);
    }

    private function killStuckDeleteTransactions(): void
    {
        DB::statement(<<<'SQL'
            SELECT pg_terminate_backend(pid)
            FROM pg_stat_activity
            WHERE datname = current_database()
              AND state = 'active'
              AND query ~ 'delete from "(en_words|ru_words)"'
            SQL
        );
    }
}
