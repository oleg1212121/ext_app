<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reuses a completed alignment for exact-copy entity pairs (ADR 0033).
 *
 * When a new match X↔Y is created and some completed match E1↔E2 exists where
 * E1's text hash and language equal X's and E2's equal Y's (in either
 * orientation), the alignment is cloned instead of recomputed: meaning matches
 * (order, similarity, human-edit landmarks) and their junctions are copied
 * with a positional sentence mapping — the Nth sentence of a copy corresponds
 * to the Nth sentence of its source, by construction of the text hash. A
 * whole-book alignment that would run for half an hour becomes a bulk insert.
 *
 * Any structural mismatch (sentence counts, an unmappable junction) aborts the
 * copy and the caller falls back to the full alignment pipeline. No record of
 * the source is kept on the new match — copies are independent.
 */
class AlignmentCopyService
{
    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly EntityTextHasher $hasher = new EntityTextHasher,
    ) {}

    /**
     * Try to satisfy the freshly created (pending) $match by copying an
     * existing completed alignment between an exact-copy pair. Returns true
     * when the match was completed by copy; false means the caller should run
     * the full alignment pipeline.
     */
    public function copyFor(EntityMatch $match): bool
    {
        $match->load(['aEntity', 'bEntity']);

        $aEntity = $match->aEntity;
        $bEntity = $match->bEntity;

        if ($aEntity === null || $bEntity === null) {
            return false;
        }

        // The copy decision needs current hashes; computing them here is a
        // cheap local sha256 when the stored ones are stale.
        $aHash = $this->hasher->refreshIfStale($aEntity);
        $bHash = $this->hasher->refreshIfStale($bEntity);

        if ($aHash === null || $bHash === null) {
            return false;
        }

        $source = $this->findSourceMatch($match, $aEntity, $bEntity, $aHash, $bHash);

        if ($source === null) {
            return false;
        }

        try {
            $copied = DB::transaction(
                fn (): bool => $this->copyAlignment($source, $match, $aEntity, $bEntity, $aHash),
            );
        } catch (Throwable) {
            return false;
        }

        if (! $copied) {
            return false;
        }

        $match->update([
            'status' => 'completed',
            'entity_similarity' => $source->entity_similarity,
            'a_last_sentence_offset' => $match->a_total_sentences,
            'b_last_sentence_offset' => $match->b_total_sentences,
            'error_message' => null,
            'started_at' => $match->started_at ?? now(),
            'completed_at' => now(),
        ]);

        return true;
    }

    /**
     * The best completed match whose sides' (text_hash, language) equal the
     * target pair's, in either orientation. Best = most human-confirmed rows,
     * then most linked rows, then most recently completed.
     */
    private function findSourceMatch(
        EntityMatch $match,
        Entity $aEntity,
        Entity $bEntity,
        string $aHash,
        string $bHash,
    ): ?EntityMatch {
        $forward = fn (Builder $query): Builder => $query
            ->whereHas('aEntity', fn (Builder $q): Builder => $q
                ->where('text_hash', $aHash)
                ->where('language_id', $aEntity->language_id))
            ->whereHas('bEntity', fn (Builder $q): Builder => $q
                ->where('text_hash', $bHash)
                ->where('language_id', $bEntity->language_id));

        $mirrored = fn (Builder $query): Builder => $query
            ->whereHas('aEntity', fn (Builder $q): Builder => $q
                ->where('text_hash', $bHash)
                ->where('language_id', $bEntity->language_id))
            ->whereHas('bEntity', fn (Builder $q): Builder => $q
                ->where('text_hash', $aHash)
                ->where('language_id', $aEntity->language_id));

        return EntityMatch::query()
            ->where('status', 'completed')
            ->whereKeyNot($match->id)
            ->where(fn (Builder $query): Builder => $query
                ->where($forward)
                ->orWhere($mirrored))
            ->withCount([
                'meaningMatches as confirmed_count' => fn (Builder $query): Builder => $query
                    ->whereHas('sentenceMeaningMatches'),
            ])
            ->orderByDesc('confirmed_count')
            ->orderByDesc('linked_count')
            ->orderByDesc('completed_at')
            ->first();
    }

    /**
     * Clone the source match's meaning matches and junctions onto the target
     * pair. Returns false (without writing) on any structural mismatch.
     */
    private function copyAlignment(
        EntityMatch $source,
        EntityMatch $target,
        Entity $aEntity,
        Entity $bEntity,
        string $aHash,
    ): bool {
        $forward = $source->aEntity?->text_hash === $aHash
            && $source->aEntity?->language_id === $aEntity->language_id;

        // The id mapping below assumes the inserted rows are the only rows of
        // this match; bail out for anything that already carries an alignment.
        if ($target->meaningMatches()->exists()) {
            return false;
        }

        // Positional sentence maps per source side: sentence id => position.
        $sourceA = $this->orderedSentenceIds($source->aEntity);
        $sourceB = $this->orderedSentenceIds($source->bEntity);
        $targetA = $this->orderedSentenceIds($aEntity);
        $targetB = $this->orderedSentenceIds($bEntity);

        if ($sourceA === [] && $sourceB === []) {
            return false;
        }

        // Source side letter => target side letter under this orientation.
        $sideMap = $forward ? ['a' => 'a', 'b' => 'b'] : ['a' => 'b', 'b' => 'a'];

        // Under mirroring, the source's a-side sentences map onto the target's
        // b-side sentences and vice versa.
        if (count($sourceA) !== count($sideMap['a'] === 'a' ? $targetA : $targetB)
            || count($sourceB) !== count($sideMap['b'] === 'a' ? $targetA : $targetB)) {
            return false;
        }

        // Source sentence id => target sentence id, per source side.
        $sentenceMap = [
            'a' => array_combine($sourceA, $sideMap['a'] === 'a' ? $targetA : $targetB),
            'b' => array_combine($sourceB, $sideMap['b'] === 'a' ? $targetA : $targetB),
        ];

        $source->load('meaningMatches.sentenceMeaningMatches');

        $now = now();
        $meaningRows = [];
        $junctionRows = [];
        $meaningIdMap = [];
        $copiedWithJunctions = 0;

        foreach ($source->meaningMatches as $meaningMatch) {
            $meaningRows[] = [
                'entity_match_id' => $target->id,
                'order' => $meaningMatch->order,
                'similarity' => $meaningMatch->similarity,
                'alignment_chunk' => $meaningMatch->alignment_chunk,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $meaningIdMap[$meaningMatch->id] = null;

            $hasJunctions = false;

            foreach ($meaningMatch->sentenceMeaningMatches as $junction) {
                $sourceSide = $junction->side;
                $mappedSentenceId = $sentenceMap[$sourceSide][$junction->entity_sentence_id] ?? null;

                if ($mappedSentenceId === null) {
                    return false;
                }

                $junctionRows[] = [
                    'entity_sentence_id' => $mappedSentenceId,
                    'meaning_match_id' => null, // filled after the meaning insert
                    'source_meaning_match_id' => $junction->meaning_match_id,
                    'side' => $sideMap[$sourceSide],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $hasJunctions = true;
            }

            if ($hasJunctions) {
                $copiedWithJunctions++;
            }
        }

        foreach (array_chunk($meaningRows, self::INSERT_CHUNK) as $chunk) {
            MeaningMatch::query()->insert($chunk);
        }

        // Map the inserted rows back to fresh ids by insertion order.
        $freshIds = $target->meaningMatches()
            ->orderBy('id')
            ->pluck('id');

        $sourceOrder = array_keys($meaningIdMap);
        // The pluck is ordered by id ascending, which matches insertion order
        // of the chunked insert above.
        foreach ($freshIds as $index => $freshId) {
            if (isset($sourceOrder[$index])) {
                $meaningIdMap[$sourceOrder[$index]] = $freshId;
            }
        }

        foreach ($junctionRows as &$junctionRow) {
            $junctionRow['meaning_match_id'] = $meaningIdMap[$junctionRow['source_meaning_match_id']];

            if ($junctionRow['meaning_match_id'] === null) {
                return false;
            }

            unset($junctionRow['source_meaning_match_id']);
        }
        unset($junctionRow);

        foreach (array_chunk($junctionRows, self::INSERT_CHUNK) as $chunk) {
            SentenceMeaningMatch::query()->insert($chunk);
        }

        $target->update([
            'a_total_sentences' => count($targetA),
            'b_total_sentences' => count($targetB),
            'linked_count' => count($meaningRows),
        ]);

        return true;
    }

    /**
     * @return list<int>
     */
    private function orderedSentenceIds(?Entity $entity): array
    {
        if ($entity === null) {
            return [];
        }

        return $entity->sentences()
            ->orderBy('order')
            ->pluck('id')
            ->all();
    }
}
