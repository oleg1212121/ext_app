<?php

namespace App\Http\Controllers;

use App\Classes\EntityAccessService;
use App\Classes\EntityCreationService;
use App\Classes\SparseOrderService;
use App\Http\Requests\ReorderEntitySentenceRequest;
use App\Http\Requests\StoreEntityRequest;
use App\Http\Requests\StoreEntitySentenceRequest;
use App\Http\Requests\UpdateEntityApprovalRequest;
use App\Http\Requests\UpdateEntityRequest;
use App\Http\Requests\UpdateEntitySentenceRequest;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\Language;
use App\Models\SentenceType;
use App\Models\Work;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EntityController extends Controller
{
    public function __construct(
        private readonly SparseOrderService $sparseOrder,
        private readonly EntityCreationService $creation = new EntityCreationService,
    ) {}

    public function create(string $lang): Response
    {
        $language = $this->resolveLanguage($lang);

        return Inertia::render('Entities/Create', [
            'lang' => $lang,
            'language' => $this->languagePayload($language),
            'works' => $this->worksForSelect(),
            'languages' => Language::query()->enabled()->orderBy('sort_order')->get()
                ->map(fn (Language $item): array => [
                    ...$this->languagePayload($item),
                    'id' => $item->id,
                ])->all(),
        ]);
    }

    public function store(StoreEntityRequest $request, string $lang): RedirectResponse
    {
        $language = $this->resolveLanguage($lang);

        $work = $this->resolveWork($request, $language);

        $result = $this->creation->create(
            $request->user(),
            $work,
            $language,
            $request->validated(),
            $request->file('file'),
        );

        if ($result['status'] === 'created_from_copy') {
            return redirect()->route('entities.show', ['lang' => $lang, 'entity' => $result['entity']->id])
                ->with('status', 'Your upload is an exact copy of an existing text — your own entity was created with sentences and word statistics precomputed.');
        }

        return redirect()->route('entities.show', ['lang' => $lang, 'entity' => $result['entity']->id]);
    }

    public function show(string $lang, int $entityId): Response
    {
        $language = $this->resolveLanguage($lang);

        $entity = Entity::query()
            ->where('language_id', $language->id)
            ->withCount('sentences')
            ->findOrFail($entityId);

        if (! $this->access()->canRead(auth()->user(), $entity)) {
            abort(403);
        }

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

        return Inertia::render('Entities/Show', [
            'lang' => $lang,
            'language' => $this->languagePayload($language),
            'entity' => [
                'id' => $entity->id,
                'name' => $entity->name,
                'label' => $entity->label,
                'work_id' => $entity->work_id,
                'work_title' => $entity->work?->title,
                'description' => $entity->description,
                'file_path' => $entity->file_path,
                'signature_status' => $entity->signatureStatus(),
                'is_approved' => $entity->is_approved,
                'sentences_count' => $entity->sentences_count,
                'created_at' => $entity->created_at?->toISOString(),
                'updated_at' => $entity->updated_at?->toISOString(),
            ],
            'entityMatches' => $entityMatches,
            'can_edit' => $canEdit,
            'can_change_approval' => $this->access()->canChangeApproval(auth()->user(), $entity),
            'sentences' => $sentences->through(function (object $sentence): array {
                return [
                    'id' => $sentence->id,
                    'order' => $sentence->order,
                    'content' => $sentence->content,
                    'type' => $sentence->sentenceType?->name,
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

    public function edit(string $lang, int $entityId): Response
    {
        $language = $this->resolveLanguage($lang);

        $entity = Entity::query()
            ->where('language_id', $language->id)
            ->withCount('sentences')
            ->findOrFail($entityId);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $sentenceTypes = SentenceType::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $alignmentCount = $this->alignmentCount($entity->id);

        return Inertia::render('Entities/Edit', [
            'lang' => $lang,
            'language' => $this->languagePayload($language),
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
            'sentencesEndpoint' => route('entities.sentences', ['lang' => $lang, 'entity' => $entity->id]),
        ]);
    }

    public function update(UpdateEntityRequest $request, string $lang, int $entityId): RedirectResponse
    {
        $language = $this->resolveLanguage($lang);

        $entity = Entity::query()
            ->where('language_id', $language->id)
            ->findOrFail($entityId);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $data = $request->validated();

        $entity->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return redirect()->route('entities.show', ['lang' => $lang, 'entity' => $entity->id])
            ->with('status', 'Entity updated.');
    }

    /**
     * Flip the entity's approval lock. Allowed for the uploader and admins
     * even while the entity is approved — this is the one change that stays
     * possible under the lock (ADR 0034).
     */
    public function updateApproved(UpdateEntityApprovalRequest $request, string $lang, int $entityId): RedirectResponse
    {
        $language = $this->resolveLanguage($lang);

        $entity = Entity::query()
            ->where('language_id', $language->id)
            ->findOrFail($entityId);

        abort_unless($this->access()->canChangeApproval($request->user(), $entity), 403);

        $entity->update(['is_approved' => (bool) $request->validated('is_approved')]);

        $status = $entity->is_approved
            ? 'Entity approved — editing is locked.'
            : 'Approval removed — editing unlocked.';

        return redirect()->route('entities.show', ['lang' => $lang, 'entity' => $entity->id])
            ->with('status', $status);
    }

    public function sentences(string $lang, int $entityId, Request $request): JsonResponse
    {
        $language = $this->resolveLanguage($lang);

        $entity = Entity::query()
            ->where('language_id', $language->id)
            ->findOrFail($entityId);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $page = $request->integer('page', 1);
        $perPage = $this->normalizePerPage($request->integer('per_page', 25));

        return response()->json($this->pageResponse($entity, $page, $perPage));
    }

    public function storeSentence(StoreEntitySentenceRequest $request, string $lang, int $entityId): JsonResponse
    {
        $language = $this->resolveLanguage($lang);

        $entity = Entity::query()
            ->where('language_id', $language->id)
            ->findOrFail($entityId);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $data = $request->validated();
        $content = trim((string) $data['content']);
        $afterSentenceId = $data['after_sentence_id'] ?? null;

        $sentence = DB::transaction(function () use ($entity, $content, $data, $afterSentenceId): Model {
            $sentences = EntitySentence::query()
                ->where('entity_id', $entity->id)
                ->orderBy('order')
                ->orderBy('id')
                ->get(['id', 'order'])
                ->map(fn ($row): array => ['key' => 's-'.$row->id, 'order' => (int) $row->order])
                ->values()
                ->all();

            $afterOrder = $this->resolveAfterOrder($afterSentenceId, $sentences);

            $result = $this->sparseOrder->orderForInsertAfter($sentences, null, $afterOrder);

            $result = $this->shiftOrdersNonNegative($result);

            $this->persistSentenceOrders($entity->id, $this->ordersFromItems($result['items']));

            return EntitySentence::query()->create([
                'entity_id' => $entity->id,
                'sentence_type_id' => (int) $data['sentence_type_id'],
                'content' => $content,
                'order' => $result['order'],
            ]);
        });

        $this->setMatchesPending($entity->id);

        $page = $request->integer('page', 1);
        $perPage = $this->normalizePerPage($request->integer('per_page', 25));
        $position = $this->positionOf($entity, $sentence);
        $targetPage = (int) floor($position / $perPage) + 1;

        return response()->json([
            'sentence' => $this->sentencePayload($sentence),
            ...$this->pageResponse($entity, $targetPage, $perPage),
        ]);
    }

    public function updateSentence(UpdateEntitySentenceRequest $request, string $lang, int $entityId, int $sentence): JsonResponse
    {
        $language = $this->resolveLanguage($lang);

        $entity = Entity::query()
            ->where('language_id', $language->id)
            ->findOrFail($entityId);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $data = $request->validated();

        $sentenceModel = $this->findEntitySentence($entity->id, $sentence);

        $sentenceModel->update([
            'content' => trim((string) $data['content']),
            'sentence_type_id' => (int) $data['sentence_type_id'],
        ]);

        $this->setMatchesPending($entity->id);

        return response()->json([
            'sentence' => $this->sentencePayload($sentenceModel->refresh()),
        ]);
    }

    public function destroySentence(string $lang, int $entityId, int $sentence, Request $request): JsonResponse
    {
        $language = $this->resolveLanguage($lang);

        $entity = Entity::query()
            ->where('language_id', $language->id)
            ->findOrFail($entityId);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $sentenceModel = $this->findEntitySentence($entity->id, $sentence);

        DB::transaction(fn () => $sentenceModel->delete());

        $this->setMatchesPending($entity->id);

        $page = $request->integer('page', 1);
        $perPage = $this->normalizePerPage($request->integer('per_page', 25));

        return response()->json($this->pageResponse($entity, $page, $perPage));
    }

    public function reorderSentences(ReorderEntitySentenceRequest $request, string $lang, int $entityId): JsonResponse
    {
        $language = $this->resolveLanguage($lang);

        $entity = Entity::query()
            ->where('language_id', $language->id)
            ->findOrFail($entityId);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $data = $request->validated();
        $sentenceId = (int) $data['sentence_id'];
        $afterSentenceId = $data['after_sentence_id'] ?? null;

        $sentenceModel = $this->findEntitySentence($entity->id, $sentenceId);

        DB::transaction(function () use ($entity, $sentenceModel, $afterSentenceId): void {
            $sentences = EntitySentence::query()
                ->where('entity_id', $entity->id)
                ->orderBy('order')
                ->orderBy('id')
                ->get(['id', 'order'])
                ->map(fn ($row): array => ['key' => 's-'.$row->id, 'order' => (int) $row->order])
                ->values()
                ->all();

            $afterOrder = $this->resolveAfterOrder($afterSentenceId, $sentences);

            $result = $this->sparseOrder->orderForInsertAfter($sentences, 's-'.$sentenceModel->id, $afterOrder);

            $result = $this->shiftOrdersNonNegative($result);

            $orders = $this->ordersFromItems($result['items']);
            $orders[$sentenceModel->id] = $result['order'];

            $this->persistSentenceOrders($entity->id, $orders);
        });

        // Reorder only rewrites order values in bulk (no model events), but
        // the hash covers content in order — mark the sentence set changed.
        $entity->touchSentences();

        $this->setMatchesPending($entity->id);

        $page = $request->integer('page', 1);
        $perPage = $this->normalizePerPage($request->integer('per_page', 25));
        $sentenceModel->refresh();
        $position = $this->positionOf($entity, $sentenceModel);
        $targetPage = (int) floor($position / $perPage) + 1;

        return response()->json($this->pageResponse($entity, $targetPage, $perPage));
    }

    private function resolveLanguage(string $lang): Language
    {
        return Language::query()
            ->enabled()
            ->where('code', $lang)
            ->firstOrFail();
    }

    /**
     * Resolve the work for a new entity: an existing work id, or a newly
     * created one from the inline "new work" fields. When the original
     * language is not given it defaults to the entity's own language.
     */
    private function resolveWork(StoreEntityRequest $request, Language $language): Work
    {
        $workId = $request->validated('work_id');

        if ($workId !== null) {
            return Work::query()->findOrFail((int) $workId);
        }

        return Work::query()->create([
            'title' => $request->validated('new_work_title'),
            'author' => $request->validated('new_work_author'),
            'original_language_id' => (int) ($request->validated('new_work_original_language_id') ?? $language->id),
        ]);
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
     * @return list<array{id: int, title: string}>
     */
    private function worksForSelect(): array
    {
        return Work::query()
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (Work $work): array => ['id' => $work->id, 'title' => $work->title])
            ->all();
    }

    /**
     * Resolve the anchor order for an insert/reorder operation.
     * after_sentence_id = 0 means "at the beginning" (BEGINNING_SENTINEL);
     * null means "at the end" (max order, or BEGINNING_SENTINEL if empty);
     * any other integer is the sentence to insert after.
     *
     * @param  list<array{key: string, order: int}>  $sentences
     */
    private function resolveAfterOrder(?int $afterSentenceId, array $sentences): int
    {
        if ($afterSentenceId === 0) {
            return SparseOrderService::BEGINNING_SENTINEL;
        }

        if ($afterSentenceId !== null) {
            return (int) EntitySentence::query()->whereKey($afterSentenceId)->value('order');
        }

        return $sentences !== [] ? (int) max(array_column($sentences, 'order')) : SparseOrderService::BEGINNING_SENTINEL;
    }

    private function findEntitySentence(int $entityId, int $sentenceId): Model
    {
        $sentence = EntitySentence::query()
            ->whereKey($sentenceId)
            ->where('entity_id', $entityId)
            ->first();

        abort_if($sentence === null, 404);

        return $sentence;
    }

    /**
     * Persist sentence orders two-phase: every changed row is parked at a
     * unique negative order before the finals are written. The final orders
     * are collision-free as a set, but one row's final may be another row's
     * current order, so a naive one-by-one write would trip the
     * (entity_id, order) unique index mid-write.
     *
     * @param  array<int, int>  $orders  sentence id => final order
     */
    private function persistSentenceOrders(int $entityId, array $orders): void
    {
        $currentOrders = EntitySentence::query()
            ->where('entity_id', $entityId)
            ->get(['id', 'order'])
            ->mapWithKeys(fn ($row): array => [$row->id => (int) $row->order]);

        $changed = [];

        foreach ($orders as $id => $order) {
            if (($currentOrders->get($id) ?? null) !== $order) {
                $changed[$id] = $order;
            }
        }

        if ($changed === []) {
            return;
        }

        foreach (array_keys($changed) as $id) {
            EntitySentence::query()->whereKey($id)->update(['order' => -$id - 1_000_000_000]);
        }

        foreach ($changed as $id => $order) {
            EntitySentence::query()->whereKey($id)->update(['order' => $order]);
        }
    }

    /**
     * @param  list<array{key: string, order: int}>  $items
     * @return array<int, int>
     */
    private function ordersFromItems(array $items): array
    {
        $orders = [];

        foreach ($items as $item) {
            $orders[(int) substr($item['key'], 2)] = (int) $item['order'];
        }

        return $orders;
    }

    /**
     * Flip every EntityMatch involving this entity to status = 'pending',
     * surfacing the need to re-align. See ADR 0015.
     */
    private function setMatchesPending(int $entityId): void
    {
        EntityMatch::query()
            ->where(function (Builder $query) use ($entityId): void {
                $query->where('a_entity_id', $entityId)
                    ->orWhere('b_entity_id', $entityId);
            })
            ->where('status', '!=', 'pending')
            ->update(['status' => 'pending']);
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

    /**
     * Shift every order in a SparseOrderService result so that the minimum is
     * non-negative, preserving relative sequence. Mirrors the guard in
     * AlignmentEditorController::storeSentence so entity-editor sentences never
     * carry negative order values (which surface as negative display numbers).
     *
     * @param  array{order: int, items: list<array{key: string, order: int}>}  $result
     * @return array{order: int, items: list<array{key: string, order: int}>}
     */
    private function shiftOrdersNonNegative(array $result): array
    {
        $orders = array_column($result['items'], 'order');
        $orders[] = $result['order'];
        $minOrder = min($orders);

        if ($minOrder < 0) {
            $shift = -$minOrder;
            $result['order'] += $shift;

            foreach ($result['items'] as &$item) {
                $item['order'] += $shift;
            }
            unset($item);
        }

        return $result;
    }

    private function normalizePerPage(int $perPage): int
    {
        return max(1, min($perPage, 100));
    }

    /**
     * @return array{id: int, order: int, content: string, sentence_type_id: int, type: ?string}
     */
    private function sentencePayload(Model $sentence): array
    {
        return [
            'id' => $sentence->id,
            'order' => (int) $sentence->order,
            'content' => $sentence->content,
            'sentence_type_id' => (int) $sentence->sentence_type_id,
            'type' => $sentence->sentenceType?->name,
        ];
    }
}
