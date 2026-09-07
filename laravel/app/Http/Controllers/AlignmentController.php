<?php

namespace App\Http\Controllers;

use App\Classes\AlignmentEditorApiPresenter;
use App\Classes\EntityAccessService;
use App\Http\Requests\StoreEnRuEntityMatchRequest;
use App\Jobs\AlignEntitySentences;
use App\Models\EnEntity;
use App\Models\EnRuEntityMatch;
use App\Models\RuEntity;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AlignmentController extends Controller
{
    public function __construct(
        private readonly AlignmentEditorApiPresenter $presenter,
    ) {}

    private function access(): EntityAccessService
    {
        return new EntityAccessService;
    }

    public function index(): Response
    {
        $entityMatches = $this->access()
            ->readableMatchQuery(auth()->user())
            ->with(['enEntity', 'ruEntity'])
            ->latest()
            ->paginate(15);

        return Inertia::render('Alignments/Index', [
            'entityMatches' => $entityMatches->through(
                fn (EnRuEntityMatch $entityMatch): array => $this->presenter->matchPayload($entityMatch),
            )->items(),
            'meta' => [
                'current_page' => $entityMatches->currentPage(),
                'last_page' => $entityMatches->lastPage(),
                'total' => $entityMatches->total(),
                'per_page' => $entityMatches->perPage(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Alignments/Create', [
            'enEntities' => $this->eligibleEntities('en'),
            'ruEntities' => $this->eligibleEntities('ru'),
        ]);
    }

    public function store(StoreEnRuEntityMatchRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $enEntity = EnEntity::find($data['en_entity_id']);
        $ruEntity = RuEntity::find($data['ru_entity_id']);

        if ($enEntity === null || $ruEntity === null
            || ! $this->access()->canRead($request->user(), $enEntity)
            || ! $this->access()->canRead($request->user(), $ruEntity)) {
            abort(403);
        }

        $existing = EnRuEntityMatch::query()
            ->where('en_entity_id', $data['en_entity_id'])
            ->where('ru_entity_id', $data['ru_entity_id'])
            ->first();

        if ($existing !== null) {
            return back()
                ->withErrors(['ru_entity_id' => 'A match for this entity pair already exists.'])
                ->with('existing_match_id', $existing->id);
        }

        $entityMatch = EnRuEntityMatch::create([
            'en_entity_id' => (int) $data['en_entity_id'],
            'ru_entity_id' => (int) $data['ru_entity_id'],
            'is_original_en' => (bool) $data['is_original_en'],
            'chunk_size' => (int) $data['chunk_size'],
            'max_n' => (int) $data['max_n'],
            'status' => 'pending',
        ]);

        AlignEntitySentences::beginFromScratch($entityMatch->id);

        return redirect()->route('alignments.index')
            ->with('success', 'Entity match created — alignment started.');
    }

    /**
     * Entities the user may read and that are ready to be aligned: they carry
     * a generated signature and at least one sentence on the side.
     *
     * @return list<array{id: int, text: string}>
     */
    private function eligibleEntities(string $lang): array
    {
        return $this->access()
            ->readableQuery(auth()->user(), $lang)
            ->whereNotNull('signature')
            ->has('sentences')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (EnEntity|RuEntity $entity): array => [
                'id' => $entity->id,
                'text' => $entity->name,
            ])
            ->all();
    }

    public function show(EnRuEntityMatch $entityMatch): Response
    {
        abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403);

        $entityMatch->load(['enEntity', 'ruEntity']);

        $payload = $this->presenter->rowsPagePayload($entityMatch, 1, 25);

        return Inertia::render('Alignments/Show', [
            'match' => $this->presenter->matchPayload($entityMatch),
            'rows' => $payload['rows'],
            'rows_meta' => $payload['meta'],
            'sentences_before' => $payload['sentences_before'],
            'unmatched_en' => $this->presenter->unmatchedPayload($entityMatch, 'en', 1),
            'unmatched_ru' => $this->presenter->unmatchedPayload($entityMatch, 'ru', 1),
            'needs_review' => $this->presenter->needsReviewPagePayload($entityMatch, 1),
        ]);
    }
}
