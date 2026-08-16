<?php

namespace App\Http\Controllers;

use App\Classes\AlignmentEditorApiPresenter;
use App\Classes\SparseOrderService;
use App\Http\Requests\AddSentenceRequest;
use App\Http\Requests\MoveSentenceRequest;
use App\Http\Requests\NeedsReviewRequest;
use App\Http\Requests\RowsRequest;
use App\Http\Requests\SentenceLangRequest;
use App\Http\Requests\StoreMeaningMatchRequest;
use App\Http\Requests\UnmatchedRequest;
use App\Http\Requests\UpdateSentenceRequest;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\RuEntitySentence;
use App\Models\RuSentenceMeaningMatch;
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

    public function storeRow(EnRuEntityMatch $entityMatch, StoreMeaningMatchRequest $request): JsonResponse
    {
        $afterRowId = $request->validated('after_row_id');

        $meaningMatch = DB::transaction(function () use ($entityMatch, $afterRowId): EnRuMeaningMatch {
            $rows = EnRuMeaningMatch::query()
                ->where('en_ru_entity_match_id', $entityMatch->id)
                ->orderBy('order')
                ->get(['id', 'order']);

            $items = $rows
                ->map(fn (EnRuMeaningMatch $row): array => ['key' => 'mm-'.$row->id, 'order' => (int) $row->order])
                ->values()
                ->all();

            $afterOrder = $afterRowId !== null
                ? (int) $rows->firstWhere('id', $afterRowId)->order
                : ($rows->last()?->order ?? SparseOrderService::BEGINNING_SENTINEL);

            $result = $this->sparseOrder->orderForInsertAfter($items, null, $afterOrder);

            $this->persistRowOrderChanges($rows, $result['items']);

            return EnRuMeaningMatch::query()->create([
                'en_ru_entity_match_id' => $entityMatch->id,
                'order' => $result['order'],
                'similarity' => 1.0,
                'alignment_chunk' => -1,
            ]);
        });

        $entityMatch->refresh()->update(['linked_count' => $entityMatch->meaningMatches()->count()]);

        return $this->mutationResponse($entityMatch, [$this->presenter->rowPayload($meaningMatch)]);
    }

    public function destroyRow(EnRuEntityMatch $entityMatch, EnRuMeaningMatch $meaningMatch): JsonResponse
    {
        abort_unless($meaningMatch->en_ru_entity_match_id === $entityMatch->id, 404);

        $unmatchedChanged = [];

        DB::transaction(function () use ($entityMatch, $meaningMatch, &$unmatchedChanged): void {
            if ($meaningMatch->enSentenceMatches()->exists()) {
                $unmatchedChanged[] = 'en';
            }

            if ($meaningMatch->ruSentenceMatches()->exists()) {
                $unmatchedChanged[] = 'ru';
            }

            $meaningMatch->enSentenceMatches()->delete();
            $meaningMatch->ruSentenceMatches()->delete();
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

    public function approveRow(EnRuEntityMatch $entityMatch, EnRuMeaningMatch $meaningMatch): JsonResponse
    {
        abort_unless($meaningMatch->en_ru_entity_match_id === $entityMatch->id, 404);

        $meaningMatch->update(['similarity' => 1.0, 'alignment_chunk' => -1]);

        return $this->mutationResponse($entityMatch, [$this->presenter->rowPayload($meaningMatch->refresh())]);
    }

    public function storeSentence(EnRuEntityMatch $entityMatch, AddSentenceRequest $request): JsonResponse
    {
        $lang = $request->validated('lang');
        $content = trim((string) $request->validated('content'));
        $meaningMatchId = (int) $request->validated('meaning_match_id');

        $meaningMatch = EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatch->id)
            ->findOrFail($meaningMatchId);

        DB::transaction(function () use ($entityMatch, $lang, $content, $meaningMatch): void {
            $sentenceClass = $this->sentenceClass($lang);
            $entityForeignKey = $this->entityForeignKey($lang);
            $entityId = $this->entityId($entityMatch, $lang);

            $anchor = $this->sideAnchorOrder($entityMatch, $lang, $meaningMatch);

            $sentences = $sentenceClass::query()
                ->where($entityForeignKey, $entityId)
                ->get(['id', 'order'])
                ->map(fn ($sentence): array => ['key' => 's-'.$sentence->id, 'order' => (int) $sentence->order])
                ->values()
                ->all();

            $result = $this->sparseOrder->orderForInsertAfter($sentences, null, $anchor);

            $sentenceTypeId = SentenceType::query()->where('name', 'sentence')->value('id');

            $sentence = $sentenceClass::query()->create([
                $entityForeignKey => $entityId,
                'sentence_type_id' => $sentenceTypeId,
                'content' => $content,
                'order' => $result['order'],
            ]);

            $this->persistSideOrderChanges($entityId, $lang, $result['items']);

            $junctionOrder = $this->appendJunctionOrder($lang, $meaningMatch);

            $this->junctionClass($lang)::query()->create([
                $this->sentenceForeignKey($lang) => $sentence->id,
                'en_ru_meaning_match_id' => $meaningMatch->id,
                'order' => $junctionOrder,
            ]);

            $meaningMatch->update(['similarity' => 1.0]);

            $totalColumn = $lang === 'en' ? 'en_total_sentences' : 'ru_total_sentences';
            $entityMatch->update([$totalColumn => $sentenceClass::query()->where($entityForeignKey, $entityId)->count()]);
        });

        return $this->mutationResponse($entityMatch, [$this->presenter->rowPayload($meaningMatch->refresh())]);
    }

    public function updateSentence(EnRuEntityMatch $entityMatch, int $sentence, UpdateSentenceRequest $request): JsonResponse
    {
        $lang = $request->validated('lang');
        $content = trim((string) $request->validated('content'));

        $sentenceModel = $this->findSideSentence($entityMatch, $lang, $sentence);
        $sentenceModel->update(['content' => $content]);

        $rowId = $this->rowIdOfSentence($lang, $sentenceModel->id);

        return $this->mutationResponse($entityMatch, $this->rowPayloadsByIds($entityMatch, $rowId !== null ? [$rowId] : []));
    }

    public function unlinkSentence(EnRuEntityMatch $entityMatch, int $sentence, SentenceLangRequest $request): JsonResponse
    {
        $lang = $request->validated('lang');

        $sentenceModel = $this->findSideSentence($entityMatch, $lang, $sentence);

        $rowId = $this->rowIdOfSentence($lang, $sentenceModel->id);
        abort_if($rowId === null, 422, 'Sentence is not linked.');

        DB::transaction(function () use ($entityMatch, $lang, $sentenceModel, $rowId): void {
            $this->unlink($entityMatch, $lang, $sentenceModel->id, $rowId);
        });

        return $this->mutationResponse(
            $entityMatch,
            $this->rowPayloadsByIds($entityMatch, [$rowId]),
            [],
            [$lang],
        );
    }

    public function destroyUnmatched(EnRuEntityMatch $entityMatch, int $sentence, SentenceLangRequest $request): JsonResponse
    {
        $lang = $request->validated('lang');

        $sentenceModel = $this->findSideSentence($entityMatch, $lang, $sentence);

        if ($this->rowIdOfSentence($lang, $sentenceModel->id) !== null) {
            abort(422, 'Linked sentences must be unlinked before deletion.');
        }

        $entityId = $this->entityId($entityMatch, $lang);
        $entityForeignKey = $this->entityForeignKey($lang);

        DB::transaction(function () use ($entityMatch, $sentenceModel, $lang, $entityId, $entityForeignKey): void {
            $sentenceModel->delete();

            $totalColumn = $lang === 'en' ? 'en_total_sentences' : 'ru_total_sentences';
            $entityMatch->update([$totalColumn => $this->sentenceClass($lang)::query()->where($entityForeignKey, $entityId)->count()]);
        });

        return $this->mutationResponse($entityMatch, [], [], [$lang]);
    }

    public function moveSentence(EnRuEntityMatch $entityMatch, MoveSentenceRequest $request): JsonResponse
    {
        $lang = $request->validated('lang');
        $sentenceId = (int) $request->validated('sentence_id');
        $toRowId = $request->validated('to_row_id');
        $index = (int) $request->validated('index');

        $this->findSideSentence($entityMatch, $lang, $sentenceId);

        $affectedRowIds = [];

        DB::transaction(function () use ($entityMatch, $lang, $sentenceId, $toRowId, $index, &$affectedRowIds): void {
            $layout = $this->sideLayout($entityMatch, $lang);
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

                $this->reorderRowJunctions($lang, $toRowId, $seq, $sentenceId);
                $affectedRowIds[] = $toRowId;

                return;
            }

            if ($fromRowId !== null) {
                $this->unlink($entityMatch, $lang, $sentenceId, $fromRowId);
                $affectedRowIds[] = $fromRowId;
            }

            if ($toRowId !== null) {
                $this->link($lang, $sentenceId, $toRowId, $index);
                $affectedRowIds[] = $toRowId;
            }
        });

        return $this->mutationResponse(
            $entityMatch,
            $this->rowPayloadsByIds($entityMatch, $affectedRowIds),
            [],
            [$lang],
        );
    }

    public function rows(EnRuEntityMatch $entityMatch, RowsRequest $request): JsonResponse
    {
        $payload = $this->presenter->rowsPagePayload($entityMatch, $request->page(), $request->perPage());

        return response()->json([
            'match' => $this->presenter->matchPayload($entityMatch->refresh()),
            'rows' => $payload['rows'],
            'meta' => $payload['meta'],
        ]);
    }

    public function unmatched(EnRuEntityMatch $entityMatch, UnmatchedRequest $request): JsonResponse
    {
        return response()->json(
            $this->presenter->unmatchedPayload($entityMatch, $request->validated('lang'), $request->page()),
        );
    }

    public function needsReview(EnRuEntityMatch $entityMatch, NeedsReviewRequest $request): JsonResponse
    {
        return response()->json(
            $this->presenter->needsReviewPagePayload($entityMatch, $request->page()),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $deletedRows
     * @param  list<string>  $unmatchedChanged
     */
    private function mutationResponse(EnRuEntityMatch $entityMatch, array $rows, array $deletedRows = [], array $unmatchedChanged = []): JsonResponse
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
    private function rowPayloadsByIds(EnRuEntityMatch $entityMatch, array $rowIds): array
    {
        if ($rowIds === []) {
            return [];
        }

        return EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatch->id)
            ->whereIn('id', $rowIds)
            ->with([
                'enSentenceMatches.enEntitySentence',
                'ruSentenceMatches.ruEntitySentence',
            ])
            ->orderBy('order')
            ->get()
            ->map(fn (EnRuMeaningMatch $row): array => $this->presenter->rowPayload($row))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, EnRuMeaningMatch>  $rows
     * @param  list<array{key: string, order: int}>  $items
     */
    private function persistRowOrderChanges($rows, array $items): void
    {
        $currentOrders = $rows->keyBy('id')->map(fn (EnRuMeaningMatch $row): int => (int) $row->order);

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
            EnRuMeaningMatch::query()->whereKey($update['id'])->update(['order' => -$update['id']]);
        }

        foreach ($updates as $update) {
            EnRuMeaningMatch::query()->whereKey($update['id'])->update(['order' => $update['order']]);
        }
    }

    /**
     * @param  list<array{key: string, order: int}>  $items
     */
    private function persistSideOrderChanges(int $entityId, string $lang, array $items): void
    {
        $sentenceClass = $this->sentenceClass($lang);
        $entityForeignKey = $this->entityForeignKey($lang);

        $currentOrders = $sentenceClass::query()
            ->where($entityForeignKey, $entityId)
            ->get(['id', 'order'])
            ->mapWithKeys(fn ($sentence): array => [$sentence->id => (int) $sentence->order]);

        foreach ($items as $item) {
            $id = (int) substr($item['key'], 2);

            if (($currentOrders->get($id) ?? null) !== $item['order']) {
                $this->setSentenceOrderRaw($lang, $id, $item['order']);
            }
        }
    }

    /**
     * @param  list<array{key: string, order: int}>  $items
     */
    private function persistJunctionOrderChanges(int $rowId, string $lang, array $items): void
    {
        $currentOrders = $this->junctionClass($lang)::query()
            ->where('en_ru_meaning_match_id', $rowId)
            ->get(['id', 'order'])
            ->mapWithKeys(fn ($junction): array => [$junction->id => (int) $junction->order]);

        foreach ($items as $item) {
            $id = (int) substr($item['key'], 2);

            if (($currentOrders->get($id) ?? null) !== $item['order']) {
                $this->junctionClass($lang)::query()->whereKey($id)->update(['order' => $item['order']]);
            }
        }
    }

    /**
     * @return array{
     *     sentences: array<int, array{order: int, row_id: ?int}>,
     *     rows: array<int, array{order: int, ids: list<int>}>
     * }
     */
    private function sideLayout(EnRuEntityMatch $entityMatch, string $lang): array
    {
        $sentenceClass = $this->sentenceClass($lang);
        $entityForeignKey = $this->entityForeignKey($lang);
        $entityId = $this->entityId($entityMatch, $lang);
        $sideForeignKey = $this->sentenceForeignKey($lang);

        $sentences = $sentenceClass::query()
            ->where($entityForeignKey, $entityId)
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

        $rows = EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatch->id)
            ->with($lang === 'en' ? 'enSentenceMatches' : 'ruSentenceMatches')
            ->orderBy('order')
            ->get();

        foreach ($rows as $row) {
            $junctions = $lang === 'en' ? $row->enSentenceMatches : $row->ruSentenceMatches;

            if ($junctions->isEmpty()) {
                continue;
            }

            $ids = $junctions
                ->sortBy(fn ($junction): array => [(int) $junction->order, (int) $junction->{$sideForeignKey}])
                ->pluck($sideForeignKey)
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

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

    private function sideAnchorOrder(EnRuEntityMatch $entityMatch, string $lang, EnRuMeaningMatch $meaningMatch): int
    {
        $layout = $this->sideLayout($entityMatch, $lang);
        $currentRowOrder = (int) $meaningMatch->order;

        if (isset($layout['rows'][$meaningMatch->id]) && $layout['rows'][$meaningMatch->id]['ids'] !== []) {
            return $this->rowRightBoundary($layout, $layout['rows'][$meaningMatch->id]['ids']);
        }

        $anchor = SparseOrderService::BEGINNING_SENTINEL;

        foreach ($layout['rows'] as $row) {
            if ($row['order'] < $currentRowOrder && $row['ids'] !== []) {
                $anchor = $this->rowRightBoundary($layout, $row['ids']);
            }
        }

        return $anchor;
    }

    /**
     * @param  array{
     *     sentences: array<int, array{order: int, row_id: ?int}>,
     *     rows: array<int, array{order: int, ids: list<int>}>
     * }  $layout
     * @param  list<int>  $ids
     */
    private function rowRightBoundary(array $layout, array $ids): int
    {
        return max(array_map(fn (int $id): int => $layout['sentences'][$id]['order'], $ids));
    }

    /**
     * @param  list<int>  $seq
     */
    private function reorderRowJunctions(string $lang, int $rowId, array $seq, ?int $movedId = null): void
    {
        $sideForeignKey = $this->sentenceForeignKey($lang);

        $junctions = $this->junctionClass($lang)::query()
            ->where('en_ru_meaning_match_id', $rowId)
            ->orderBy('order')
            ->orderBy($sideForeignKey)
            ->get(['id', $sideForeignKey, 'order'])
            ->values();

        $orders = [];

        foreach ($junctions as $junction) {
            $orders[(int) $junction->{$sideForeignKey}] = (int) $junction->order;
        }

        if ($movedId !== null) {
            $remaining = array_values(array_filter($seq, fn (int $id): bool => $id !== $movedId));
            $insertIndex = array_search($movedId, $seq, true);

            $prevOrder = $insertIndex > 0 ? ($orders[$remaining[$insertIndex - 1]] ?? null) : null;
            $nextOrder = $insertIndex < count($remaining) ? ($orders[$remaining[$insertIndex]] ?? null) : null;

            $newOrder = $this->sparseOrder->between($prevOrder, $nextOrder);

            if ($newOrder !== null) {
                $this->setJunctionOrderRaw($lang, $movedId, $newOrder);

                return;
            }
        }

        $spread = $this->sparseOrder->spreadOrders(count($seq), null, null);

        foreach ($seq as $index => $id) {
            $this->setJunctionOrderRaw($lang, $id, $spread[$index]);
        }
    }

    private function link(string $lang, int $sentenceId, int $rowId, int $index): void
    {
        $sideForeignKey = $this->sentenceForeignKey($lang);

        $junctions = $this->junctionClass($lang)::query()
            ->where('en_ru_meaning_match_id', $rowId)
            ->orderBy('order')
            ->orderBy($sideForeignKey)
            ->get(['id', $sideForeignKey, 'order'])
            ->values();

        $items = $junctions
            ->map(fn ($junction): array => ['key' => 'j-'.$junction->id, 'order' => (int) $junction->order])
            ->values()
            ->all();

        $afterIndex = min($index, $junctions->count()) - 1;
        $afterOrder = $afterIndex >= 0
            ? (int) $junctions[$afterIndex]->order
            : SparseOrderService::BEGINNING_SENTINEL;

        $result = $this->sparseOrder->orderForInsertAfter($items, null, $afterOrder);

        $this->persistJunctionOrderChanges($rowId, $lang, $result['items']);

        $this->junctionClass($lang)::query()->create([
            $this->sentenceForeignKey($lang) => $sentenceId,
            'en_ru_meaning_match_id' => $rowId,
            'order' => $result['order'],
        ]);

        EnRuMeaningMatch::query()->whereKey($rowId)->update(['similarity' => 1.0]);
    }

    private function unlink(EnRuEntityMatch $entityMatch, string $lang, int $sentenceId, int $rowId): void
    {
        $this->junctionClass($lang)::query()
            ->where($this->sentenceForeignKey($lang), $sentenceId)
            ->where('en_ru_meaning_match_id', $rowId)
            ->delete();

        EnRuMeaningMatch::query()->whereKey($rowId)->update(['similarity' => 1.0]);
    }

    private function setSentenceOrderRaw(string $lang, int $sentenceId, int $order): void
    {
        $this->sentenceClass($lang)::query()->whereKey($sentenceId)->update(['order' => $order]);
    }

    private function setJunctionOrderRaw(string $lang, int $sentenceId, int $order): void
    {
        $this->junctionClass($lang)::query()
            ->where($this->sentenceForeignKey($lang), $sentenceId)
            ->update(['order' => $order]);
    }

    /**
     * Compute a sparse order that appends a new junction at the end of the
     * row's junction-order sequence, independent of the linked sentence's
     * document order.
     */
    private function appendJunctionOrder(string $lang, EnRuMeaningMatch $meaningMatch): int
    {
        $junctions = $this->junctionClass($lang)::query()
            ->where('en_ru_meaning_match_id', $meaningMatch->id)
            ->orderBy('order')
            ->orderBy('id')
            ->get(['id', 'order']);

        $items = $junctions
            ->map(fn ($junction): array => ['key' => 'j-'.$junction->id, 'order' => (int) $junction->order])
            ->values()
            ->all();

        $result = $this->sparseOrder->orderForInsertAfter(
            $items,
            null,
            $junctions->last()?->order ?? SparseOrderService::BEGINNING_SENTINEL,
        );

        $this->persistJunctionOrderChanges($meaningMatch->id, $lang, $result['items']);

        return $result['order'];
    }

    private function rowIdOfSentence(string $lang, int $sentenceId): ?int
    {
        $junction = $this->junctionClass($lang)::query()
            ->where($this->sentenceForeignKey($lang), $sentenceId)
            ->first();

        return $junction !== null ? (int) $junction->en_ru_meaning_match_id : null;
    }

    private function findSideSentence(EnRuEntityMatch $entityMatch, string $lang, int $sentenceId): Model
    {
        $sentenceClass = $this->sentenceClass($lang);

        $sentence = $sentenceClass::query()
            ->whereKey($sentenceId)
            ->where($this->entityForeignKey($lang), $this->entityId($entityMatch, $lang))
            ->first();

        abort_if($sentence === null, 404);

        return $sentence;
    }

    private function sentenceClass(string $lang): string
    {
        return $lang === 'en' ? EnEntitySentence::class : RuEntitySentence::class;
    }

    private function junctionClass(string $lang): string
    {
        return $lang === 'en' ? EnSentenceMeaningMatch::class : RuSentenceMeaningMatch::class;
    }

    private function sentenceForeignKey(string $lang): string
    {
        return $lang === 'en' ? 'en_entity_sentence_id' : 'ru_entity_sentence_id';
    }

    private function entityForeignKey(string $lang): string
    {
        return $lang === 'en' ? 'en_entity_id' : 'ru_entity_id';
    }

    private function entityId(EnRuEntityMatch $entityMatch, string $lang): int
    {
        return $lang === 'en' ? (int) $entityMatch->en_entity_id : (int) $entityMatch->ru_entity_id;
    }
}
