<?php

namespace App\Classes;

use App\Models\Language;
use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Word test: draws a fresh sample of headwords across the language's
 * CEFR tag groups (a1-c2), scores the checked ones as a band-credit sum on
 * the 0-20000 scale, and bulk-marks the presumed-known range as raise-only
 * familiarity (ADR 0071). Retakes adapt: a stored level skips the bands
 * below it — their floor anchor becomes the base, and the words spread
 * evenly over the bands at or above the level.
 */
class WordTestService
{
    /**
     * The CEFR band anchors on the 0-20000 scale, ascending. A band's width
     * is its anchor minus the previous one (a1's band = its own anchor);
     * the widths sum to exactly 20 000.
     */
    public const CEFR_ANCHORS = ['a1' => 1000, 'a2' => 3000, 'b1' => 6000, 'b2' => 10000, 'c1' => 15000, 'c2' => 20000];

    /** Headwords drawn per test, redistributed evenly over the tested bands. */
    public const SAMPLE_SIZE = 60;

    private const CACHE_PREFIX = 'word-test:sample:';

    private const CACHE_TTL_HOURS = 24;

    /**
     * Draw a fresh sample for the language. The tested bands are those whose
     * anchor is at or above the stored level (all six for a first test),
     * each contributing SAMPLE_SIZE / tested headwords drawn from the band's
     * tagged pool minus what lower bands already claimed — one row per
     * l_word, its most common word class, so a spelling tagged in two levels
     * is drawn at most once, in the lower band that claimed it first. Tag
     * membership defines the pool, there is no frequency filter (unranked
     * words are legitimate). Any tested band without tagged headwords makes
     * the test unavailable (null). The server
     * keeps the band layout in the cache so the submit can be scored
     * without trusting the client; the page also receives the tested groups
     * for its "this retake covers ..." note.
     *
     * @return array{token: string, words: array<int, array{id: int, word: string}>, groups: array<int, string>}|null
     */
    public function sample(Language $language, int $currentLevel = 0): ?array
    {
        $tested = array_keys(array_filter(
            self::CEFR_ANCHORS,
            static fn (int $anchor): bool => $anchor >= $currentLevel,
        ));

        if ($tested === []) {
            return null; // a stored level above the scale — nothing left to test
        }

        // Anchor of the group before the lowest tested one; 0 when a1 is tested.
        $base = 0;
        $previousAnchor = 0;
        foreach (self::CEFR_ANCHORS as $slug => $anchor) {
            if ($anchor >= $currentLevel) {
                $base = $previousAnchor;

                break;
            }

            $previousAnchor = $anchor;
        }

        $quota = intdiv(self::SAMPLE_SIZE, count($tested));
        $bands = [];
        $drawnRows = [];
        $claimed = [];

        foreach ($tested as $slug) {
            $pool = $this->taggedHeadwords($language->id, $slug);

            if ($pool === []) {
                return null; // unseeded tags or a language without the lists
            }

            // Lower bands claim their spellings first: the quota draws only
            // from spellings no tested band has drawn yet.
            $fresh = array_values(array_filter(
                $pool,
                static fn (array $row): bool => ! isset($claimed[$row['l_word']]),
            ));

            $bands[$slug] = [];

            foreach ($this->draw($fresh, $quota) as $row) {
                $claimed[$row['l_word']] = true;
                $bands[$slug][] = $row['id'];
                $drawnRows[] = $row;
            }
        }

        $token = (string) Str::uuid();

        Cache::put(self::CACHE_PREFIX.$token, [
            'language_id' => $language->id,
            'base' => $base,
            'bands' => $bands,
            'all_word_ids' => array_merge(...array_values($bands)),
        ], now()->addHours(self::CACHE_TTL_HOURS));

        // The page never shows band order — a flat shuffle hides the rarity.
        $words = collect($drawnRows)
            ->map(static fn (array $row): array => ['id' => $row['id'], 'word' => $row['word']])
            ->shuffle()
            ->values()
            ->all();

        return ['token' => $token, 'words' => $words, 'groups' => $tested];
    }

    /**
     * The cached sample a submit token resolves to (null when unknown or expired).
     *
     * @return array{language_id: int, base: int, bands: array<string, array<int, int>>, all_word_ids: array<int, int>}|null
     */
    public function resolveSample(string $token): ?array
    {
        return Cache::get(self::CACHE_PREFIX.$token);
    }

    /**
     * Score a checked-word list against a sample payload: the untested lower
     * bands contribute their floor anchor as the base, and each tested band
     * contributes its width (anchor minus the previous anchor) × the known
     * share of its drawn words, so the sum lands back on the 0-20000 scale.
     * Pure over the payload.
     *
     * @param  array{base: int, bands: array<string, array<int, int>>}  $payload
     * @param  array<int, int>  $knownWordIds
     */
    public function score(array $payload, array $knownWordIds): int
    {
        $known = array_flip(array_map('intval', $knownWordIds));

        $previousAnchor = 0;
        $widths = [];
        foreach (self::CEFR_ANCHORS as $slug => $anchor) {
            $widths[$slug] = $anchor - $previousAnchor;
            $previousAnchor = $anchor;
        }

        $score = (int) $payload['base'];

        foreach ($payload['bands'] as $slug => $wordIds) {
            $size = count($wordIds);

            if ($size === 0) {
                continue;
            }

            $knownCount = count(array_intersect_key($known, array_flip($wordIds)));
            $score += (int) round($widths[$slug] * $knownCount / $size);
        }

        return min($score, self::CEFR_ANCHORS['c2']);
    }

    /**
     * Persist the run's score as the user's vocabulary level, raise-only:
     * max(old, new). A bad retake never lowers the stored level — the same
     * raise-only rule as the familiarity marking (ADR 0090).
     *
     * @return int the stored level after the raise
     */
    public function persistLevel(User $user, int $score): int
    {
        $user->vocabulary_level = max($user->vocabulary_level, $score);
        $user->save();

        return $user->vocabulary_level;
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
     * The CEFR-tagged headwords of one level: distinct on l_word keeps each
     * spelling's most common word-class row. One query per band.
     *
     * @return array<int, array{id: int, word: string, l_word: string}>
     */
    private function taggedHeadwords(int $languageId, string $slug): array
    {
        return Word::query()
            ->selectRaw('distinct on (words.l_word) words.id, words.word, words.l_word')
            ->join('tagged_words', 'tagged_words.word_id', '=', 'words.id')
            ->join('tags', 'tags.id', '=', 'tagged_words.tag_id')
            ->where('tags.slug', $slug)
            ->where('words.language_id', $languageId)
            ->whereNotNull('words.l_word')
            ->orderBy('words.l_word')
            ->orderBy('words.frequency')
            ->orderBy('words.id')
            ->toBase()
            ->get()
            ->map(static fn ($row): array => ['id' => (int) $row->id, 'word' => $row->word, 'l_word' => $row->l_word])
            ->all();
    }

    /**
     * Draw $size random rows from a band's pool (all of it when smaller).
     *
     * @param  array<int, array{id: int, word: string, l_word: string}>  $rows
     * @return array<int, array{id: int, word: string, l_word: string}>
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
