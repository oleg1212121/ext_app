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
use App\Jobs\RefineEntitySentences;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\Work;
use Illuminate\Http\JsonResponse;

/**
 * The HTTP surface of the alignment-editing domain (ADR 0062): access
 * gates, request validation, 404/422 mapping, and the mutation envelope.
 * Every alignment mutation — rows, junctions, ordering, totals, sentence
 * content — goes through AlignmentEditorService, whose writes never flip a
 * match stale (the editor's no-stale rule). All routes are work-nested
 * (ADR 0072); a match path under the wrong work is a 404.
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

    public function storeRow(Work $work, EntityMatch $entityMatch, StoreMeaningMatchRequest $request): JsonResponse
    {
        $this->abortUnlessInWork($work, $entityMatch);

        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $meaningMatch = $this->editor->createRow($entityMatch, $request->validated('after_row_id'));

        return $this->mutationResponse($entityMatch, [$this->presenter->rowPayload($meaningMatch)]);
    }

    public function destroyRow(Work $work, EntityMatch $entityMatch, MeaningMatch $meaningMatch): JsonResponse
    {
        $this->abortUnlessInWork($work, $entityMatch);

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

    public function approveRow(Work $work, EntityMatch $entityMatch, MeaningMatch $meaningMatch): JsonResponse
    {
        $this->abortUnlessInWork($work, $entityMatch);

        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        abort_unless($meaningMatch->entity_match_id === $entityMatch->id, 404);

        $this->editor->approveRow($meaningMatch);

        return $this->mutationResponse($entityMatch, [$this->presenter->rowPayload($meaningMatch->refresh())]);
    }

    public function disapproveRow(Work $work, EntityMatch $entityMatch, MeaningMatch $meaningMatch): JsonResponse
    {
        $this->abortUnlessInWork($work, $entityMatch);

        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        abort_unless($meaningMatch->entity_match_id === $entityMatch->id, 404);

        $this->editor->rejectRow($meaningMatch);

        return $this->mutationResponse($entityMatch, [$this->presenter->rowPayload($meaningMatch->refresh())]);
    }

    public function storeSentence(Work $work, EntityMatch $entityMatch, AddSentenceRequest $request): JsonResponse
    {
        $this->abortUnlessInWork($work, $entityMatch);

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

    public function updateSentence(Work $work, EntityMatch $entityMatch, int $sentence, UpdateSentenceRequest $request): JsonResponse
    {
        $this->abortUnlessInWork($work, $entityMatch);

        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $side = Side::from($request->validated('side'));

        $sentenceModel = $this->findSideSentence($entityMatch, $side, $sentence);
        $this->editor->updateSentenceContent($sentenceModel, trim((string) $request->validated('content')));

        $rowId = $this->editor->rowIdOfSentence($entityMatch, $side, $sentenceModel->id);

        return $this->mutationResponse($entityMatch, $this->rowPayloadsByIds($entityMatch, $rowId !== null ? [$rowId] : []));
    }

    public function unlinkSentence(Work $work, EntityMatch $entityMatch, int $sentence, SentenceSideRequest $request): JsonResponse
    {
        $this->abortUnlessInWork($work, $entityMatch);

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

    public function destroyUnmatched(Work $work, EntityMatch $entityMatch, int $sentence, SentenceSideRequest $request): JsonResponse
    {
        $this->abortUnlessInWork($work, $entityMatch);

        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        $side = Side::from($request->validated('side'));

        $sentenceModel = $this->findSideSentence($entityMatch, $side, $sentence);

        if ($this->editor->rowIdOfSentence($entityMatch, $side, $sentenceModel->id) !== null) {
            abort(422, 'Linked sentences must be unlinked before deletion.');
        }

        $this->editor->deleteUnmatchedSentence($entityMatch, $sentenceModel);

        return $this->mutationResponse($entityMatch, [], [], [$side->value]);
    }

    public function moveSentence(Work $work, EntityMatch $entityMatch, MoveSentenceRequest $request): JsonResponse
    {
        $this->abortUnlessInWork($work, $entityMatch);

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

    public function rows(Work $work, EntityMatch $entityMatch, RowsRequest $request): JsonResponse
    {
        $this->abortUnlessInWork($work, $entityMatch);

        abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403);

        $payload = $this->presenter->rowsPagePayload($entityMatch, $request->page(), $request->perPage());

        return response()->json([
            'match' => $this->presenter->matchPayload($entityMatch->refresh()),
            'rows' => $payload['rows'],
            'meta' => $payload['meta'],
            'sentences_before' => $payload['sentences_before'],
        ]);
    }

    public function unmatched(Work $work, EntityMatch $entityMatch, UnmatchedRequest $request): JsonResponse
    {
        $this->abortUnlessInWork($work, $entityMatch);

        abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403);

        return response()->json(
            $this->presenter->unmatchedPayload($entityMatch, $request->validated('side'), $request->page()),
        );
    }

    public function needsReview(Work $work, EntityMatch $entityMatch, NeedsReviewRequest $request): JsonResponse
    {
        $this->abortUnlessInWork($work, $entityMatch);

        abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403);

        return response()->json(
            $this->presenter->needsReviewPagePayload($entityMatch, $request->page()),
        );
    }

    /**
     * Queue the alignment refine round: re-align the regions around one-sided
     * machine rows with the DP aligner (joined window embeddings), replacing
     * machine rows only on strict score improvement. Runs on the queue like
     * the align pipeline — the match's status column tracks the run.
     */
    public function refine(Work $work, EntityMatch $entityMatch): JsonResponse
    {
        $this->abortUnlessInWork($work, $entityMatch);

        abort_unless($this->access()->canEditMatch(auth()->user(), $entityMatch), 403);

        RefineEntitySentences::dispatch($entityMatch->id);

        return response()->json(['queued' => true]);
    }

    /**
     * A match path under the wrong work is a 404, same as a missing one.
     */
    private function abortUnlessInWork(Work $work, EntityMatch $entityMatch): void
    {
        abort_unless($entityMatch->aEntity?->work_id === $work->id, 404);
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
