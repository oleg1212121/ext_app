<?php

namespace App\Classes;

use App\Classes\WordTranslations\WordTranslationResolver;
use App\Jobs\FetchWordTranslations;
use App\Models\Language;
use App\Models\Word;
use App\Models\WordTranslation;
use App\Models\WordTranslationFetch;

/**
 * Gatekeeper for auto-fetching word translations. The single
 * word_translation_fetches row per (word, target language) is both the
 * dedupe ledger and the exclusion list: status=empty means every provider
 * answered "no translation" and the pair is never checked again.
 */
class WordTranslationFetchService
{
    public function __construct(private readonly WordTranslationResolver $resolver) {}

    /**
     * Queue a fetch unless the pair is already covered: the word already has
     * translation links, a fetch succeeded or is in flight, the word is
     * excluded (status=empty), or a failed fetch is still under its attempt
     * cap and cooldown.
     */
    public function dispatchIfEligible(Word $word, Language $target): bool
    {
        if ((int) $word->language_id === (int) $target->id
            || ! $this->resolver->hasProvider()
            || $this->hasTranslations($word)) {
            return false;
        }

        $record = WordTranslationFetch::query()
            ->firstOrCreate(['word_id' => $word->id, 'target_language_id' => $target->id]);

        if (! $record->wasRecentlyCreated && ! $this->recordAllowsRedispatch($record)) {
            return false;
        }

        $record->forceFill(['status' => WordTranslationFetch::STATUS_PENDING])->save();

        FetchWordTranslations::dispatch($word->id, $target->id);

        return true;
    }

    private function hasTranslations(Word $word): bool
    {
        return WordTranslation::query()
            ->where(fn ($query) => $query
                ->where('word_a_id', $word->id)
                ->orWhere('word_b_id', $word->id))
            ->exists();
    }

    private function recordAllowsRedispatch(WordTranslationFetch $record): bool
    {
        // pending / succeeded / empty (the exclusion) are all covered; only
        // failed lookups may be re-dispatched, past the cooldown, under the
        // attempt cap.
        if ($record->status !== WordTranslationFetch::STATUS_FAILED) {
            return false;
        }

        return $record->attempts < WordTranslationFetch::MAX_ATTEMPTS
            && $record->last_attempted_at !== null
            && $record->last_attempted_at->copy()->addHours(WordTranslationFetch::RETRY_COOLDOWN_HOURS)->isPast();
    }
}
