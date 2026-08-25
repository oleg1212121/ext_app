<?php

namespace App\Http\Controllers;

use App\Classes\EntityAccessService;
use App\Classes\SparseOrderService;
use App\Classes\TextSignatureService;
use App\Http\Requests\ReorderEntitySentenceRequest;
use App\Http\Requests\StoreEntityRequest;
use App\Http\Requests\StoreEntitySentenceRequest;
use App\Http\Requests\UpdateEntityRequest;
use App\Http\Requests\UpdateEntitySentenceRequest;
use App\Jobs\ProcessEntityFile;
use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\Language;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\SentenceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class EntityController extends Controller
{
    public function __construct(
        private readonly SparseOrderService $sparseOrder,
    ) {}

    public function index(): Response
    {
        $languages = Language::query()
            ->enabled()
            ->orderBy('sort_order')
            ->get()
            ->map(function (Language $language): array {
                return [
                    'code' => $language->code,
                    'name' => $language->name,
                    'native_name' => $language->native_name,
                    'entity_count' => $this->queryForLanguage($language->code)->count(),
                ];
            });

        return Inertia::render('Entities/Index', [
            'languages' => $languages,
        ]);
    }

    public function list(string $lang): Response
    {
        $language = $this->resolveLanguage($lang);

        $entities = $this->access()
            ->readableQuery(auth()->user(), $lang)
            ->withCount('sentences')
            ->orderBy('name')
            ->paginate(15);

        return Inertia::render('Entities/List', [
            'lang' => $lang,
            'language' => [
                'code' => $language->code,
                'name' => $language->name,
                'native_name' => $language->native_name,
            ],
            'entities' => $entities->through(function (EnEntity|RuEntity $entity): array {
                return [
                    'id' => $entity->id,
                    'name' => $entity->name,
                    'description' => $entity->description,
                    'signature_status' => $this->signatureStatus($entity),
                    'sentences_count' => $entity->sentences_count,
                    'created_at' => $entity->created_at?->toISOString(),
                ];
            })->items(),
            'meta' => [
                'current_page' => $entities->currentPage(),
                'last_page' => $entities->lastPage(),
                'total' => $entities->total(),
                'per_page' => $entities->perPage(),
            ],
        ]);
    }

    public function create(string $lang): Response
    {
        $language = $this->resolveLanguage($lang);

        return Inertia::render('Entities/Create', [
            'lang' => $lang,
            'language' => [
                'code' => $language->code,
                'name' => $language->name,
                'native_name' => $language->native_name,
            ],
        ]);
    }

    public function store(StoreEntityRequest $request, string $lang): RedirectResponse
    {
        $this->resolveLanguage($lang);

        $data = $request->validated();

        $filePath = null;
        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store("entities/{$lang}", 'local');
        }

        if ($filePath !== null) {
            $result = TextSignatureService::create()
                ->findSimilarExisting(TextSignatureService::readFileFromLocalPath($filePath), $lang);

            if ($result['signature'] === null) {
                Storage::disk('local')->delete($filePath);

                return back()->withErrors([
                    'file' => 'We could not process the text right now. Please try again later.',
                ]);
            }

            if ($result['entity'] !== null) {
                $this->access()->grant(
                    $request->user(),
                    $result['entity'],
                    (float) $result['similarity'],
                );

                Storage::disk('local')->delete($filePath);

                return redirect()->route('entities.show', [
                    'lang' => $lang,
                    'entity' => $result['entity']->getKey(),
                ])->with('status', 'Your upload matched an existing text — access granted, no new entity created.');
            }

            $signature = $result['signature'];
        }

        $entity = $this->queryForLanguage($lang)->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'file_path' => $filePath,
            'is_restricted' => true,
            'signature' => isset($signature) ? json_encode($signature) : null,
        ]);

        $this->access()->grant($request->user(), $entity, null);

        if ($filePath !== null) {
            ProcessEntityFile::dispatch($entity->id, $filePath, $lang);
        }

        return redirect()->route('entities.show', ['lang' => $lang, 'entity' => $entity->id]);
    }

    public function show(string $lang, int $entityId): Response
    {
        $language = $this->resolveLanguage($lang);

        $entity = $this->queryForLanguage($lang)
            ->withCount('sentences')
            ->findOrFail($entityId);

        if (! $this->access()->canRead(auth()->user(), $entity)) {
            abort(403);
        }

        $canEdit = $this->access()->canEdit(auth()->user(), $entity);

        $entityMatches = EnRuEntityMatch::query()
            ->where(function (Builder $query) use ($lang, $entityId): void {
                if ($lang === 'en') {
                    $query->where('en_entity_id', $entityId);
                } else {
                    $query->where('ru_entity_id', $entityId);
                }
            })
            ->get(['id', 'status'])
            ->map(fn (EnRuEntityMatch $match): array => [
                'id' => $match->id,
                'status' => $match->status,
            ]);

        $sentences = $entity->sentences()
            ->with('sentenceType')
            ->orderBy('order')
            ->paginate(20);

        return Inertia::render('Entities/Show', [
            'lang' => $lang,
            'language' => [
                'code' => $language->code,
                'name' => $language->name,
                'native_name' => $language->native_name,
            ],
            'entity' => [
                'id' => $entity->id,
                'name' => $entity->name,
                'description' => $entity->description,
                'file_path' => $entity->file_path,
                'signature_status' => $this->signatureStatus($entity),
                'sentences_count' => $entity->sentences_count,
                'created_at' => $entity->created_at?->toISOString(),
                'updated_at' => $entity->updated_at?->toISOString(),
            ],
            'entityMatches' => $entityMatches,
            'can_edit' => $canEdit,
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

        $entity = $this->queryForLanguage($lang)
            ->withCount('sentences')
            ->findOrFail($entityId);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $sentenceTypes = SentenceType::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $alignmentCount = $this->alignmentCount($lang, $entity->id);

        return Inertia::render('Entities/Edit', [
            'lang' => $lang,
            'language' => [
                'code' => $language->code,
                'name' => $language->name,
                'native_name' => $language->native_name,
            ],
            'entity' => [
                'id' => $entity->id,
                'name' => $entity->name,
                'description' => $entity->description,
                'is_restricted' => $entity->is_restricted,
                'sentences_count' => $entity->sentences_count,
            ],
            'sentenceTypes' => $sentenceTypes->map(fn (SentenceType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
            ])->all(),
            'alignmentCount' => $alignmentCount,
            'sentencesEndpoint' => route('entities.sentences', ['lang' => $lang, 'entity' => $entity->id]),
        ]);
    }

    public function update(UpdateEntityRequest $request, string $lang, int $entityId): RedirectResponse
    {
        $this->resolveLanguage($lang);

        $entity = $this->queryForLanguage($lang)->findOrFail($entityId);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $data = $request->validated();

        $entity->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return redirect()->route('entities.show', ['lang' => $lang, 'entity' => $entity->id])
            ->with('status', 'Entity updated.');
    }

    public function sentences(string $lang, int $entityId, Request $request): JsonResponse
    {
        $this->resolveLanguage($lang);

        $entity = $this->queryForLanguage($lang)->findOrFail($entityId);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $page = $request->integer('page', 1);
        $perPage = $this->normalizePerPage($request->integer('per_page', 25));

        return response()->json($this->pageResponse($lang, $entity, $page, $perPage));
    }

    public function storeSentence(StoreEntitySentenceRequest $request, string $lang, int $entityId): JsonResponse
    {
        $this->resolveLanguage($lang);

        $entity = $this->queryForLanguage($lang)->findOrFail($entityId);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $data = $request->validated();
        $content = trim((string) $data['content']);
        $afterSentenceId = $data['after_sentence_id'] ?? null;

        $sentence = DB::transaction(function () use ($lang, $entity, $content, $data, $afterSentenceId): Model {
            $sentenceClass = $this->sentenceClass($lang);
            $entityForeignKey = $this->entityForeignKey($lang);

            $sentences = $sentenceClass::query()
                ->where($entityForeignKey, $entity->id)
                ->orderBy('order')
                ->orderBy('id')
                ->get(['id', 'order'])
                ->map(fn ($row): array => ['key' => 's-'.$row->id, 'order' => (int) $row->order])
                ->values()
                ->all();

            $afterOrder = $this->resolveAfterOrder($lang, $afterSentenceId, $sentences);

            $result = $this->sparseOrder->orderForInsertAfter($sentences, null, $afterOrder);

            $result = $this->shiftOrdersNonNegative($result);

            $this->persistOrderChanges($lang, $entity->id, $result['items']);

            return $sentenceClass::query()->create([
                $entityForeignKey => $entity->id,
                'sentence_type_id' => (int) $data['sentence_type_id'],
                'content' => $content,
                'order' => $result['order'],
            ]);
        });

        $this->setMatchesPending($lang, $entity->id);

        $page = $request->integer('page', 1);
        $perPage = $this->normalizePerPage($request->integer('per_page', 25));
        $position = $this->positionOf($lang, $entity, $sentence);
        $targetPage = (int) floor($position / $perPage) + 1;

        return response()->json([
            'sentence' => $this->sentencePayload($lang, $sentence),
            ...$this->pageResponse($lang, $entity, $targetPage, $perPage),
        ]);
    }

    public function updateSentence(UpdateEntitySentenceRequest $request, string $lang, int $entityId, int $sentence): JsonResponse
    {
        $this->resolveLanguage($lang);

        $entity = $this->queryForLanguage($lang)->findOrFail($entityId);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $data = $request->validated();

        $sentenceModel = $this->findEntitySentence($lang, $entity->id, $sentence);

        $sentenceModel->update([
            'content' => trim((string) $data['content']),
            'sentence_type_id' => (int) $data['sentence_type_id'],
        ]);

        $this->setMatchesPending($lang, $entity->id);

        return response()->json([
            'sentence' => $this->sentencePayload($lang, $sentenceModel->refresh()),
        ]);
    }

    public function destroySentence(string $lang, int $entityId, int $sentence, Request $request): JsonResponse
    {
        $this->resolveLanguage($lang);

        $entity = $this->queryForLanguage($lang)->findOrFail($entityId);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $sentenceModel = $this->findEntitySentence($lang, $entity->id, $sentence);

        DB::transaction(fn () => $sentenceModel->delete());

        $this->setMatchesPending($lang, $entity->id);

        $page = $request->integer('page', 1);
        $perPage = $this->normalizePerPage($request->integer('per_page', 25));

        return response()->json($this->pageResponse($lang, $entity, $page, $perPage));
    }

    public function reorderSentences(ReorderEntitySentenceRequest $request, string $lang, int $entityId): JsonResponse
    {
        $this->resolveLanguage($lang);

        $entity = $this->queryForLanguage($lang)->findOrFail($entityId);

        abort_unless($this->access()->canEdit(auth()->user(), $entity), 403);

        $data = $request->validated();
        $sentenceId = (int) $data['sentence_id'];
        $afterSentenceId = $data['after_sentence_id'] ?? null;

        $sentenceModel = $this->findEntitySentence($lang, $entity->id, $sentenceId);

        DB::transaction(function () use ($lang, $entity, $sentenceModel, $afterSentenceId): void {
            $sentenceClass = $this->sentenceClass($lang);
            $entityForeignKey = $this->entityForeignKey($lang);

            $sentences = $sentenceClass::query()
                ->where($entityForeignKey, $entity->id)
                ->orderBy('order')
                ->orderBy('id')
                ->get(['id', 'order'])
                ->map(fn ($row): array => ['key' => 's-'.$row->id, 'order' => (int) $row->order])
                ->values()
                ->all();

            $afterOrder = $this->resolveAfterOrder($lang, $afterSentenceId, $sentences);

            $result = $this->sparseOrder->orderForInsertAfter($sentences, 's-'.$sentenceModel->id, $afterOrder);

            $result = $this->shiftOrdersNonNegative($result);

            $this->persistOrderChanges($lang, $entity->id, $result['items']);

            $this->setSentenceOrderRaw($lang, $sentenceModel->id, $result['order']);
        });

        $this->setMatchesPending($lang, $entity->id);

        $page = $request->integer('page', 1);
        $perPage = $this->normalizePerPage($request->integer('per_page', 25));
        $sentenceModel->refresh();
        $position = $this->positionOf($lang, $entity, $sentenceModel);
        $targetPage = (int) floor($position / $perPage) + 1;

        return response()->json($this->pageResponse($lang, $entity, $targetPage, $perPage));
    }

    private function resolveLanguage(string $lang): Language
    {
        return Language::query()
            ->enabled()
            ->where('code', $lang)
            ->firstOrFail();
    }

    private function queryForLanguage(string $lang): Builder
    {
        return match ($lang) {
            'en' => EnEntity::query(),
            'ru' => RuEntity::query(),
            default => abort(404),
        };
    }

    private function access(): EntityAccessService
    {
        return new EntityAccessService;
    }

    private function signatureStatus(EnEntity|RuEntity $entity): string
    {
        if ($entity->signature !== null) {
            return 'generated';
        }

        if ($entity->file_path !== null) {
            return 'pending';
        }

        return 'none';
    }

    private function sentenceClass(string $lang): string
    {
        return $lang === 'en' ? EnEntitySentence::class : RuEntitySentence::class;
    }

    private function entityForeignKey(string $lang): string
    {
        return $lang === 'en' ? 'en_entity_id' : 'ru_entity_id';
    }

    /**
     * Resolve the anchor order for an insert/reorder operation.
     * after_sentence_id = 0 means "at the beginning" (BEGINNING_SENTINEL);
     * null means "at the end" (max order, or BEGINNING_SENTINEL if empty);
     * any other integer is the sentence to insert after.
     *
     * @param  list<array{key: string, order: int}>  $sentences
     */
    private function resolveAfterOrder(string $lang, ?int $afterSentenceId, array $sentences): int
    {
        if ($afterSentenceId === 0) {
            return SparseOrderService::BEGINNING_SENTINEL;
        }

        if ($afterSentenceId !== null) {
            return (int) $this->sentenceClass($lang)::query()->whereKey($afterSentenceId)->value('order');
        }

        return $sentences !== [] ? (int) max(array_column($sentences, 'order')) : SparseOrderService::BEGINNING_SENTINEL;
    }

    private function findEntitySentence(string $lang, int $entityId, int $sentenceId): Model
    {
        $sentenceClass = $this->sentenceClass($lang);
        $entityForeignKey = $this->entityForeignKey($lang);

        $sentence = $sentenceClass::query()
            ->whereKey($sentenceId)
            ->where($entityForeignKey, $entityId)
            ->first();

        abort_if($sentence === null, 404);

        return $sentence;
    }

    /**
     * @param  list<array{key: string, order: int}>  $items
     */
    private function persistOrderChanges(string $lang, int $entityId, array $items): void
    {
        $sentenceClass = $this->sentenceClass($lang);
        $entityForeignKey = $this->entityForeignKey($lang);

        $currentOrders = $sentenceClass::query()
            ->where($entityForeignKey, $entityId)
            ->get(['id', 'order'])
            ->mapWithKeys(fn ($row): array => [$row->id => (int) $row->order]);

        foreach ($items as $item) {
            $id = (int) substr($item['key'], 2);

            if (($currentOrders->get($id) ?? null) !== $item['order']) {
                $this->setSentenceOrderRaw($lang, $id, $item['order']);
            }
        }
    }

    private function setSentenceOrderRaw(string $lang, int $sentenceId, int $order): void
    {
        $this->sentenceClass($lang)::query()->whereKey($sentenceId)->update(['order' => $order]);
    }

    /**
     * Flip every EnRuEntityMatch involving this entity to status = 'pending',
     * surfacing the need to re-align. See ADR 0015.
     */
    private function setMatchesPending(string $lang, int $entityId): void
    {
        $column = $lang === 'en' ? 'en_entity_id' : 'ru_entity_id';

        EnRuEntityMatch::query()
            ->where($column, $entityId)
            ->where('status', '!=', 'pending')
            ->update(['status' => 'pending']);
    }

    private function alignmentCount(string $lang, int $entityId): int
    {
        $column = $lang === 'en' ? 'en_entity_id' : 'ru_entity_id';

        return EnRuEntityMatch::query()->where($column, $entityId)->count();
    }

    /**
     * Paginated slice of an entity's sentences plus display metadata.
     *
     * @return array{sentences: list<array{id: int, order: int, content: string, sentence_type_id: int, type: ?string}>, meta: array{current_page: int, last_page: int, total: int, per_page: int}, before_first_id: ?int}
     */
    private function pageResponse(string $lang, EnEntity|RuEntity $entity, int $page, int $perPage): array
    {
        $sentenceClass = $this->sentenceClass($lang);
        $entityForeignKey = $this->entityForeignKey($lang);

        $query = $sentenceClass::query()
            ->where($entityForeignKey, $entity->id)
            ->orderBy('order')
            ->orderBy('id');

        $total = (clone $query)->count();
        $lastPage = max((int) ceil($total / $perPage), 1);
        $currentPage = min(max($page, 1), $lastPage);

        $items = (clone $query)
            ->forPage($currentPage, $perPage)
            ->get();

        $sentences = $items
            ->map(fn (object $sentence): array => $this->sentencePayload($lang, $sentence))
            ->all();

        $beforeFirstId = $currentPage > 1
            ? $this->sentenceIdAtOffset($lang, $entity, ($currentPage - 1) * $perPage - 1)
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

    private function sentenceIdAtOffset(string $lang, EnEntity|RuEntity $entity, int $offset): ?int
    {
        if ($offset < 0) {
            return null;
        }

        $sentenceClass = $this->sentenceClass($lang);
        $entityForeignKey = $this->entityForeignKey($lang);

        return $sentenceClass::query()
            ->where($entityForeignKey, $entity->id)
            ->orderBy('order')
            ->orderBy('id')
            ->offset($offset)
            ->limit(1)
            ->value('id');
    }

    /**
     * 0-based position of a sentence in the global (order, id) ordering.
     */
    private function positionOf(string $lang, EnEntity|RuEntity $entity, Model $sentence): int
    {
        $sentenceClass = $this->sentenceClass($lang);
        $entityForeignKey = $this->entityForeignKey($lang);

        return (int) $sentenceClass::query()
            ->where($entityForeignKey, $entity->id)
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
    private function sentencePayload(string $lang, Model $sentence): array
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
