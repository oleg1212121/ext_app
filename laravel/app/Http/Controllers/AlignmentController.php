<?php

namespace App\Http\Controllers;

use App\Classes\AlignmentEditorApiPresenter;
use App\Classes\EntityAccessService;
use App\Http\Requests\StoreEntityMatchRequest;
use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\Language;
use App\Models\Work;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
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
            ->with(['aEntity.language', 'bEntity.language', 'aEntity.work'])
            ->latest()
            ->paginate(15);

        return Inertia::render('Alignments/Index', [
            'entityMatches' => $entityMatches->through(
                fn (EntityMatch $entityMatch): array => $this->presenter->matchPayload($entityMatch),
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
            'works' => $this->alignableWorks(),
            'languages' => Language::query()->enabled()->orderBy('sort_order')->get()
                ->map(fn (Language $language): array => [
                    'code' => $language->code,
                    'name' => $language->name,
                ])->all(),
        ]);
    }

    public function store(StoreEntityMatchRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $firstEntity = Entity::find($data['first_entity_id']);
        $secondEntity = Entity::find($data['second_entity_id']);

        if ($firstEntity === null || $secondEntity === null
            || ! $this->access()->canRead($request->user(), $firstEntity)
            || ! $this->access()->canRead($request->user(), $secondEntity)) {
            abort(403);
        }

        if ($firstEntity->work_id !== $secondEntity->work_id) {
            return back()->withErrors([
                'second_entity_id' => 'Both entities must belong to the same work.',
            ]);
        }

        if ($firstEntity->language_id === $secondEntity->language_id) {
            return back()->withErrors([
                'second_entity_id' => 'Both entities must be in different languages.',
            ]);
        }

        // Canonical pair order: the lower entity id is always the a side, so
        // the unique(a_entity_id, b_entity_id) constraint covers both orders.
        [$aEntityId, $bEntityId] = [
            min((int) $data['first_entity_id'], (int) $data['second_entity_id']),
            max((int) $data['first_entity_id'], (int) $data['second_entity_id']),
        ];

        $existing = EntityMatch::query()
            ->where('a_entity_id', $aEntityId)
            ->where('b_entity_id', $bEntityId)
            ->first();

        if ($existing !== null) {
            return back()
                ->withErrors(['second_entity_id' => 'A match for this entity pair already exists.'])
                ->with('existing_match_id', $existing->id);
        }

        $entityMatch = EntityMatch::create([
            'a_entity_id' => $aEntityId,
            'b_entity_id' => $bEntityId,
            'chunk_size' => (int) $data['chunk_size'],
            'max_n' => (int) $data['max_n'],
            'status' => 'pending',
        ]);

        AlignEntitySentences::beginFromScratch($entityMatch->id);

        return redirect()->route('alignments.index')
            ->with('success', 'Entity match created — alignment started.');
    }

    public function show(EntityMatch $entityMatch): Response
    {
        abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403);

        $entityMatch->load(['aEntity.language', 'bEntity.language', 'aEntity.work.originalLanguage']);

        $payload = $this->presenter->rowsPagePayload($entityMatch, 1, 25);

        return Inertia::render('Alignments/Show', [
            'match' => $this->presenter->matchPayload($entityMatch),
            'rows' => $payload['rows'],
            'rows_meta' => $payload['meta'],
            'sentences_before' => $payload['sentences_before'],
            'unmatched_a' => $this->presenter->unmatchedPayload($entityMatch, 'a', 1),
            'unmatched_b' => $this->presenter->unmatchedPayload($entityMatch, 'b', 1),
            'needs_review' => $this->presenter->needsReviewPagePayload($entityMatch, 1),
        ]);
    }

    /**
     * Works that have at least two eligible entities in distinct languages:
     * each work carries its eligible entities grouped by language code.
     *
     * @return list<array<string, mixed>>
     */
    private function alignableWorks(): array
    {
        $eligible = $this->access()
            ->readableQuery(auth()->user())
            ->whereNotNull('signature')
            ->has('sentences')
            ->with('language')
            ->orderBy('name')
            ->get();

        return Work::query()
            ->whereKey($eligible->pluck('work_id')->unique()->all())
            ->orderBy('title')
            ->get()
            ->map(function (Work $work) use ($eligible): array {
                /** @var Collection<int, Entity> $workEntities */
                $workEntities = $eligible->where('work_id', $work->id)->values();

                $byLanguage = [];

                foreach ($workEntities as $entity) {
                    $code = $entity->language?->code ?? '?';

                    $byLanguage[$code][] = [
                        'id' => $entity->id,
                        'text' => $entity->name.($entity->label !== null ? " ({$entity->label})" : ''),
                    ];
                }

                if (count($byLanguage) < 2) {
                    return [];
                }

                return [
                    'id' => $work->id,
                    'title' => $work->title,
                    'entities' => $byLanguage,
                ];
            })
            ->filter(fn (array $work): bool => $work !== [])
            ->values()
            ->all();
    }
}
