<?php

namespace App\Classes;

use App\Models\Form;
use App\Models\Word;
use App\Models\WordTranslation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The word's completeness — how much is known about it, stored on
 * `words.completeness` as a 0–7 score (popup-ai-enrich spec decision 3):
 *
 * | points | what                                             |
 * |--------|--------------------------------------------------|
 * | 2      | ≥ 1 linked translation (`word_translations` row) |
 * | 1      | definitions exist                                |
 * | 1      | transcriptions exist                             |
 * | 1      | examples exist                                   |
 * | 1      | etymologies exist                                |
 * | 1      | a real frequency rank (0 < rank < the unranked
 *          sentinel — 0 is the "never ranked" column default,
 *          `Word::FREQUENCY_UNRANKED` the explicit unranked
 *          stamp; ADR 0041's fold guard uses the same window) |
 *
 * The score never depends on how many rows a kind has — existence only.
 * `words:recalc-completeness` computes the same matrix set-based in SQL;
 * its command test pins that mirror to this matrix.
 */
class WordCompleteness
{
    public const MAX = 7;

    /**
     * The matrix, over the word's loaded content relations. Callers that
     * know the translation-link state pass it in (the popup family resolves
     * it with one pivot query, the backfill with a SQL EXISTS); without the
     * argument the word's translation-link relations are consulted.
     */
    public static function score(Word $word, ?bool $hasLinkedTranslations = null): int
    {
        $score = 0;

        $score += ($hasLinkedTranslations ?? self::hasLinkedTranslations($word)) ? 2 : 0;
        $score += $word->definitions->isNotEmpty() ? 1 : 0;
        $score += $word->transcriptions->isNotEmpty() ? 1 : 0;
        $score += $word->examples->isNotEmpty() ? 1 : 0;
        $score += $word->etymologies->isNotEmpty() ? 1 : 0;
        $score += self::hasRealFrequencyRank($word) ? 1 : 0;

        return $score;
    }

    /**
     * A frequency rank that stands for a real list position: below the
     * unranked sentinel and not the 0 "never ranked" column default.
     */
    public static function hasRealFrequencyRank(Word $word): bool
    {
        $frequency = (float) $word->frequency;

        return $frequency > 0 && $frequency < Word::FREQUENCY_UNRANKED;
    }

    private static function hasLinkedTranslations(Word $word): bool
    {
        if ($word->relationLoaded('translationLinks') && $word->relationLoaded('translationLinksAsB')) {
            return $word->translationLinks->isNotEmpty() || $word->translationLinksAsB->isNotEmpty();
        }

        return self::linksExist(collect([$word]));
    }

    /**
     * Whether ANY of the words carries a `word_translations` link.
     *
     * @param  Collection<int, Word>  $words
     */
    public static function linksExist(Collection $words): bool
    {
        $ids = $words->pluck('id')->all();

        if ($ids === []) {
            return false;
        }

        return WordTranslation::query()
            ->where(fn ($query) => $query
                ->whereIn('word_a_id', $ids)
                ->orWhereIn('word_b_id', $ids))
            ->exists();
    }

    /**
     * Lazy staleness recalc for the popup's word family: a word whose
     * `completeness_at` is missing or older than its own `updated_at` or the
     * newest stamp of its content children (definitions/transcriptions/
     * examples/etymologies/forms — translation links included, they carry 2
     * of the 7 points) gets recomputed and persisted; fresh words are left
     * untouched. The passed-in models have their in-memory completeness
     * attributes refreshed either way, so ordering always sees an int.
     *
     * Content-child stamps ride the caller's eager-loaded relations; forms
     * (not eager-loaded) and the translation pivot are aggregated with one
     * query each per family.
     *
     * @param  Collection<int, Word>  $words
     * @return Collection<int, Word>
     */
    public static function recalcFamily(Collection $words): Collection
    {
        if ($words->isEmpty()) {
            return $words;
        }

        $ids = $words->pluck('id')->all();

        $linkStamps = [];
        WordTranslation::query()
            ->where(fn ($query) => $query
                ->whereIn('word_a_id', $ids)
                ->orWhereIn('word_b_id', $ids))
            ->get(['word_a_id', 'word_b_id', 'created_at', 'updated_at'])
            ->each(function (WordTranslation $link) use (&$linkStamps): void {
                $stamp = $link->updated_at->max($link->created_at);
                foreach ([$link->word_a_id, $link->word_b_id] as $wordId) {
                    $linkStamps[$wordId] = isset($linkStamps[$wordId])
                        ? $linkStamps[$wordId]->max($stamp)
                        : $stamp;
                }
            });

        $formStamps = Form::query()
            ->whereIn('word_id', $ids)
            ->groupBy('word_id')
            ->selectRaw('word_id, greatest(max(created_at), max(updated_at)) as content_stamp')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->word_id => new Carbon($row->content_stamp)]);

        foreach ($words as $word) {
            $threshold = self::contentStamp($word, $formStamps[$word->getKey()] ?? null, $linkStamps[$word->getKey()] ?? null);
            if (! self::isStale($word, $threshold)) {
                $word->completeness = (int) $word->completeness;

                continue;
            }

            $score = self::score($word, array_key_exists($word->getKey(), $linkStamps));
            $stamp = now();
            Word::query()->whereKey($word->getKey())->update([
                'completeness' => $score,
                'completeness_at' => $stamp,
            ]);
            $word->forceFill(['completeness' => $score, 'completeness_at' => $stamp]);
        }

        return $words;
    }

    /**
     * The newest timestamp the word's content ever carried — anything written
     * after the score's stamp makes it stale.
     */
    private static function contentStamp(Word $word, ?Carbon $formStamp, ?Carbon $linkStamp): Carbon
    {
        $threshold = $word->updated_at ?? now();

        foreach (['definitions', 'transcriptions', 'examples', 'etymologies'] as $relation) {
            $word->{$relation}->each(function ($child) use (&$threshold): void {
                $threshold = $threshold
                    ->max($child->updated_at)
                    ->max($child->created_at);
            });
        }

        return $threshold
            ->max($formStamp)
            ->max($linkStamp);
    }

    /**
     * Missing score, or a score older than any content stamp. Second-precision
     * comparison — the database writes these columns at timestamp(0).
     */
    private static function isStale(Word $word, Carbon $threshold): bool
    {
        if ($word->completeness === null || $word->completeness_at === null) {
            return true;
        }

        return $word->completeness_at->getTimestamp() < $threshold->getTimestamp();
    }
}
