<?php

namespace App\Http\Controllers;

use App\Classes\AlignmentEditorApiPresenter;
use App\Classes\AlignmentEditorService;
use App\Classes\EntityAccessService;
use App\Enums\Side;
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
use Illuminate\Http\JsonResponse;

/**
 * The HTTP surface of the alignment-editing domain (ADR 0062): access
 * gates, request validation, 404/422 mapping, and the mutation envelope.
 * Every alignment mutation — rows, junctions, ordering, totals — goes
 * through AlignmentEditorService; sentence content edits are plain model
 * writes with no alignment invariant attached.
 */
class AlignmentEditorController extends Controller
{
    public function __construct(
        private readonly AlignmentEditorService $editor,
        private readonly AlignmentEditorApiPresenter $presenter,
    ) {}

    private function access(): EntityAccessService
    {
        return new EntityAccessService;
    }

    public function storeRow(EntityMatch $entityMatch, StoreMeaningMatchRequest $request): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $meaningMatch = $this->editor->createRow($entityMatch, $request->validated('after_row_id'));

        return $this->mutationResponse($entityMatch, [$this->presenter->rowPayload($meaningMatch)]);
    }

    public function destroyRow(EntityMatch $entityMatch, MeaningMatch $meaningMatch): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        abort_unless($meaningMatch->entity_match_id === $entityMatch->id, 404);

        $unmatchedChanged = $this->editor->deleteRow($entityMatch, $meaningMatch);

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

        $this->editor->approveRow($meaningMatch);

        return $this->mutationResponse($entityMatch, [$this->presenter->rowPayload($meaningMatch->refresh())]);
    }

    public function storeSentence(EntityMatch $entityMatch, AddSentenceRequest $request): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $side = Side::from($request->validated('side'));
        $content = trim((string) $request->validated('content'));
        $meaningMatchId = (int) $request->validated('meaning_match_id');

        $meaningMatch = MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->findOrFail($meaningMatchId);

        $this->editor->addSentence($entityMatch, $meaningMatch, $side, $content);

        return $this->mutationResponse($entityMatch, [$this->presenter->rowPayload($meaningMatch->refresh())]);
    }

    public function updateSentence(EntityMatch $entityMatch, int $sentence, UpdateSentenceRequest $request): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $side = Side::from($request->validated('side'));
        $content = trim((string) $request->validated('content'));

        $sentenceModel = $this->findSideSentence($entityMatch, $side, $sentence);
        $sentenceModel->update(['content' => $content]);

        $rowId = $this->editor->rowIdOfSentence($entityMatch, $side, $sentenceModel->id);

        return $this->mutationResponse($entityMatch, $this->rowPayloadsByIds($entityMatch, $rowId !== null ? [$rowId] : []));
    }

    public function unlinkSentence(EntityMatch $entityMatch, int $sentence, SentenceSideRequest $request): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $side = Side::from($request->validated('side'));

        $sentenceModel = $this->findSideSentence($entityMatch, $side, $sentence);

        $rowId = $this->editor->rowIdOfSentence($entityMatch, $side, $sentenceModel->id);
        abort_if($rowId === null, 422, 'Sentence is not linked.');

        $this->editor->unlinkSentence($entityMatch, $side, $sentenceModel->id, $rowId);

        return $this->mutationResponse(
            $entityMatch,
            $this->rowPayloadsByIds($entityMatch, [$rowId]),
            [],
            [$side->value],
        );
    }

    public function destroyUnmatched(EntityMatch $entityMatch, int $sentence, SentenceSideRequest $request): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $side = Side::from($request->validated('side'));

        $sentenceModel = $this->findSideSentence($entityMatch, $side, $sentence);

        if ($this->editor->rowIdOfSentence($entityMatch, $side, $sentenceModel->id) !== null) {
            abort(422, 'Linked sentences must be unlinked before deletion.');
        }

        $this->editor->deleteUnmatchedSentence($entityMatch, $sentenceModel);

        return $this->mutationResponse($entityMatch, [], [], [$side->value]);
    }

    public function moveSentence(EntityMatch $entityMatch, MoveSentenceRequest $request): JsonResponse
    {
        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $side = Side::from($request->validated('side'));
        $sentenceId = (int) $request->validated('sentence_id');
        $toRowId = $request->validated('to_row_id');
        $index = (int) $request->validated('index');

        $this->findSideSentence($entityMatch, $side, $sentenceId);

        $affectedRowIds = $this->editor->moveSentence($entityMatch, $side, $sentenceId, $toRowId, $index);

        return $this->mutationResponse(
            $entityMatch,
            $this->rowPayloadsByIds($entityMatch, $affectedRowIds),
            [],
            [$side->value],
        );
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
     * The side's sentence; a miss (or a sentence of the other side's entity)
     * is a 404.
     */
    private function findSideSentence(EntityMatch $entityMatch, Side $side, int $sentenceId): EntitySentence
    {
        $sentence = $this->editor->findSideSentence($entityMatch, $side, $sentenceId);

        abort_if($sentence === null, 404);

        return $sentence;
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
}
