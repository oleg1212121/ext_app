<?php

namespace App\Http\Controllers;

use App\Classes\EntityAccessService;
use App\Classes\EntitySentenceStore;
use App\Classes\EntityWordKnowledgeService;
use App\Enums\SentenceAnchor;
use App\Http\Requests\ReorderEntitySentenceRequest;
use App\Http\Requests\StoreEntitySentenceRequest;
use App\Http\Requests\UpdateEntityApprovalRequest;
use App\Http\Requests\UpdateEntityRequest;
use App\Http\Requests\UpdateEntitySentenceRequest;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\Language;
use App\Models\SentenceType;
use App\Models\UserWord;
use App\Models\Work;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EntityController extends Controller
{
    public function __construct(
        private readonly EntitySentenceStore $sentences,
        private readonly EntityWordKnowledgeService $knowledge,
    ) {}

    public function show(Work $work, Entity $entity): Response
    {
        $this->abortUnlessInWork($work, $entity);

        if (! $this->access()->canRead(auth()->user(), $entity)) {
            abort(403);
        }

        $entity->load(['language', 'work'])->loadCount('sentences');

        $canEdit = $this->access()->canEdit(auth()->user(), $entity);

        $entityMatches = $entity->entityMatches()
            ->get(['id', 'status'])
            ->map(fn (EntityMatch $match): array => [
                'id' => $match->id,
                'status' => $match->status,
            ]);

        $sentences = $entity->sentences()
            ->with('sentenceType')
            ->orderBy('order')
            ->paginate(20);

        // Word knowledge (ADR 0074): the viewer's stored snapshot, computed
        // on the spot when missing or stale; null while the word list is
        // being (re)built.
        $knowledge = $this->knowledge->ensure($entity, auth()->user());

        return Inertia::render('Entities/Show', [
            'language' => $this->languagePayload($entity->language),
            'entity' => [
                'id' => $entity->id,
                'name' => $entity->name,
                'label' => $entity->label,
                'work_id' => $entity->work_id,
                'work_title' => $entity->work?->title,
                'description' => $entity->description,
                'file_path' => $entity->file_path,
                'signature_status' => $entity->signatureStatus(),
                'status' => $entity->status,
                'is_approved' => $entity->is_approved,
                'sentences_count' => $entity->sentences_count,
                'created_at' => $entity->created_at?->toISOString(),
                'updated_at' => $entity->updated_at?->toISOString(),
            ],
            'entityMatches' => $entityMatches,
            'word_knowledge' => $knowledge === null ? null : [
                'score' => $knowledge->score,
                'computed_at' => $knowledge->computed_at?->toISOString(),
            ],
            'needs_word_test' => ! UserWord::query()->where('user_id', auth()->id())->exists(),
            'can_edit' => $canEdit,
            'can_change_approval' => $this->access()->canChangeApproval(auth()->user(), $entity),
            'sentences' => $sentences->through(function (object $sentence): array {
                return [
                    'id' => $sentence->id,
                    'order' => $sentence->order,
                    'content' => $sentence->content,
                    'type' => $sentence->sentenceType?->name,
                    'image' => $sentence->image_path !== null
                        ? [
                            'url' => route('illustrations.show', ['sentence' => $sentence->id]),
                            'width' => $sentence->image_width !== null ? (int) $sentence->image_width : null,
                            'height' => $sentence->image_height !== null ? (int) $sentence->image_height : null,
                        ]
                        : null,
                ];
            })->items(),
            'sentences_meta' => [
                'current_page' => $sentences->currentPage(),
                'last_page' => $sentences->lastPage(),
                'total' => $sentences->total(),
                'per_page' => $sentences->perPage(),
            ],
        ]);
    }

    public function edit(Work $work, Entity $entity): Response
    {
        $this->abortUnlessInWork($work, $entity);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $entity->load(['language', 'work'])->loadCount('sentences');

        $sentenceTypes = SentenceType::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $alignmentCount = $this->alignmentCount($entity->id);

        return Inertia::render('Entities/Edit', [
            'language' => $this->languagePayload($entity->language),
            'entity' => [
                'id' => $entity->id,
                'name' => $entity->name,
                'label' => $entity->label,
                'work_id' => $entity->work_id,
                'work_title' => $entity->work?->title,
                'description' => $entity->description,
                'is_restricted' => $entity->is_restricted,
                'is_approved' => $entity->is_approved,
                'sentences_count' => $entity->sentences_count,
            ],
            'sentenceTypes' => $sentenceTypes->map(fn (SentenceType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
            ])->all(),
            'alignmentCount' => $alignmentCount,
            'can_change_approval' => $this->access()->canChangeApproval(auth()->user(), $entity),
            'sentencesEndpoint' => route('entities.sentences', ['work' => $work->id, 'entity' => $entity->id]),
        ]);
    }

    public function update(Work $work, Entity $entity, UpdateEntityRequest $request): RedirectResponse
    {
        $this->abortUnlessInWork($work, $entity);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $data = $request->validated();

        $entity->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return redirect()->route('entities.show', ['work' => $work->id, 'entity' => $entity->id])
            ->with('status', 'Entity updated.');
    }

    /**
     * Flip the entity's approval lock. Allowed for the uploader and admins
     * even while the entity is approved — this is the one change that stays
     * possible under the lock (ADR 0034).
     */
    public function updateApproved(Work $work, Entity $entity, UpdateEntityApprovalRequest $request): RedirectResponse
    {
        $this->abortUnlessInWork($work, $entity);

        abort_unless($this->access()->canChangeApproval($request->user(), $entity), 403);

        $entity->update(['is_approved' => (bool) $request->validated('is_approved')]);

        $status = $entity->is_approved
            ? 'Entity approved — editing is locked.'
            : 'Approval removed — editing unlocked.';

        return redirect()->route('entities.show', ['work' => $work->id, 'entity' => $entity->id])
            ->with('status', $status);
    }

    public function sentences(Work $work, Entity $entity, Request $request): JsonResponse
    {
        $this->abortUnlessInWork($work, $entity);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $page = $request->integer('page', 1);
        $perPage = $this->normalizePerPage($request->integer('per_page', 25));

        return response()->json($this->pageResponse($entity, $page, $perPage));
    }

    public function storeSentence(Work $work, Entity $entity, StoreEntitySentenceRequest $request): JsonResponse
    {
        $this->abortUnlessInWork($work, $entity);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $data = $request->validated();
        $content = trim((string) ($data['content'] ?? ''));
        $afterSentenceId = $data['after_sentence_id'] ?? null;

        $sentence = $this->sentences->insert($entity, [
            'content' => $content,
            'sentence_type_id' => (int) $data['sentence_type_id'],
        ], $this->sentenceAnchor($afterSentenceId), $request->file('image'));

        $page = $request->integer('page', 1);
        $perPage = $this->normalizePerPage($request->integer('per_page', 25));
        $position = $this->positionOf($entity, $sentence);
        $targetPage = (int) floor($position / $perPage) + 1;

        return response()->json([
            'sentence' => $this->sentencePayload($sentence),
            ...$this->pageResponse($entity, $targetPage, $perPage),
        ]);
    }

    public function updateSentence(Work $work, Entity $entity, int $sentence, UpdateEntitySentenceRequest $request): JsonResponse
    {
        $this->abortUnlessInWork($work, $entity);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $data = $request->validated();

        $sentenceModel = $this->findEntitySentence($entity->id, $sentence);

        $sentenceModel = $this->sentences->update($sentenceModel, [
            'content' => trim((string) ($data['content'] ?? '')),
            'sentence_type_id' => (int) $data['sentence_type_id'],
        ], null, $request->file('image'));

        return response()->json([
            'sentence' => $this->sentencePayload($sentenceModel),
        ]);
    }

    public function destroySentence(Work $work, Entity $entity, int $sentence, Request $request): JsonResponse
    {
        $this->abortUnlessInWork($work, $entity);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $sentenceModel = $this->findEntitySentence($entity->id, $sentence);

        $this->sentences->delete($sentenceModel);

        $page = $request->integer('page', 1);
        $perPage = $this->normalizePerPage($request->integer('per_page', 25));

        return response()->json($this->pageResponse($entity, $page, $perPage));
    }

    public function reorderSentences(Work $work, Entity $entity, ReorderEntitySentenceRequest $request): JsonResponse
    {
        $this->abortUnlessInWork($work, $entity);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $data = $request->validated();
        $sentenceId = (int) $data['sentence_id'];
        $afterSentenceId = $data['after_sentence_id'] ?? null;

        $sentenceModel = $this->findEntitySentence($entity->id, $sentenceId);

        $sentenceModel = $this->sentences->reorder($sentenceModel, $this->sentenceAnchor($afterSentenceId));

        $page = $request->integer('page', 1);
        $perPage = $this->normalizePerPage($request->integer('per_page', 25));
        $position = $this->positionOf($entity, $sentenceModel);
        $targetPage = (int) floor($position / $perPage) + 1;

        return response()->json($this->pageResponse($entity, $targetPage, $perPage));
    }

    /**
     * Entity routes nest under the work (ADR 0073). The entity carries its
     * language, so the work segment is verified, not used for lookup — a
     * URL naming another work is a 404, not a wrong-language 404.
     */
    private function abortUnlessInWork(Work $work, Entity $entity): void
    {
        abort_unless($entity->work_id === $work->id, 404);
    }

    private function access(): EntityAccessService
    {
        return new EntityAccessService;
    }

    /**
     * @return array{code: string, name: string, native_name: ?string}
     */
    private function languagePayload(Language $language): array
    {
        return [
            'code' => $language->code,
            'name' => $language->name,
            'native_name' => $language->native_name,
        ];
    }

    /**
     * Translate the wire convention for an insert position into the anchor
     * type: after_sentence_id 0 means "at the beginning", null means
     * "append at the end", any other integer is the sentence to insert
     * after.
     */
    private function sentenceAnchor(?int $afterSentenceId): SentenceAnchor
    {
        if ($afterSentenceId === 0) {
            return SentenceAnchor::beginning();
        }

        if ($afterSentenceId !== null) {
            return SentenceAnchor::after($afterSentenceId);
        }

        return SentenceAnchor::end();
    }

    private function findEntitySentence(int $entityId, int $sentenceId): EntitySentence
    {
        $sentence = EntitySentence::query()
            ->whereKey($sentenceId)
            ->where('entity_id', $entityId)
            ->first();

        abort_if($sentence === null, 404);

        return $sentence;
    }

    private function alignmentCount(int $entityId): int
    {
        return (int) $this->access()
            ->readableMatchQuery(auth()->user())
            ->where(function (Builder $query) use ($entityId): void {
                $query->where('a_entity_id', $entityId)
                    ->orWhere('b_entity_id', $entityId);
            })
            ->count();
    }

    /**
     * Paginated slice of an entity's sentences plus display metadata.
     *
     * @return array{sentences: list<array{id: int, order: int, content: string, sentence_type_id: int, type: ?string}>, meta: array{current_page: int, last_page: int, total: int, per_page: int}, before_first_id: ?int}
     */
    private function pageResponse(Entity $entity, int $page, int $perPage): array
    {
        $query = EntitySentence::query()
            ->where('entity_id', $entity->id)
            ->orderBy('order')
            ->orderBy('id');

        $total = (clone $query)->count();
        $lastPage = max((int) ceil($total / $perPage), 1);
        $currentPage = min(max($page, 1), $lastPage);

        $items = (clone $query)
            ->forPage($currentPage, $perPage)
            ->get();

        $sentences = $items
            ->map(fn (object $sentence): array => $this->sentencePayload($sentence))
            ->all();

        $beforeFirstId = $currentPage > 1
            ? $this->sentenceIdAtOffset($entity, ($currentPage - 1) * $perPage - 1)
            : null;

        return [
            'sentences' => $sentences,
            'meta' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'total' => $total,
                'per_page' => $perPage,
            ],
            'before_first_id' => $beforeFirstId,
        ];
    }

    private function sentenceIdAtOffset(Entity $entity, int $offset): ?int
    {
        if ($offset < 0) {
            return null;
        }

        return EntitySentence::query()
            ->where('entity_id', $entity->id)
            ->orderBy('order')
            ->orderBy('id')
            ->offset($offset)
            ->limit(1)
            ->value('id');
    }

    /**
     * 0-based position of a sentence in the global (order, id) ordering.
     */
    private function positionOf(Entity $entity, Model $sentence): int
    {
        return (int) EntitySentence::query()
            ->where('entity_id', $entity->id)
            ->where(function ($query) use ($sentence): void {
                $query
                    ->where('order', '<', $sentence->order)
                    ->orWhere(fn ($q) => $q->where('order', $sentence->order)->where('id', '<', $sentence->id));
            })
            ->count();
    }

    private function normalizePerPage(int $perPage): int
    {
        return max(1, min($perPage, 100));
    }

    /**
     * @return array{id: int, order: int, content: string, sentence_type_id: int, type: ?string, image: ?array{url: string, width: ?int, height: ?int}}
     */
    private function sentencePayload(Model $sentence): array
    {
        return [
            'id' => $sentence->id,
            'order' => (int) $sentence->order,
            'content' => $sentence->content,
            'sentence_type_id' => (int) $sentence->sentence_type_id,
            'type' => $sentence->sentenceType?->name,
            'image' => $sentence->image_path !== null
                ? [
                    'url' => route('illustrations.show', ['sentence' => $sentence->id]),
                    'width' => $sentence->image_width !== null ? (int) $sentence->image_width : null,
                    'height' => $sentence->image_height !== null ? (int) $sentence->image_height : null,
                ]
                : null,
        ];
    }
}
