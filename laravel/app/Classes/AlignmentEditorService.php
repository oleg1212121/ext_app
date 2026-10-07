<?php

namespace App\Classes;

use App\Enums\Side;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The alignment-editing domain (ADR 0062): every human mutation on a
 * match's meaning rows and their sentences — row create/delete/approve,
 * sentence add/update/unlink/delete, and the move placement engine — owned
 * by one service so the invariant writes (syncLinkedCount, syncTotals,
 * HUMAN_CHUNK, two-phase order parking) live in exactly one place. The HTTP
 * controller keeps access gates, validation, 404/422 mapping, and payload
 * shaping; the aligner pipeline has its own write path (MeaningMatchStore)
 * and does not come through here.
 */
class AlignmentEditorService
{
    public function __construct(
        private readonly SparseOrderService $sparseOrder,
        private readonly SentenceOrderService $sentenceOrder,
    ) {}

    // ─── rows ────────────────────────────────────────────────────────────────

    /**
     * Create a human-made meaning row, inserted after $afterRowId (null =
     * after the last row, or first for an empty match).
     */
    public function createRow(EntityMatch $entityMatch, ?int $afterRowId): MeaningMatch
    {
        $meaningMatch = DB::transaction(function () use ($entityMatch, $afterRowId): MeaningMatch {
            $rows = MeaningMatch::query()
                ->where('entity_match_id', $entityMatch->id)
                ->orderBy('order')
                ->get(['id', 'order']);

            $items = $rows
                ->map(fn (MeaningMatch $row): array => ['key' => 'mm-'.$row->id, 'order' => (int) $row->order])
                ->values()
                ->all();

            $afterOrder = $afterRowId !== null
                ? (int) $rows->firstWhere('id', $afterRowId)->order
                : ($rows->last()?->order ?? SparseOrderService::BEGINNING_SENTINEL);

            $result = $this->sparseOrder->orderForInsertAfter($items, null, $afterOrder);

            $this->persistRowOrderChanges($rows, $result['items']);

            return MeaningMatch::query()->create([
                'entity_match_id' => $entityMatch->id,
                'order' => $result['order'],
                'similarity' => 1.0,
                'alignment_chunk' => MeaningMatch::HUMAN_CHUNK,
            ]);
        });

        $entityMatch->syncLinkedCount();

        return $meaningMatch;
    }

    /**
     * Delete a row and its junctions; the junctioned sentences return to the
     * unmatched pool. Returns the sides whose pools changed.
     *
     * @return list<'a'|'b'>
     */
    public function deleteRow(EntityMatch $entityMatch, MeaningMatch $meaningMatch): array
    {
        $unmatchedChanged = [];

        DB::transaction(function () use ($entityMatch, $meaningMatch, &$unmatchedChanged): void {
            foreach (Side::cases() as $side) {
                if ($meaningMatch->sideSentenceMeaningMatches($side->value)->exists()) {
                    $unmatchedChanged[] = $side->value;
                }
            }

            $meaningMatch->sentenceMeaningMatches()->delete();
            $meaningMatch->delete();

            $entityMatch->syncLinkedCount();
        });

        return $unmatchedChanged;
    }

    /**
     * Approve a row: human-confirmed, so it becomes a hard landmark a future
     * Re-align preserves (similarity 1.0 + the HUMAN_CHUNK sentinel).
     */
    public function approveRow(MeaningMatch $meaningMatch): void
    {
        $meaningMatch->update(['similarity' => 1.0, 'alignment_chunk' => MeaningMatch::HUMAN_CHUNK]);
    }

    /**
     * Reject a row: similarity drops to 0 only. The chunk sentinel is left
     * untouched on purpose — a rejection is a number, not a permanent human
     * verdict, so a later Re-align may re-pair or overwrite the row. The row
     * stays in the needs-review list (similarity below the threshold).
     */
    public function rejectRow(MeaningMatch $meaningMatch): void
    {
        $meaningMatch->update(['similarity' => 0.0]);
    }

    // ─── sentences ───────────────────────────────────────────────────────────

    /**
     * Create a new sentence on one side and link it into the row, ordered
     * after the row's side content (see sideAnchorOrder).
     */
    public function addSentence(EntityMatch $entityMatch, MeaningMatch $meaningMatch, Side $side, string $content): EntitySentence
    {
        return DB::transaction(function () use ($entityMatch, $side, $content, $meaningMatch): EntitySentence {
            $entityId = $entityMatch->entityIdFor($side);

            $anchor = $this->sideAnchorOrder($entityMatch, $side, $meaningMatch);

            $order = $this->placeSideSentence($entityMatch, $side, null, $anchor);

            $sentenceTypeId = SentenceType::query()->where('name', 'sentence')->value('id');

            $sentence = EntitySentence::query()->create([
                'entity_id' => $entityId,
                'sentence_type_id' => $sentenceTypeId,
                'content' => $content,
                'order' => $order,
            ]);

            SentenceMeaningMatch::query()->create([
                'entity_sentence_id' => $sentence->id,
                'meaning_match_id' => $meaningMatch->id,
                'side' => $side->value,
            ]);

            $meaningMatch->update(['similarity' => 1.0]);

            $entityMatch->syncTotals();

            return $sentence;
        });
    }

    /**
     * Drag a sentence to a new position: within a row, across rows, or
     * to/from the unmatched pool ($toRowId null). The drop position wins —
     * the sentence's document order is renumbered so it sorts exactly where
     * it was dropped (see placeSideSentence). Returns the ids of the rows
     * whose payloads changed.
     *
     * @return list<int>
     */
    public function moveSentence(EntityMatch $entityMatch, Side $side, int $sentenceId, ?int $toRowId, int $index): array
    {
        $affectedRowIds = [];

        DB::transaction(function () use ($entityMatch, $side, $sentenceId, $toRowId, $index, &$affectedRowIds): void {
            $layout = $this->sideLayout($entityMatch, $side);
            $fromRowId = $layout['sentences'][$sentenceId]['row_id'] ?? null;

            if ($fromRowId === $toRowId) {
                if ($toRowId === null) {
                    return;
                }

                $current = $layout['rows'][$toRowId]['ids'];
                $remaining = array_values(array_filter($current, fn (int $id): bool => $id !== $sentenceId));
                $seq = $remaining;
                array_splice($seq, $index, 0, [$sentenceId]);

                if ($seq === $current) {
                    return;
                }

                $this->placeMovedWithinRow($entityMatch, $side, $seq, $sentenceId, $layout);
                $affectedRowIds[] = $toRowId;

                return;
            }

            if ($fromRowId !== null) {
                $this->unlink($entityMatch, $side, $sentenceId, $fromRowId);
                $affectedRowIds[] = $fromRowId;
            }

            if ($toRowId !== null) {
                $this->link($side, $sentenceId, $toRowId);
                $this->placeSentenceAt($entityMatch, $side, $sentenceId, $toRowId, $index);
                $affectedRowIds[] = $toRowId;
            }
        });

        return $affectedRowIds;
    }

    /**
     * Remove a sentence's junction; it returns to the unmatched pool.
     */
    public function unlinkSentence(EntityMatch $entityMatch, Side $side, int $sentenceId, int $rowId): void
    {
        DB::transaction(function () use ($entityMatch, $side, $sentenceId, $rowId): void {
            $this->unlink($entityMatch, $side, $sentenceId, $rowId);
        });
    }

    /**
     * Edit a sentence's content — the editor's one content write. A plain
     * model write on purpose: per ADR 0062 the editor never flips a match
     * stale and never completes it (only Re-align / Run from scratch / a
     * sentence re-import do), so this deliberately stays out of the
     * EntitySentenceStore mutation flow. The updated model event bumps
     * sentences_updated_at, so the text hash follows the new content
     * (ADR 0033).
     */
    public function updateSentenceContent(EntitySentence $sentence, string $content): EntitySentence
    {
        $sentence->update(['content' => $content]);

        return $sentence->refresh();
    }

    /**
     * Hard-delete an unmatched sentence (linked sentences must be unlinked
     * first — the controller refuses those). The totals are the aligner's
     * cursor space — image-less sentences only (ADR 0050), matching
     * AlignEntitySentences.
     */
    public function deleteUnmatchedSentence(EntityMatch $entityMatch, EntitySentence $sentence): void
    {
        DB::transaction(function () use ($entityMatch, $sentence): void {
            $sentence->delete();

            $entityMatch->syncTotals();
        });
    }

    // ─── lookups ─────────────────────────────────────────────────────────────

    /**
     * The side's sentence, or null when it does not exist or belongs to the
     * other side's entity.
     */
    public function findSideSentence(EntityMatch $entityMatch, Side $side, int $sentenceId): ?EntitySentence
    {
        return EntitySentence::query()
            ->whereKey($sentenceId)
            ->where('entity_id', $entityMatch->entityIdFor($side))
            ->first();
    }

    /**
     * The row the sentence is junctioned into on this match, or null when it
     * is unmatched.
     */
    public function rowIdOfSentence(EntityMatch $entityMatch, Side $side, int $sentenceId): ?int
    {
        // Scoped to this match: the sentence may also be junctioned in other
        // matches of the same entity, and an unscoped first() would return a
        // row of a different alignment.
        $junction = SentenceMeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->where('entity_sentence_id', $sentenceId)
            ->orderBy('id')
            ->first();

        return $junction !== null ? (int) $junction->meaning_match_id : null;
    }

    // ─── placement engine ────────────────────────────────────────────────────

    /**
     * Renumber a moved sentence so it sorts at the drop index within its
     * destination row: the order is picked from the side's global document
     * order — after the preceding row sentence, or before the row's first
     * sentence — so it can never collide with an interleaved sentence's order
     * and rebalances the neighborhood when the surrounding gap is exhausted.
     *
     * @param  list<int>  $seq  the row's sentence ids in intended order
     * @param  array{
     *     sentences: array<int, array{order: int, row_id: ?int}>,
     *     rows: array<int, array{order: int, ids: list<int>}}>
     * }  $layout
     */
    private function placeMovedWithinRow(EntityMatch $entityMatch, Side $side, array $seq, int $movedId, array $layout): void
    {
        $insertIndex = array_search($movedId, $seq, true);
        $remaining = array_values(array_filter($seq, fn (int $id): bool => $id !== $movedId));

        $prevId = $insertIndex > 0 ? $remaining[$insertIndex - 1] : null;
        $nextId = $insertIndex < count($remaining) ? $remaining[$insertIndex] : null;

        if ($prevId === null && $nextId === null) {
            return;
        }

        $afterOrder = $prevId !== null
            ? (int) $layout['sentences'][$prevId]['order']
            : $this->predecessorOrderBelow($layout, (int) $layout['sentences'][$nextId]['order']);

        $this->placeSideSentence($entityMatch, $side, $movedId, $afterOrder);
    }

    /**
     * Place a sentence junctioned into a row holding no other sentences on
     * this side: anchored after the closest populated row below (or before
     * the closest one above) so the global numbering stays monotonic with
     * row order, again from the side's global document order so the result
     * cannot collide with an interleaved sentence.
     *
     * @param  array{
     *     sentences: array<int, array{order: int, row_id: ?int}>,
     *     rows: array<int, array{order: int, ids: list<int>}}>
     * }  $layout
     */
    private function placeIntoEmptyRow(EntityMatch $entityMatch, Side $side, int $sentenceId, array $layout, int $rowId): void
    {
        $rowOrder = $layout['rows'][$rowId]['order'];

        $low = null;
        $high = null;

        foreach ($layout['rows'] as $id => $row) {
            if ($id === $rowId || $row['ids'] === []) {
                continue;
            }

            if ($row['order'] < $rowOrder) {
                $low = $this->rowRightBoundary($layout, $row['ids']);
            }

            if ($high === null && $row['order'] > $rowOrder) {
                $high = $this->rowLeftBoundary($layout, $row['ids']);
            }
        }

        if ($low !== null) {
            $afterOrder = $low;
        } elseif ($high !== null) {
            $afterOrder = $this->predecessorOrderBelow($layout, $high);
        } else {
            $afterOrder = SparseOrderService::BEGINNING_SENTINEL;
        }

        $this->placeSideSentence($entityMatch, $side, $sentenceId, $afterOrder);
    }

    /**
     * Compute a collision-free order for a sentence placed after $afterOrder
     * in the side's global document order. The neighbourhood rebalance, the
     * non-negative shift, the two-phase persistence and the
     * sentences_updated_at bump (document order feeds the text hash) live on
     * SentenceOrderService.
     *
     * @return int the order assigned to the placed sentence
     */
    private function placeSideSentence(EntityMatch $entityMatch, Side $side, ?int $sentenceId, int $afterOrder): int
    {
        return $this->sentenceOrder->placeAfterOrder($entityMatch->entityIdFor($side), $afterOrder, $sentenceId);
    }

    /**
     * Largest sentence order strictly below $upperBound, or the beginning
     * sentinel when nothing sorts below it.
     *
     * @param  array{sentences: array<int, array{order: int, row_id: ?int}>}  $layout
     */
    private function predecessorOrderBelow(array $layout, int $upperBound): int
    {
        $predecessor = SparseOrderService::BEGINNING_SENTINEL;

        foreach ($layout['sentences'] as $info) {
            if ($info['order'] < $upperBound) {
                $predecessor = max($predecessor, $info['order']);
            }
        }

        return $predecessor;
    }

    /**
     * Renumber a freshly linked sentence so it sorts at the drop index within
     * its destination row, bounded by the document orders of the surrounding
     * rows so the global numbering stays monotonic with row order.
     */
    private function placeSentenceAt(EntityMatch $entityMatch, Side $side, int $sentenceId, int $rowId, int $index): void
    {
        $layout = $this->sideLayout($entityMatch, $side);

        $current = array_values(array_filter(
            $layout['rows'][$rowId]['ids'],
            fn (int $id): bool => $id !== $sentenceId,
        ));

        if ($current === []) {
            $this->placeIntoEmptyRow($entityMatch, $side, $sentenceId, $layout, $rowId);

            return;
        }

        $seq = $current;
        array_splice($seq, $index, 0, [$sentenceId]);

        $this->placeMovedWithinRow($entityMatch, $side, $seq, $sentenceId, $layout);
    }

    /**
     * Every sentence of the side's entity with its owning row (null for
     * unmatched), plus every row with its side sentences in document order —
     * the map the placement engine reasons over.
     *
     * @return array{
     *     sentences: array<int, array{order: int, row_id: ?int}>,
     *     rows: array<int, array{order: int, ids: list<int>}}>
     * }
     */
    private function sideLayout(EntityMatch $entityMatch, Side $side): array
    {
        $entityId = $entityMatch->entityIdFor($side);

        $sentences = EntitySentence::query()
            ->where('entity_id', $entityId)
            ->get(['id', 'order']);

        $layout = [
            'sentences' => [],
            'rows' => [],
        ];

        foreach ($sentences as $sentence) {
            $layout['sentences'][$sentence->id] = [
                'order' => (int) $sentence->order,
                'row_id' => null,
            ];
        }

        $rows = MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->with(['sentenceMeaningMatches' => fn ($query) => $query->where('side', $side->value)])
            ->orderBy('order')
            ->get();

        $allSentenceIds = $rows
            ->flatMap(fn (MeaningMatch $row) => $row->sentenceMeaningMatches->pluck('entity_sentence_id'))
            ->unique()
            ->values()
            ->all();

        $orderedSentenceIds = $allSentenceIds !== []
            ? EntitySentence::query()
                ->whereIn('id', $allSentenceIds)
                ->orderBy('order')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all()
            : [];

        $idOrder = array_flip($orderedSentenceIds);

        foreach ($rows as $row) {
            $junctions = $row->sentenceMeaningMatches;

            if ($junctions->isEmpty()) {
                continue;
            }

            $ids = $junctions
                ->pluck('entity_sentence_id')
                ->values()
                ->all();

            usort($ids, fn (int $a, int $b): int => ($idOrder[$a] ?? 0) <=> ($idOrder[$b] ?? 0));

            foreach ($ids as $id) {
                $layout['sentences'][$id]['row_id'] = $row->id;
            }

            $layout['rows'][$row->id] = [
                'order' => (int) $row->order,
                'ids' => $ids,
            ];
        }

        return $layout;
    }

    /**
     * The order a new sentence into the row is anchored after: the row's own
     * right boundary when it holds side sentences, else the closest populated
     * row below's boundary, clamped below the next populated row and the
     * row's own order.
     */
    private function sideAnchorOrder(EntityMatch $entityMatch, Side $side, MeaningMatch $meaningMatch): int
    {
        $layout = $this->sideLayout($entityMatch, $side);
        $currentRowOrder = (int) $meaningMatch->order;

        if (isset($layout['rows'][$meaningMatch->id]) && $layout['rows'][$meaningMatch->id]['ids'] !== []) {
            return $this->rowRightBoundary($layout, $layout['rows'][$meaningMatch->id]['ids']);
        }

        $anchor = null;

        foreach ($layout['rows'] as $row) {
            if ($row['order'] < $currentRowOrder && $row['ids'] !== []) {
                $anchor = $this->rowRightBoundary($layout, $row['ids']);
            }
        }

        if ($anchor !== null) {
            $nextRowBoundary = null;

            foreach ($layout['rows'] as $row) {
                if ($row['order'] > $currentRowOrder && $row['ids'] !== []) {
                    $nextRowBoundary = $this->rowLeftBoundary($layout, $row['ids']);
                    break;
                }
            }

            if ($nextRowBoundary !== null && $anchor >= $nextRowBoundary) {
                $anchor = $nextRowBoundary - 1;
            }

            if ($anchor >= $currentRowOrder) {
                $anchor = $currentRowOrder - 1;
            }

            return $anchor;
        }

        $allOrders = array_column($layout['sentences'], 'order');

        return $allOrders !== [] ? max((int) min($allOrders), 0) : 0;
    }

    /**
     * @param  array{
     *     sentences: array<int, array{order: int, row_id: ?int}>,
     *     rows: array<int, array{order: int, ids: list<int>}}>
     * }  $layout
     * @param  list<int>  $ids
     */
    private function rowRightBoundary(array $layout, array $ids): int
    {
        return max(array_map(fn (int $id): int => $layout['sentences'][$id]['order'], $ids));
    }

    /**
     * @param  array{
     *     sentences: array<int, array{order: int, row_id: ?int}>,
     *     rows: array<int, array{order: int, ids: list<int>}}>
     * }  $layout
     * @param  list<int>  $ids
     */
    private function rowLeftBoundary(array $layout, array $ids): int
    {
        return min(array_map(fn (int $id): int => $layout['sentences'][$id]['order'], $ids));
    }

    private function link(Side $side, int $sentenceId, int $rowId): void
    {
        SentenceMeaningMatch::query()->create([
            'entity_sentence_id' => $sentenceId,
            'meaning_match_id' => $rowId,
            'side' => $side->value,
        ]);

        MeaningMatch::query()->whereKey($rowId)->update(['similarity' => 1.0]);
    }

    private function unlink(EntityMatch $entityMatch, Side $side, int $sentenceId, int $rowId): void
    {
        SentenceMeaningMatch::query()
            ->where('entity_sentence_id', $sentenceId)
            ->where('meaning_match_id', $rowId)
            ->delete();

        MeaningMatch::query()->whereKey($rowId)->update(['similarity' => 1.0]);
    }

    /**
     * @param  Collection<int, MeaningMatch>  $rows
     * @param  list<array{key: string, order: int}>  $items
     */
    private function persistRowOrderChanges($rows, array $items): void
    {
        $currentOrders = $rows->keyBy('id')->map(fn (MeaningMatch $row): int => (int) $row->order);

        $updates = [];

        foreach ($items as $item) {
            $id = (int) substr($item['key'], 3);

            if (($currentOrders->get($id) ?? null) !== $item['order']) {
                $updates[] = ['id' => $id, 'order' => $item['order']];
            }
        }

        app(SparseOrderService::class)->persistOrdersTwoPhase(MeaningMatch::class, $updates);
    }
}
