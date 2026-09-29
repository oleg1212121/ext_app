<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityWord;
use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use Illuminate\Support\Collection;

/**
 * Adopts an entity's unmatchable tokens into the dictionary: tokens the
 * linker stamped unmatchable get a `words` row of their own (class
 * 'unknown'), and every entity word that still has no translation gets a
 * fetch queued. The fetch ledger decides per (word, target language)
 * whether a job is really needed — excluded words never re-check.
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
        $unknownClassId = $this->ensureUnknownClass((int) $entity->language_id);

        EntityWord::query()
            ->where('entity_id', $entity->id)
            ->whereNull('word_id')
            ->whereNotNull('unmatchable_at')
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function ($rows) use ($entity, $unknownClassId, &$adopted): void {
                foreach ($rows as $row) {
                    $word = Word::query()->firstOrCreate(
                        [
                            'word' => $row->token,
                            'language_id' => $entity->language_id,
                            'word_class_id' => $unknownClassId,
                        ],
                        ['l_word' => $row->l_word],
                    );

                    $row->forceFill(['word_id' => $word->id, 'unmatchable_at' => null])->save();
                    $adopted++;
                }
            });

        return $adopted;
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
