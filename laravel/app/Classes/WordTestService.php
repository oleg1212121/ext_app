<?php

namespace App\Classes;

use App\Models\Language;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Word test: draws a fresh sample of headwords across the language's
 * ranked inventory, scores the checked ones on the 0-20000 rank scale, and
 * bulk-marks the presumed-known range as raise-only familiarity (ADR 0071).
 */
class WordTestService
{
    /**
     * Highest frequency rank the test considers (lower = more common); the
     * unranked marker (1 100 000) is excluded by the same comparison.
     */
    public const MAX_RANK = 20000;

    /** Equal-count buckets the ranked inventory is cut into. */
    public const BUCKET_COUNT = 20;

    /** Headwords drawn per test, spread proportionally over the buckets. */
    public const SAMPLE_SIZE = 50;

    /** Score credit a fully-known bucket contributes (BUCKET_COUNT of these = 20 000). */
    public const BUCKET_CREDIT = 1000;

    /** Below this many ranked headwords a language has nothing to test. */
    public const MIN_HEADWORDS = 100;

    private const CACHE_PREFIX = 'word-test:sample:';

    private const CACHE_TTL_HOURS = 24;

    /**
     * Draw a fresh sample for the language. The ranked headwords (one row per
     * l_word, its most common word class) are cut into equal-count buckets and
     * SAMPLE_SIZE headwords are drawn proportionally; the server keeps the
     * bucket layout in the cache so the submit can be scored without trusting
     * the client. Returns null when the language has too few ranked words.
     *
     * @return array{token: string, words: array<int, array{id: int, word: string}}|null
     */
    public function sample(Language $language): ?array
    {
        $headwords = $this->rankedHeadwords($language->id);

        if (count($headwords) < self::MIN_HEADWORDS) {
            return null;
        }

        $buckets = [];
        $drawnRows = [];
        $count = count($headwords);

        for ($bucket = 0; $bucket < self::BUCKET_COUNT; $bucket++) {
            $start = intdiv($count * $bucket, self::BUCKET_COUNT);
            $end = intdiv($count * ($bucket + 1), self::BUCKET_COUNT);
            $slice = array_slice($headwords, $start, $end - $start);

            // Round-based sizes sum to SAMPLE_SIZE exactly (3,2,3,2,... over 20 buckets).
            $size = (int) round(self::SAMPLE_SIZE * ($bucket + 1) / self::BUCKET_COUNT)
                - (int) round(self::SAMPLE_SIZE * $bucket / self::BUCKET_COUNT);

            $picked = $this->draw($slice, $size);
            $buckets[] = array_map(static fn (array $row): int => $row['id'], $picked);
            $drawnRows = array_merge($drawnRows, $picked);
        }

        $token = (string) Str::uuid();

        Cache::put(self::CACHE_PREFIX.$token, [
            'language_id' => $language->id,
            'buckets' => $buckets,
            'all_word_ids' => array_merge(...$buckets),
        ], now()->addHours(self::CACHE_TTL_HOURS));

        // The page never shows rank order — a flat shuffle hides the rarity.
        $words = collect($drawnRows)
            ->map(static fn (array $row): array => ['id' => $row['id'], 'word' => $row['word']])
            ->shuffle()
            ->values()
            ->all();

        return ['token' => $token, 'words' => $words];
    }

    /**
     * The cached sample a submit token resolves to (null when unknown or expired).
     *
     * @return array{language_id: int, buckets: array<int, array<int, int>>, all_word_ids: array<int, int>}|null
     */
    public function resolveSample(string $token): ?array
    {
        return Cache::get(self::CACHE_PREFIX.$token);
    }

    /**
     * Score a checked-word list against a sample payload: each bucket
     * contributes BUCKET_CREDIT × the known share of its drawn words, so the
     * sum lands back on the 0-20000 rank scale. Pure over the payload.
     *
     * @param  array{buckets: array<int, array<int, int>>}  $payload
     * @param  array<int, int>  $knownWordIds
     */
    public function score(array $payload, array $knownWordIds): int
    {
        $known = array_flip(array_map('intval', $knownWordIds));
        $score = 0;

        foreach ($payload['buckets'] as $wordIds) {
            $size = count($wordIds);

            if ($size === 0) {
                continue;
            }

            $knownCount = count(array_intersect_key($known, array_flip($wordIds)));
            $score += (int) round(self::BUCKET_CREDIT * $knownCount / $size);
        }

        return min($score, self::BUCKET_COUNT * self::BUCKET_CREDIT);
    }

    /**
     * Raise-only bulk marking: every word of the language at rank <= score
     * gets the presumed-known baseline unless the user already knows it
     * better. One set-based statement — a placement run marks thousands of
     * rows, and Postgres has no builder-native upsert-select.
     *
     * @return int the number of user_word rows inserted or raised
     */
    public function mark(int $userId, int $languageId, int $score): int
    {
        if ($score <= 0) {
            return 0;
        }

        return DB::affectingStatement(
            'insert into user_word (user_id, word_id, familiarity, created_at, updated_at) '
            .'select ?, words.id, ?, now(), now() from words '
            .'where words.language_id = ? and words.frequency <= ? '
            .'on conflict (user_id, word_id) do update '
            .'set familiarity = greatest(user_word.familiarity, excluded.familiarity), '
            .'updated_at = now()',
            [$userId, UserWord::PLACEMENT_BASELINE, $languageId, $score],
        );
    }

    /**
     * Ranked headwords of the language in frequency order: distinct on
     * l_word keeps each headword's most common word-class row. The decimal
     * rank is cast to float — the decimal wire format sorts as a string.
     *
     * @return array<int, array{id: int, word: string}>
     */
    private function rankedHeadwords(int $languageId): array
    {
        return Word::query()
            ->selectRaw('distinct on (words.l_word) words.id, words.word, words.l_word, words.frequency::float8 as frequency')
            ->where('language_id', $languageId)
            ->where('frequency', '<=', self::MAX_RANK)
            ->whereNotNull('l_word')
            ->orderBy('l_word')
            ->orderBy('words.frequency')
            ->orderBy('words.id')
            ->toBase()
            ->get()
            ->sortBy('frequency')
            ->values()
            ->map(static fn ($row): array => ['id' => (int) $row->id, 'word' => $row->word])
            ->all();
    }

    /**
     * Draw $size random rows from a bucket slice (all of it when smaller).
     *
     * @param  array<int, array{id: int, word: string}>  $rows
     * @return array<int, array{id: int, word: string}>
     */
    private function draw(array $rows, int $size): array
    {
        if ($size <= 0 || $rows === []) {
            return [];
        }

        if (count($rows) <= $size) {
            return array_values($rows);
        }

        shuffle($rows);

        return array_slice($rows, 0, $size);
    }
}
