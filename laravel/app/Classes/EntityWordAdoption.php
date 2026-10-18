<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityWord;
use App\Models\Form;
use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use App\Support\LemmaForms;
use App\Support\StressMarks;
use App\Support\WordLookupKey;
use Illuminate\Support\Collection;

/**
 * Adopts an entity's unmatchable tokens into the dictionary: tokens the
 * linker stamped unmatchable attach to an existing same-l_word word row of
 * the language when one exists (preferring a properly classed row over the
 * unknown placeholder) — a twin row is never minted for an l_word the
 * dictionary already carries. Only when nothing matches does adoption
 * create a row (class 'unknown'): a plain display word (no combining
 * stress marks for ru/be/uk) that carries itself as its lemma form. Every
 * entity word that still has no translation gets a fetch queued. The fetch
 * ledger decides per (word, target language) whether a job is really
 * needed — excluded words never re-check.
 */
class EntityWordAdoption
{
    private const BATCH_SIZE = 500;

    /**
     * Adopt the entity's unmatchable tokens, then queue translation fetches
     * for its translation-less dictionary words. Only linker-stamped rows
     * are adopted: unstamped NULL rows may still be waiting for their
     * ordinary link pass.
     *
     * @param  Collection<int, Language>|null  $targets  fetch target languages; defaults to every other enabled language
     * @return array{adopted: int, dispatched: int}
     */
    public function adoptForEntity(Entity $entity, ?Collection $targets = null): array
    {
        $adopted = $this->adoptUnmatched($entity);
        $dispatched = $this->dispatchFetches($entity, $targets ?? $this->defaultTargets($entity));

        return ['adopted' => $adopted, 'dispatched' => $dispatched];
    }

    /**
     * @return Collection<int, Language>
     */
    private function defaultTargets(Entity $entity): Collection
    {
        return Language::query()
            ->enabled()
            ->whereKeyNot($entity->language_id)
            ->orderBy('sort_order')
            ->get();
    }

    private function adoptUnmatched(Entity $entity): int
    {
        $adopted = 0;
        $languageId = (int) $entity->language_id;
        $unknownClassId = $this->ensureUnknownClass($languageId);
        $stripsMarks = StressMarks::stripsFor((string) $entity->language->code);

        EntityWord::query()
            ->where('entity_id', $entity->id)
            ->whereNull('word_id')
            ->whereNotNull('unmatchable_at')
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function ($rows) use ($languageId, $unknownClassId, $stripsMarks, &$adopted): void {
                // One batched lookup per chunk keeps the per-token cost at a
                // plain array pick: this runs inside crossword:refresh.
                $candidates = Word::query()
                    ->where('language_id', $languageId)
                    ->whereIn('l_word', $rows->pluck('l_word')->unique()->all())
                    ->orderBy('id')
                    ->get(['id', 'l_word', 'word_class_id'])
                    ->groupBy('l_word');

                foreach ($rows as $row) {
                    $word = $this->preferClassed($candidates->get($row->l_word), $unknownClassId)
                        ?? $this->createAdoptedWord($languageId, $row, $unknownClassId, $stripsMarks);

                    if ($word === null) {
                        // A token that is nothing but combining marks has no
                        // plain display word: leave it stamped instead of
                        // storing a junk row.
                        continue;
                    }

                    $row->forceFill(['word_id' => $word->id, 'unmatchable_at' => null])->save();
                    $adopted++;
                }
            });

        return $adopted;
    }

    /**
     * The existing row to adopt: the first non-unknown-class candidate in
     * id order, else the lowest-id row — an unknown placeholder never beats
     * a properly classed row of the same l_word.
     *
     * @param  Collection<int, Word>|null  $candidates
     */
    private function preferClassed(?Collection $candidates, int $unknownClassId): ?Word
    {
        return $candidates?->first(fn (Word $word) => (int) $word->word_class_id !== $unknownClassId)
            ?? $candidates?->first();
    }

    /**
     * The dictionary has no row for the token's l_word: mint one. The raw
     * surface token keeps its casing but stores plain — the combining
     * stress marks leave the display word (ru/be/uk) — and the new word
     * carries itself as its lemma form.
     */
    private function createAdoptedWord(int $languageId, EntityWord $row, int $unknownClassId, bool $stripsMarks): ?Word
    {
        $display = $stripsMarks ? StressMarks::strip($row->token) : $row->token;
        if ($display === '') {
            return null;
        }

        $word = Word::query()->create([
            'word' => mb_substr($display, 0, 256),
            'l_word' => WordLookupKey::normalize($display),
            'language_id' => $languageId,
            'word_class_id' => $unknownClassId,
        ]);

        Form::upsert(
            LemmaForms::rows($word->word, [], $word->id, $stripsMarks),
            ['form', 'word_id'],
        );

        return $word;
    }

    /**
     * Queue fetches for the entity's dictionary words that carry no
     * translation link at all.
     *
     * @param  Collection<int, Language>  $targets
     */
    private function dispatchFetches(Entity $entity, Collection $targets): int
    {
        if ($targets->isEmpty()) {
            return 0;
        }

        $service = app(WordTranslationFetchService::class);
        $dispatched = 0;

        EntityWord::query()
            ->where('entity_id', $entity->id)
            ->whereNotNull('word_id')
            ->whereDoesntHave('word.translationLinks')
            ->whereDoesntHave('word.translationLinksAsB')
            ->distinct()
            ->pluck('word_id')
            ->chunk(self::BATCH_SIZE)
            ->each(function (Collection $wordIds) use ($service, $targets, &$dispatched): void {
                Word::query()
                    ->whereIn('id', $wordIds->all())
                    ->get()
                    ->each(function (Word $word) use ($service, $targets, &$dispatched): void {
                        foreach ($targets as $target) {
                            $dispatched += $service->dispatchIfEligible($word, $target) ? 1 : 0;
                        }
                    });
            });

        return $dispatched;
    }

    /**
     * The 'unknown' part of speech every language carries — the same row
     * WiktionaryParser::ensureWordClass('unknown') creates.
     */
    private function ensureUnknownClass(int $languageId): int
    {
        return (int) WordClass::query()
            ->firstOrCreate(
                ['language_id' => $languageId, 'slug' => 'unknown'],
                ['title' => 'unknown'],
            )->id;
    }
}
