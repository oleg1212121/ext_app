<?php

namespace App\Classes;

use App\Models\UserWord;
use App\Models\UserWordEvent;
use Illuminate\Support\Facades\DB;

/**
 * Applies familiarity deltas to the user's word rows, deduplicated by the
 * user_word_event ledger: a (user, word, row, kind) event is only ever
 * counted once, so re-revealing the same sentence never re-credits it.
 */
class WordFamiliarityService
{
    /**
     * Add a signed delta per word (clamped to the 0-100 band) and return
     * word id => resulting familiarity for every touched word.
     *
     * @param  array<int, int>  $deltas  word id => signed delta
     * @param  bool  $onlyExisting  when true, words without a user_word row
     *                              are skipped instead of being created (crossword bonus path)
     * @return array<int, int>
     */
    public function applyDeltas(int $userId, array $deltas, bool $onlyExisting = false): array
    {
        if ($deltas === []) {
            return [];
        }

        return DB::transaction(function () use ($userId, $deltas, $onlyExisting): array {
            $wordIds = array_map(intval(...), array_keys($deltas));

            if (! $onlyExisting) {
                $existing = UserWord::query()
                    ->where('user_id', $userId)
                    ->whereIn('word_id', $wordIds)
                    ->pluck('word_id');

                $missing = collect($wordIds)->diff($existing);
                $now = now();

                if ($missing->isNotEmpty()) {
                    UserWord::query()->insertOrIgnore($missing
                        ->map(fn (int $wordId): array => [
                            'user_id' => $userId,
                            'word_id' => $wordId,
                            'familiarity' => UserWord::FAMILIARITY_MIN,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])
                        ->all());
                }
            }

            foreach ($deltas as $wordId => $delta) {
                UserWord::query()
                    ->where('user_id', $userId)
                    ->where('word_id', (int) $wordId)
                    ->update([
                        'familiarity' => DB::raw(sprintf(
                            'LEAST(%d, GREATEST(%d, user_word.familiarity + %d))',
                            UserWord::FAMILIARITY_MAX,
                            UserWord::FAMILIARITY_MIN,
                            (int) $delta,
                        )),
                        'updated_at' => now(),
                    ]);
            }

            return UserWord::query()
                ->where('user_id', $userId)
                ->whereIn('word_id', $wordIds)
                ->pluck('familiarity', 'word_id')
                ->map(fn ($familiarity): int => (int) $familiarity)
                ->all();
        });
    }

    /**
     * Apply familiarity events in order, skipping those already recorded in
     * the ledger, then return word id => resulting familiarity for every
     * word referenced by the request (deduped ones included).
     *
     * @param  list<array{row_key: string, kind: string, word_ids: list<int>}>  $events
     * @return array<int, int>
     */
    public function applyEvents(int $userId, array $events): array
    {
        $deltas = [];
        $touched = [];

        DB::transaction(function () use ($userId, $events, &$deltas, &$touched): void {
            $now = now();

            foreach ($events as $event) {
                $wordIds = collect($event['word_ids'])->map(intval(...))->unique()->values();
                if ($wordIds->isEmpty()) {
                    continue;
                }

                foreach ($wordIds as $wordId) {
                    $touched[$wordId] = true;
                }

                $seen = UserWordEvent::query()
                    ->where('user_id', $userId)
                    ->where('row_key', $event['row_key'])
                    ->where('kind', $event['kind'])
                    ->whereIn('word_id', $wordIds)
                    ->pluck('word_id');

                $fresh = $wordIds->diff($seen);
                if ($fresh->isEmpty()) {
                    continue;
                }

                UserWordEvent::query()->insertOrIgnore($fresh
                    ->map(fn (int $wordId): array => [
                        'user_id' => $userId,
                        'word_id' => $wordId,
                        'row_key' => $event['row_key'],
                        'kind' => $event['kind'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->all());

                $delta = $event['kind'] === UserWord::KIND_READ
                    ? UserWord::READ_STEP
                    : -UserWord::LOOKUP_PENALTY;

                foreach ($fresh as $wordId) {
                    $deltas[$wordId] = ($deltas[$wordId] ?? 0) + $delta;
                }
            }
        });

        $updated = $this->applyDeltas($userId, $deltas);

        // Words whose events were all deduped still get a reading so the
        // caller can recolor from the response alone.
        $missing = array_diff(array_map(intval(...), array_keys($touched)), array_keys($updated));
        if ($missing !== []) {
            $updated += UserWord::query()
                ->where('user_id', $userId)
                ->whereIn('word_id', $missing)
                ->pluck('familiarity', 'word_id')
                ->map(fn ($familiarity): int => (int) $familiarity)
                ->all();
        }

        return $updated;
    }
}
