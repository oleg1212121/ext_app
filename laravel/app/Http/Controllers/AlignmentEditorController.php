<?php

namespace App\Http\Controllers;

use App\Classes\AlignmentEditorApiPresenter;
use App\Classes\EntityAccessService;
use App\Classes\SparseOrderService;
use App\Http\Requests\AddSentenceRequest;
use App\Http\Requests\MoveSentenceRequest;
use App\Http\Requests\NeedsReviewRequest;
use App\Http\Requests\RowsRequest;
use App\Http\Requests\SentenceSideRequest;
use App\Http\Requests\StoreMeaningMatchRequest;
use App\Http\Requests\UnmatchedRequest;
use App\Http\Requests\UpdateSentenceRequest;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AlignmentEditorController extends Controller
{
    public function __construct(
        private readonly SparseOrderService $sparseOrder,
        private readonly AlignmentEditorApiPresenter $presenter,
    ) {}

    private function access(): EntityAccessService
    {
        return new EntityAccessService;
    }

    public function storeRow(EntityMatch $entityMatch, StoreMeaningMatchRequest $request): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $afterRowId = $request->validated('after_row_id');

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
                'alignment_chunk' => -1,
            ]);
        });

        $entityMatch->refresh()->update(['linked_count' => $entityMatch->meaningMatches()->count()]);

        return $this->mutationResponse($entityMatch, [$this->presenter->rowPayload($meaningMatch)]);
    }

    public function destroyRow(EntityMatch $entityMatch, MeaningMatch $meaningMatch): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        abort_unless($meaningMatch->entity_match_id === $entityMatch->id, 404);

        $unmatchedChanged = [];

        DB::transaction(function () use ($entityMatch, $meaningMatch, &$unmatchedChanged): void {
            foreach (['a', 'b'] as $side) {
                if ($meaningMatch->sideSentenceMeaningMatches($side)->exists()) {
                    $unmatchedChanged[] = $side;
                }
            }

            $meaningMatch->sentenceMeaningMatches()->delete();
            $meaningMatch->delete();

            $entityMatch->update(['linked_count' => $entityMatch->meaningMatches()->count()]);
        });

        return $this->mutationResponse(
            $entityMatch,
            [],
            [$meaningMatch->id],
            $unmatchedChanged,
        );
    }

    public function approveRow(EntityMatch $entityMatch, MeaningMatch $meaningMatch): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        abort_unless($meaningMatch->entity_match_id === $entityMatch->id, 404);

        $meaningMatch->update(['similarity' => 1.0, 'alignment_chunk' => -1]);

        return $this->mutationResponse($entityMatch, [$this->presenter->rowPayload($meaningMatch->refresh())]);
    }

    public function storeSentence(EntityMatch $entityMatch, AddSentenceRequest $request): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $side = $request->validated('side');
        $content = trim((string) $request->validated('content'));
        $meaningMatchId = (int) $request->validated('meaning_match_id');

        $meaningMatch = MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->findOrFail($meaningMatchId);

        DB::transaction(function () use ($entityMatch, $side, $content, $meaningMatch): void {
            $entityId = $this->entityId($entityMatch, $side);

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
                'side' => $side,
            ]);

            $meaningMatch->update(['similarity' => 1.0]);

            $totalColumn = $side === 'a' ? 'a_total_sentences' : 'b_total_sentences';
            $entityMatch->update([$totalColumn => EntitySentence::query()->where('entity_id', $entityId)->count()]);
        });

        return $this->mutationResponse($entityMatch, [$this->presenter->rowPayload($meaningMatch->refresh())]);
    }

    public function updateSentence(EntityMatch $entityMatch, int $sentence, UpdateSentenceRequest $request): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $side = $request->validated('side');
        $content = trim((string) $request->validated('content'));

        $sentenceModel = $this->findSideSentence($entityMatch, $side, $sentence);
        $sentenceModel->update(['content' => $content]);

        $rowId = $this->rowIdOfSentence($side, $sentenceModel->id);

        return $this->mutationResponse($entityMatch, $this->rowPayloadsByIds($entityMatch, $rowId !== null ? [$rowId] : []));
    }

    public function unlinkSentence(EntityMatch $entityMatch, int $sentence, SentenceSideRequest $request): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $side = $request->validated('side');

        $sentenceModel = $this->findSideSentence($entityMatch, $side, $sentence);

        $rowId = $this->rowIdOfSentence($side, $sentenceModel->id);
        abort_if($rowId === null, 422, 'Sentence is not linked.');

        DB::transaction(function () use ($entityMatch, $side, $sentenceModel, $rowId): void {
            $this->unlink($entityMatch, $side, $sentenceModel->id, $rowId);
        });

        return $this->mutationResponse(
            $entityMatch,
            $this->rowPayloadsByIds($entityMatch, [$rowId]),
            [],
            [$side],
        );
    }

    public function destroyUnmatched(EntityMatch $entityMatch, int $sentence, SentenceSideRequest $request): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $side = $request->validated('side');

        $sentenceModel = $this->findSideSentence($entityMatch, $side, $sentence);

        if ($this->rowIdOfSentence($side, $sentenceModel->id) !== null) {
            abort(422, 'Linked sentences must be unlinked before deletion.');
        }

        $entityId = $this->entityId($entityMatch, $side);

        DB::transaction(function () use ($entityMatch, $sentenceModel, $side, $entityId): void {
            $sentenceModel->delete();

            $totalColumn = $side === 'a' ? 'a_total_sentences' : 'b_total_sentences';
            $entityMatch->update([$totalColumn => EntitySentence::query()->where('entity_id', $entityId)->count()]);
        });

        return $this->mutationResponse($entityMatch, [], [], [$side]);
    }

    public function moveSentence(EntityMatch $entityMatch, MoveSentenceRequest $request): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $side = $request->validated('side');
        $sentenceId = (int) $request->validated('sentence_id');
        $toRowId = $request->validated('to_row_id');
        $index = (int) $request->validated('index');

        $this->findSideSentence($entityMatch, $side, $sentenceId);

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

        return $this->mutationResponse(
            $entityMatch,
            $this->rowPayloadsByIds($entityMatch, $affectedRowIds),
            [],
            [$side],
        );
    }

    /**
     * Renumber a moved sentence so it sorts at the drop index within its
     * destination row: the order is picked from the side's global document
     * order — after the preceding row sentence, or before the row's first
     * sentence — so it can never collide with an interleaved sentence's order
     * and rebalances the neighborhood when the surrounding gap is exhausted.
     *
     * @param  'a'|'b'  $side
     * @param  list<int>  $seq  the row's sentence ids in intended order
     * @param  array{
     *     sentences: array<int, array{order: int, row_id: ?int}>,
     *     rows: array<int, array{order: int, ids: list<int>}}>
     * }  $layout
     */
    private function placeMovedWithinRow(EntityMatch $entityMatch, string $side, array $seq, int $movedId, array $layout): void
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
     * @param  'a'|'b'  $side
     * @param  array{
     *     sentences: array<int, array{order: int, row_id: ?int}>,
     *     rows: array<int, array{order: int, ids: list<int>}}>
     * }  $layout
     */
    private function placeIntoEmptyRow(EntityMatch $entityMatch, string $side, int $sentenceId, array $layout, int $rowId): void
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
     * in the side's global document order, rebalancing neighbouring orders
     * when the surrounding gap is exhausted. Order changes from a rebalance
     * (and the placed sentence's own change) are persisted two-phase — parked
     * at unique negatives first — so the (entity_id, order) unique index never
     * sees a transient collision mid-write.
     *
     * @param  'a'|'b'  $side
     * @return int the order assigned to the placed sentence
     */
    private function placeSideSentence(EntityMatch $entityMatch, string $side, ?int $sentenceId, int $afterOrder): int
    {
        $entityId = $this->entityId($entityMatch, $side);

        $currentOrders = EntitySentence::query()
            ->where('entity_id', $entityId)
            ->get(['id', 'order'])
            ->mapWithKeys(fn ($sentence): array => [$sentence->id => (int) $sentence->order]);

        $items = $currentOrders
            ->map(fn (int $order, int $id): array => ['key' => 's-'.$id, 'order' => $order])
            ->values()
            ->all();

        $result = $this->sparseOrder->orderForInsertAfter(
            $items,
            $sentenceId !== null ? 's-'.$sentenceId : null,
            $afterOrder,
        );

        $allResultOrders = array_column($result['items'], 'order');
        $allResultOrders[] = $result['order'];
        $minOrder = min($allResultOrders);

        if ($minOrder < 0) {
            $shift = -$minOrder;
            $result['order'] += $shift;

            foreach ($result['items'] as &$item) {
                $item['order'] += $shift;
            }
            unset($item);
        }

        $updates = [];

        foreach ($result['items'] as $item) {
            $id = (int) substr($item['key'], 2);

            if (($currentOrders->get($id) ?? null) !== $item['order']) {
                $updates[] = ['id' => $id, 'order' => $item['order']];
            }
        }

        if ($sentenceId !== null && ($currentOrders->get($sentenceId) ?? null) !== $result['order']) {
            $updates[] = ['id' => $sentenceId, 'order' => $result['order']];
        }

        foreach ($updates as $update) {
            EntitySentence::query()->whereKey($update['id'])->update(['order' => -($update['id'] + 1_000_000_000)]);
        }

        foreach ($updates as $update) {
            EntitySentence::query()->whereKey($update['id'])->update(['order' => $update['order']]);
        }

        return $result['order'];
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
     *
     * @param  'a'|'b'  $side
     */
    private function placeSentenceAt(EntityMatch $entityMatch, string $side, int $sentenceId, int $rowId, int $index): void
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

    public function rows(EntityMatch $entityMatch, RowsRequest $request): JsonResponse
    {
        abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403);

        $payload = $this->presenter->rowsPagePayload($entityMatch, $request->page(), $request->perPage());

        return response()->json([
            'match' => $this->presenter->matchPayload($entityMatch->refresh()),
            'rows' => $payload['rows'],
            'meta' => $payload['meta'],
            'sentences_before' => $payload['sentences_before'],
        ]);
    }

    public function unmatched(EntityMatch $entityMatch, UnmatchedRequest $request): JsonResponse
    {
        abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403);

        return response()->json(
            $this->presenter->unmatchedPayload($entityMatch, $request->validated('side'), $request->page()),
        );
    }

    public function needsReview(EntityMatch $entityMatch, NeedsReviewRequest $request): JsonResponse
    {
        abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403);

        return response()->json(
            $this->presenter->needsReviewPagePayload($entityMatch, $request->page()),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $deletedRows
     * @param  list<'a'|'b'>  $unmatchedChanged
     */
    private function mutationResponse(EntityMatch $entityMatch, array $rows, array $deletedRows = [], array $unmatchedChanged = []): JsonResponse
    {
        return response()->json([
            'match' => $this->presenter->matchPayload($entityMatch->refresh()),
            'rows' => $rows,
            'deleted_rows' => $deletedRows,
            'unmatched_changed' => $unmatchedChanged,
        ]);
    }

    /**
     * @param  list<int>  $rowIds
     * @return list<array<string, mixed>>
     */
    private function rowPayloadsByIds(EntityMatch $entityMatch, array $rowIds): array
    {
        if ($rowIds === []) {
            return [];
        }

        return MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->whereIn('id', $rowIds)
            ->with(['sentenceMeaningMatches.entitySentence'])
            ->orderBy('order')
            ->get()
            ->map(fn (MeaningMatch $row): array => $this->presenter->rowPayload($row))
            ->values()
            ->all();
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

        if ($updates === []) {
            return;
        }

        foreach ($updates as $update) {
            MeaningMatch::query()->whereKey($update['id'])->update(['order' => -$update['id']]);
        }

        foreach ($updates as $update) {
            MeaningMatch::query()->whereKey($update['id'])->update(['order' => $update['order']]);
        }
    }

    /**
     * @param  'a'|'b'  $side
     * @return array{
     *     sentences: array<int, array{order: int, row_id: ?int}>,
     *     rows: array<int, array{order: int, ids: list<int>}}>
     * }
     */
    private function sideLayout(EntityMatch $entityMatch, string $side): array
    {
        $entityId = $this->entityId($entityMatch, $side);

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
            ->with(['sentenceMeaningMatches' => fn ($query) => $query->where('side', $side)])
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
     * @param  'a'|'b'  $side
     */
    private function sideAnchorOrder(EntityMatch $entityMatch, string $side, MeaningMatch $meaningMatch): int
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

    private function rowLeftBoundary(array $layout, array $ids): int
    {
        return min(array_map(fn (int $id): int => $layout['sentences'][$id]['order'], $ids));
    }

    /**
     * @param  'a'|'b'  $side
     */
    private function link(string $side, int $sentenceId, int $rowId): void
    {
        SentenceMeaningMatch::query()->create([
            'entity_sentence_id' => $sentenceId,
            'meaning_match_id' => $rowId,
            'side' => $side,
        ]);

        MeaningMatch::query()->whereKey($rowId)->update(['similarity' => 1.0]);
    }

    /**
     * @param  'a'|'b'  $side
     */
    private function unlink(EntityMatch $entityMatch, string $side, int $sentenceId, int $rowId): void
    {
        SentenceMeaningMatch::query()
            ->where('entity_sentence_id', $sentenceId)
            ->where('meaning_match_id', $rowId)
            ->delete();

        MeaningMatch::query()->whereKey($rowId)->update(['similarity' => 1.0]);
    }

    /**
     * @param  'a'|'b'  $side
     */
    private function rowIdOfSentence(string $side, int $sentenceId): ?int
    {
        $junction = SentenceMeaningMatch::query()
            ->where('entity_sentence_id', $sentenceId)
            ->first();

        return $junction !== null ? (int) $junction->meaning_match_id : null;
    }

    /**
     * @param  'a'|'b'  $side
     */
    private function findSideSentence(EntityMatch $entityMatch, string $side, int $sentenceId): Model
    {
        $sentence = EntitySentence::query()
            ->whereKey($sentenceId)
            ->where('entity_id', $this->entityId($entityMatch, $side))
            ->first();

        abort_if($sentence === null, 404);

        return $sentence;
    }

    /**
     * @param  'a'|'b'  $side
     */
    private function entityId(EntityMatch $entityMatch, string $side): int
    {
        return $side === 'a' ? (int) $entityMatch->a_entity_id : (int) $entityMatch->b_entity_id;
    }
}
