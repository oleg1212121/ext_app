<?php

namespace App\Console\Commands;

use App\Classes\MultiwordVerbShape;
use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use Illuminate\Console\Command;

class ReclassMultiwordWordsCommand extends Command
{
    protected $signature = 'words:reclass-multiword
        {--dry-run : Report what would be reclassed without writing}';

    protected $description = 'Reclass English multi-word rows imported under the verb class that fail the particle/preposition shape ("do it", "kick the bucket") to the phrase class (ADR 0059)';

    const SAMPLES = 10;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // The Wiktionary import stored multi-word headwords under whatever
        // pos the dump claimed; only the particle/preposition shape can
        // serve multi-word-verb matching (ADR 0059) — the rest is phrase
        // material, not verb material. English-only: other languages'
        // multi-word verbs ("выдавать себя за") are legitimate verbs the
        // English shape has no opinion about.
        $rows = Word::query()
            ->where('l_word', 'like', '% %')
            ->whereHas('wordClass', fn ($q) => $q->where('slug', 'verb'))
            ->whereHas('language', fn ($q) => $q->where('code', 'en'))
            ->orderBy('id')
            ->get(['id', 'word', 'l_word', 'language_id', 'word_class_id']);

        $junk = $rows->reject(fn (Word $word) => MultiwordVerbShape::isValid($word->l_word))->values();

        if ($junk->isEmpty()) {
            $this->info('No multi-word verb rows outside the phrasal shape.');

            return self::SUCCESS;
        }

        $phraseClassIds = [];

        foreach ($junk->groupBy('language_id') as $languageId => $words) {
            $code = Language::query()->whereKey($languageId)->value('code') ?? "language #{$languageId}";
            $this->line("{$code}: {$words->count()} row(s) to reclass");

            foreach ($words->take(self::SAMPLES) as $word) {
                $this->line("  - {$word->word}");
            }

            if ($dryRun) {
                continue;
            }

            $phraseClassIds[$languageId] = WordClass::query()->firstOrCreate(
                ['language_id' => $languageId, 'slug' => 'phrase'],
                ['title' => 'phrase'],
            )->id;
        }

        if ($dryRun) {
            $this->info('Dry run: nothing written ('.$junk->count().' row(s) would be reclassed).');

            return self::SUCCESS;
        }

        $reclassed = 0;
        $skipped = 0;

        foreach ($junk as $word) {
            // The words table is unique per (word, language, class); a
            // phrase-class row of the same headword already existing means
            // the move would collide — skip and report instead of merging.
            $taken = Word::query()
                ->where('word', $word->word)
                ->where('language_id', $word->language_id)
                ->where('word_class_id', $phraseClassIds[$word->language_id])
                ->exists();

            if ($taken) {
                $skipped++;

                continue;
            }

            $word->forceFill(['word_class_id' => $phraseClassIds[$word->language_id]])->save();
            $reclassed++;
        }

        $this->info("Reclassed {$reclassed} row(s) to the phrase class".($skipped > 0 ? ", skipped {$skipped} (phrase-class duplicate already exists)" : '').'.');

        return self::SUCCESS;
    }
}
