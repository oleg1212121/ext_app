<?php

namespace App\Http\Controllers;

use App\Classes\AlignmentCopyService;
use App\Classes\AlignmentEditorApiPresenter;
use App\Classes\EntityAccessService;
use App\Classes\EntityCreationService;
use App\Http\Requests\StoreEntityMatchRequest;
use App\Http\Requests\StoreWorkEntityRequest;
use App\Http\Requests\StoreWorkRequest;
use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\Language;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Library — the work-first browse surface. Works are a public catalog
 * (every approved user sees every work, empty ones included; see ADR 0021);
 * entity and alignment lists stay scoped to what the user can read.
 */
class LibraryController extends Controller
{
    private const WORK_TABS = ['entities', 'alignments'];

    public function __construct(
        private readonly EntityAccessService $access,
        private readonly EntityCreationService $creation,
        private readonly AlignmentEditorApiPresenter $presenter,
        private readonly AlignmentCopyService $alignmentCopy = new AlignmentCopyService,
    ) {}

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $works = Work::query()
            ->with('originalLanguage')
            ->withCount(['entities' => $this->access->readableConstraint($request->user())])
            ->when($q !== '', function (Builder $query) use ($q): Builder {
                $needle = $this->searchNeedle($q);

                return $query->where(function (Builder $query) use ($needle): void {
                    $query->where('title', 'ilike', $needle)
                        ->orWhere('author', 'ilike', $needle);
                });
            })
            ->orderBy('title')
            ->paginate(15);

        return Inertia::render('Library/Index', [
            'q' => $q,
            'works' => $works->through(function (Work $work): array {
                return [
                    'id' => $work->id,
                    'title' => $work->title,
                    'author' => $work->author,
                    'description' => $work->description,
                    'original_language' => $this->languageRef($work),
                    'entities_count' => $work->entities_count,
                    'created_at' => $work->created_at?->toISOString(),
                ];
            })->items(),
            'meta' => [
                'current_page' => $works->currentPage(),
                'last_page' => $works->lastPage(),
                'total' => $works->total(),
                'per_page' => $works->perPage(),
            ],
        ]);
    }

    public function createWork(): Response
    {
        return Inertia::render('Library/CreateWork', [
            'languages' => $this->enabledLanguages(),
        ]);
    }

    public function storeWork(StoreWorkRequest $request): RedirectResponse
    {
        $work = Work::query()->create($request->validated());

        return redirect()->route('library.show', ['work' => $work->id]);
    }

    public function showWork(Request $request, int $work): Response
    {
        $work = Work::query()->with('originalLanguage')->findOrFail($work);
        $q = trim((string) $request->query('q', ''));
        $tab = in_array($request->query('tab'), self::WORK_TABS, true)
            ? $request->query('tab')
            : 'entities';

        return Inertia::render('Library/ShowWork', [
            'work' => [
                'id' => $work->id,
                'title' => $work->title,
                'author' => $work->author,
                'description' => $work->description,
                'original_language' => $this->languageRef($work),
                'created_at' => $work->created_at?->toISOString(),
            ],
            'tab' => $tab,
            'q' => $q,
            ...$tab === 'alignments'
                ? $this->workAlignmentsPayload($request, $work, $q)
                : $this->workEntitiesPayload($request, $work, $q),
        ]);
    }

    /**
     * The Entities tab: the work's readable per-language texts.
     *
     * @return array{entities: list<array<string, mixed>>, meta: array<string, int>}
     */
    private function workEntitiesPayload(Request $request, Work $work, string $q): array
    {
        $entities = $this->access->readableQuery($request->user())
            ->where('work_id', $work->id)
            ->with('language')
            ->withCount('sentences')
            ->when($q !== '', function (Builder $query) use ($q): Builder {
                $needle = $this->searchNeedle($q);

                return $query->where(function (Builder $query) use ($needle): void {
                    $query->where('name', 'ilike', $needle)
                        ->orWhere('label', 'ilike', $needle);
                });
            })
            ->orderBy('name')
            ->paginate(15);

        return [
            'entities' => $entities->through(function (Entity $entity): array {
                return [
                    'id' => $entity->id,
                    'name' => $entity->name,
                    'label' => $entity->label,
                    'description' => $entity->description,
                    'signature_status' => $entity->signatureStatus(),
                    'sentences_count' => $entity->sentences_count,
                    'language' => [
                        'code' => $entity->language?->code,
                        'name' => $entity->language?->name,
                    ],
                    'created_at' => $entity->created_at?->toISOString(),
                ];
            })->items(),
            'meta' => [
                'current_page' => $entities->currentPage(),
                'last_page' => $entities->lastPage(),
                'total' => $entities->total(),
                'per_page' => $entities->perPage(),
            ],
        ];
    }

    /**
     * The Alignments tab: the work's readable entity matches. A match has no
     * work_id of its own — same-work is enforced at creation (and every
     * mutation), so the A-side entity names the work.
     *
     * @return array{alignments: list<array<string, mixed>>, alignments_meta: array<string, int>}
     */
    private function workAlignmentsPayload(Request $request, Work $work, string $q): array
    {
        $nativeLanguageId = $request->user()->nativeLanguage()?->id;

        $matches = $this->access->readableMatchQuery($request->user())
            ->whereHas('aEntity', fn (Builder $query) => $query->where('work_id', $work->id))
            ->with(['aEntity.language', 'bEntity.language', 'aEntity.work'])
            ->when($q !== '', function (Builder $query) use ($q): Builder {
                $needle = $this->searchNeedle($q);

                return $query->where(function (Builder $query) use ($needle): void {
                    $query->whereHas('aEntity', fn (Builder $inner) => $inner->where('name', 'ilike', $needle))
                        ->orWhereHas('bEntity', fn (Builder $inner) => $inner->where('name', 'ilike', $needle));
                });
            })
            ->latest()
            ->paginate(15);

        return [
            'alignments' => $matches->through(function (EntityMatch $entityMatch) use ($nativeLanguageId): array {
                return [
                    ...$this->presenter->matchPayload($entityMatch),
                    'reader_target' => $this->readerTarget($entityMatch, $nativeLanguageId),
                ];
            })->items(),
            'alignments_meta' => [
                'current_page' => $matches->currentPage(),
                'last_page' => $matches->lastPage(),
                'total' => $matches->total(),
                'per_page' => $matches->perPage(),
            ],
        ];
    }

    /**
     * The side an alignment card's reader button opens: the language the user
     * is learning (not their native one), shown against the native side. When
     * both sides qualify, prefer the work's original language, then the
     * A-side.
     *
     * @return array{lang: string, entity_id: int}|null
     */
    private function readerTarget(EntityMatch $entityMatch, ?int $nativeLanguageId): ?array
    {
        $sides = ['a' => $entityMatch->aEntity, 'b' => $entityMatch->bEntity];

        $nonNative = array_keys(array_filter(
            $sides,
            fn (?Entity $entity): bool => $entity !== null && $entity->language_id !== $nativeLanguageId,
        ));

        $originalSide = $entityMatch->originalSide();

        // One learning side wins outright; otherwise (both or neither are
        // non-native) the work's original side breaks the tie, then the A-side.
        $side = count($nonNative) === 1
            ? $nonNative[0]
            : ($originalSide ?? 'a');

        $entity = $sides[$side] ?? null;

        if ($entity === null || $entity->language?->code === null) {
            return null;
        }

        return [
            'lang' => $entity->language->code,
            'entity_id' => $entity->id,
        ];
    }

    public function createEntity(int $work): Response
    {
        $work = Work::query()->findOrFail($work);

        return Inertia::render('Library/CreateEntity', [
            'work' => [
                'id' => $work->id,
                'title' => $work->title,
                'author' => $work->author,
            ],
            'languages' => $this->enabledLanguages(),
        ]);
    }

    public function storeEntity(StoreWorkEntityRequest $request, int $work): RedirectResponse
    {
        $work = Work::query()->findOrFail($work);
        $language = Language::query()->enabled()->findOrFail((int) $request->validated('language_id'));

        $result = $this->creation->create(
            $request->user(),
            $work,
            $language,
            $request->validated(),
            $request->file('file'),
        );

        return $this->redirectFromCreation($result, $language->code);
    }

    public function createAlignment(Request $request, int $work): Response
    {
        $work = Work::query()->findOrFail($work);

        return Inertia::render('Library/CreateAlignment', [
            'work' => [
                'id' => $work->id,
                'title' => $work->title,
                'author' => $work->author,
            ],
            'entities' => $this->alignableEntities($request->user(), $work),
        ]);
    }

    public function storeAlignment(StoreEntityMatchRequest $request, int $work): RedirectResponse
    {
        $work = Work::query()->findOrFail($work);

        $data = $request->validated();

        $firstEntity = Entity::find($data['first_entity_id']);
        $secondEntity = Entity::find($data['second_entity_id']);

        if ($firstEntity === null || $secondEntity === null
            || ! $this->access->canRead($request->user(), $firstEntity)
            || ! $this->access->canRead($request->user(), $secondEntity)) {
            abort(403);
        }

        // The form only lists this work's entities; a forged pair from
        // another work is rejected here.
        if ($firstEntity->work_id !== $work->id || $secondEntity->work_id !== $work->id) {
            return back()->withErrors([
                'second_entity_id' => 'Both entities must belong to the selected work.',
            ]);
        }

        // Same-language pairs are valid (e.g. a book of exercises and its
        // answer key); the a/b sides stay canonical by entity id.

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

        // An exact-copy pair reuses a completed alignment instead of running
        // the (potentially half-hour) pipeline.
        if ($this->alignmentCopy->copyFor($entityMatch)) {
            return redirect()
                ->route('library.show', ['work' => $work->id, 'tab' => 'alignments'])
                ->with('success', 'Entity match created — alignment copied from an identical text pair.');
        }

        AlignEntitySentences::beginFromScratch($entityMatch->id);

        return redirect()
            ->route('library.show', ['work' => $work->id, 'tab' => 'alignments'])
            ->with('success', 'Entity match created — alignment started.');
    }

    /**
     * The work's alignable entities (readable, signature generated, sentences
     * split), grouped by language code for the create form's selects.
     *
     * @return array<string, list<array{id: int, text: string}>>
     */
    private function alignableEntities(User $user, Work $work): array
    {
        $eligible = $this->access->readableQuery($user)
            ->where('work_id', $work->id)
            ->whereNotNull('signature')
            ->has('sentences')
            ->with('language')
            ->orderBy('name')
            ->get();

        $byLanguage = [];

        foreach ($eligible as $entity) {
            $code = $entity->language?->code ?? '?';

            $byLanguage[$code][] = [
                'id' => $entity->id,
                'text' => $entity->name.($entity->label !== null ? " ({$entity->label})" : ''),
            ];
        }

        return $byLanguage;
    }

    /**
     * Map an EntityCreationService outcome to its redirect: the freshly
     * created entity's page, with an extra status when the upload was an
     * exact copy and the entity was cloned with precomputed derivations.
     * Mirrors EntityController::store.
     *
     * @param  array{status: string, entity: Entity, source: ?Entity}  $result
     */
    private function redirectFromCreation(array $result, string $lang): RedirectResponse
    {
        $redirect = redirect()->route('entities.show', ['lang' => $lang, 'entity' => $result['entity']->id]);

        if ($result['status'] === 'created_from_copy') {
            return $redirect->with('status', 'Your upload is an exact copy of an existing text — your own entity was created with sentences and word statistics precomputed.');
        }

        return $redirect;
    }

    /**
     * @return list<array{id: int, code: string, name: string, native_name: ?string}>
     */
    private function enabledLanguages(): array
    {
        return Language::query()
            ->enabled()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Language $language): array => [
                'id' => $language->id,
                'code' => $language->code,
                'name' => $language->name,
                'native_name' => $language->native_name,
            ])
            ->all();
    }

    /**
     * @return array{code: string, name: string}|null
     */
    private function languageRef(Work $work): ?array
    {
        return $work->originalLanguage !== null ? [
            'code' => $work->originalLanguage->code,
            'name' => $work->originalLanguage->name,
        ] : null;
    }

    private function searchNeedle(string $q): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
    }
}
