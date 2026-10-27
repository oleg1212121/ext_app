<?php

namespace App\Http\Controllers;

use App\Classes\AIModelResolver;
use App\Classes\AlignmentEditorApiPresenter;
use App\Classes\Enrichment\EnricherRegistry;
use App\Classes\EntityAccessService;
use App\Classes\MeaningMatchPresenter;
use App\Classes\ReadingRowsPresenter;
use App\Http\Requests\ReaderPageRequest;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\Language;
use App\Models\User;
use App\Models\Work;
use App\Support\SavedUiSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class ReaderController extends Controller
{
    /**
     * Rows per reader page — the same page size the simulator pages
     * meaning matches at, and deliberately fixed: no per-page selector.
     */
    private const ROWS_PER_PAGE = 50;

    public function __construct(
        protected MeaningMatchPresenter $presenter,
        protected ReadingRowsPresenter $readingRows,
        protected AIModelResolver $modelResolver,
        protected EnricherRegistry $enrichers,
        protected EntityAccessService $access,
        protected AlignmentEditorApiPresenter $alignmentPresenter,
    ) {}

    /**
     * The text library (Practice → Reader): language tabs over the list of
     * readable texts. Bare /reader derives the user's native enabled
     * language, falling back to en.
     */
    public function index(?string $lang = null): Response
    {
        if ($lang === null) {
            $native = auth()->user()->nativeLanguage();
            $lang = ($native?->is_enabled ?? false) ? $native->code : 'en';
        }

        $language = $this->resolveLanguage($lang);

        return Inertia::render('ReaderIndex', [
            'lang' => $lang,
            // Objects with display names (not bare codes): the index page's
            // pending-alignment label names any language from this list.
            'languages' => Language::query()
                ->enabled()
                ->orderBy('sort_order')
                ->get(['code', 'name', 'native_name'])
                ->map(fn (Language $language): array => [
                    'code' => $language->code,
                    'name' => $language->name,
                    'native_name' => $language->native_name,
                ])
                ->all(),
            'entities' => $this->entitiesForLanguage($language),
        ]);
    }

    public function show(ReaderPageRequest $request, int $entityId): Response
    {
        $entity = Entity::query()->with('language')->findOrFail($entityId);

        if (! $this->access->canRead(auth()->user(), $entity)) {
            abort(403);
        }

        $nativeLanguageId = auth()->user()->nativeLanguage()?->id;

        ['rows' => $rows, 'sideEntities' => $sideEntities, 'meta' => $meta, 'positionKey' => $positionKey, 'defaultSide' => $defaultSide] = $this->buildRows($entity, $nativeLanguageId, $request->page());

        ['wordMaps' => $wordMaps, 'highlightable' => $highlightable, 'explainable' => $explainable] = $this->readingRows->wordMapsFor($sideEntities, (int) auth()->id(), $nativeLanguageId, $rows);
        $saved = SavedUiSettings::section(auth()->user(), 'reader');

        // Rows are canonical a/b (ADR 0060) — which side reads first is the
        // client's flip around defaultSide. Languages, word maps and the
        // eligibility flags ride the same side letters, so the client needs
        // no pair-flip knowledge at all; a single-language text has no b side.
        return Inertia::render('Reader', [
            'entity' => [
                'id' => $entity->id,
                'name' => $entity->name,
            ],
            'rows' => $rows,
            'defaultSide' => $defaultSide,
            'langs' => [
                'a' => $sideEntities['a']?->language?->code,
                'b' => $sideEntities['b']?->language?->code,
            ],
            'meta' => $meta,
            'positionKey' => $positionKey,
            'fontSize' => SavedUiSettings::int($saved, 'font_size', ...SavedUiSettings::READER_FONT_SIZE),
            'highlight' => SavedUiSettings::bool($saved, 'highlight', true),
            // The annotation display preferences (ADR 0067): one prop per
            // registry annotation, camelCased from its setting key —
            // stressMarks, phrasalVerbs, ...
            ...SavedUiSettings::annotations($saved, $this->enrichers->annotations()),
            'wordMaps' => $wordMaps,
            'highlightable' => $highlightable,
            // The AI explanation tab follows the same "not your native
            // language" rule as highlighting; the model itself is the user's
            // stored explanation preference, resolved server-side.
            'explainable' => $explainable,
            'explain' => $this->modelResolver->explainConfig(),
        ]);
    }

    /**
     * The work-scoped ENTITY reader — the split's always-monolingual half:
     * one row per sentence, no translation column and no language radio,
     * even when the entity sits in a readable match (that match belongs to
     * the alignment reader, whose list the header cross-links to).
     */
    public function entityShow(ReaderPageRequest $request, int $workId, int $entityId): Response
    {
        $work = Work::query()->findOrFail($workId);

        // The entity is scoped to the URL's work: a mismatched pair of ids
        // is a bad URL, not a permission problem (wrong-work 404 is the
        // established pattern).
        $entity = Entity::query()
            ->where('work_id', $work->id)
            ->with('language')
            ->find($entityId);

        if ($entity === null) {
            abort(404);
        }

        if (! $this->access->canRead(auth()->user(), $entity)) {
            abort(403);
        }

        [
            'rows' => $rows,
            'sideEntities' => $sideEntities,
            'meta' => $meta,
            'positionKey' => $positionKey,
            'defaultSide' => $defaultSide,
        ] = $this->singleLanguageRows($entity, $request->page());

        return $this->renderWorkReader(
            $entity,
            $work,
            $this->entityCrossLinks($work),
            $rows,
            $sideEntities,
            $meta,
            $positionKey,
            $defaultSide,
        );
    }

    /**
     * The work-scoped ALIGNMENT reader — the bilingual half: rows from the
     * match's meaning matches, the behaviour the old auto-detecting reader
     * had when both sides were readable. Unlike that path there is no
     * single-language fallback: the entity reader is the fallback, and it
     * is a different URL.
     */
    public function alignmentShow(ReaderPageRequest $request, int $workId, int $matchId): Response
    {
        $work = Work::query()->findOrFail($workId);

        $match = EntityMatch::query()
            ->with(['aEntity.work', 'aEntity.language', 'bEntity.language'])
            ->findOrFail($matchId);

        // An EntityMatch has no work of its own — the work comes via the
        // A-side entity (canonical a/b: the A-side of every same-work pair
        // lives inside that work). A match anchored elsewhere under this
        // work's URL is a bad URL.
        if ($match->aEntity === null || (int) $match->aEntity->work_id !== $work->id) {
            abort(404);
        }

        if (! $this->access->canReadMatch(auth()->user(), $match)) {
            abort(403);
        }

        $defaultSide = $match->readingSideFor(auth()->user()->nativeLanguage()?->id);

        $paginator = $this->paginateRows(
            $this->presenter->meaningMatchesQuery($match),
            $request->page(),
        );

        return $this->renderWorkReader(
            $defaultSide === 'a' ? $match->aEntity : $match->bEntity,
            $work,
            $this->alignmentCrossLinks($work, $match),
            $this->readingRows->toReadingRows($paginator->getCollection()),
            ['a' => $match->aEntity, 'b' => $match->bEntity],
            $this->readingRows->metaFor($paginator),
            'mm:'.$match->id,
            $defaultSide,
        );
    }

    /**
     * The work's sections page — the work browse middle of the reader flow:
     * the work header plus one entry card per section. Both card counts
     * already ship with their final rules, so the alignments ticket only
     * adds its card + list, never a payload change here.
     */
    public function workShow(Request $request, int $workId): Response
    {
        $work = Work::query()
            ->with('originalLanguage')
            ->findOrFail($workId);

        return Inertia::render('Reader/WorkHome', [
            'work' => [
                'id' => $work->id,
                'title' => $work->title,
                'author' => $work->author,
                'description' => $work->description,
                'original_language' => $this->languageRef($work),
            ],
            'entities_count' => $work
                ->entities()
                ->where($this->access->readableConstraint($request->user()))
                ->count(),
            'alignments_count' => $this->readableWorkAlignments($work, $request->user())->count(),
        ]);
    }

    /**
     * The work's texts list — every entity of the work the user can read,
     * searchable by name/label and filterable to one entity language. The
     * reader-side twin of the Library's entities branch, minus the
     * management affordances (no add card, no badges).
     */
    public function workEntities(Request $request, int $workId): Response
    {
        $work = Work::query()->findOrFail($workId);
        $q = trim((string) $request->query('q', ''));
        $lang = trim((string) $request->query('lang', ''));

        // The filter only accepts enabled languages: an unknown or disabled
        // code is a bad URL, not an empty list.
        $language = $lang === ''
            ? null
            : Language::query()->enabled()->where('code', $lang)->firstOrFail();

        $entities = $this->access
            ->readableQuery($request->user(), $language?->id)
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

        return Inertia::render('Reader/WorkEntitiesList', [
            'work' => [
                'id' => $work->id,
                'title' => $work->title,
                'author' => $work->author,
            ],
            'q' => $q,
            'lang' => $lang,
            // Objects with display names (not bare codes): the language
            // select labels each option "name — native_name".
            'languages' => Language::query()
                ->enabled()
                ->orderBy('sort_order')
                ->get(['code', 'name', 'native_name'])
                ->map(fn (Language $language): array => [
                    'code' => $language->code,
                    'name' => $language->name,
                    'native_name' => $language->native_name,
                ])
                ->all(),
            'entities' => $entities->through(function (Entity $entity): array {
                return [
                    'id' => $entity->id,
                    'name' => $entity->name,
                    'label' => $entity->label,
                    'sentences_count' => $entity->sentences_count,
                    'language' => [
                        'code' => $entity->language?->code,
                        'name' => $entity->language?->name,
                    ],
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

    /**
     * The work's alignments list — the matches of the work the user can
     * read at status completed or stale, searchable by either side's entity
     * name and filterable to a language of either side. The reading-surface
     * twin of the Library's alignments branch: no reader_target here — the
     * cards link the match-anchored alignment reader, which owns the side
     * rule. The base query is the same builder the sections page's
     * Alignments card counts, so card count and list total agree.
     */
    public function workAlignments(Request $request, int $workId): Response
    {
        $work = Work::query()->findOrFail($workId);
        $q = trim((string) $request->query('q', ''));
        $lang = trim((string) $request->query('lang', ''));

        // The filter only accepts enabled languages: an unknown or disabled
        // code is a bad URL, not an empty list.
        $language = $lang === ''
            ? null
            : Language::query()->enabled()->where('code', $lang)->firstOrFail();

        $matches = $this->readableWorkAlignments($work, $request->user())
            ->with(['aEntity.language', 'bEntity.language', 'aEntity.work'])
            ->when($q !== '', function (Builder $query) use ($q): Builder {
                $needle = $this->searchNeedle($q);

                return $query->where(function (Builder $query) use ($needle): void {
                    $query->whereHas('aEntity', fn (Builder $inner) => $inner->where('name', 'ilike', $needle))
                        ->orWhereHas('bEntity', fn (Builder $inner) => $inner->where('name', 'ilike', $needle));
                });
            })
            ->when($language !== null, function (Builder $query) use ($language): Builder {
                return $query->where(function (Builder $query) use ($language): void {
                    $query->whereHas('aEntity', fn (Builder $inner) => $inner->where('language_id', $language->id))
                        ->orWhereHas('bEntity', fn (Builder $inner) => $inner->where('language_id', $language->id));
                });
            })
            ->latest()
            ->paginate(15);

        return Inertia::render('Reader/WorkAlignmentsList', [
            'work' => [
                'id' => $work->id,
                'title' => $work->title,
                'author' => $work->author,
            ],
            'q' => $q,
            'lang' => $lang,
            // Objects with display names (not bare codes): the language
            // select labels each option "name — native_name".
            'languages' => Language::query()
                ->enabled()
                ->orderBy('sort_order')
                ->get(['code', 'name', 'native_name'])
                ->map(fn (Language $language): array => [
                    'code' => $language->code,
                    'name' => $language->name,
                    'native_name' => $language->native_name,
                ])
                ->all(),
            'alignments' => $matches->through(fn (EntityMatch $entityMatch): array => $this->alignmentPresenter->matchPayload($entityMatch))->items(),
            'meta' => [
                'current_page' => $matches->currentPage(),
                'last_page' => $matches->lastPage(),
                'total' => $matches->total(),
                'per_page' => $matches->perPage(),
            ],
        ]);
    }

    /**
     * The props assembly shared by the two work-scoped readers — the same
     * shape the auto-detecting show() ships (rows/langs/meta/positionKey/
     * fontSize/annotations/wordMaps/highlightable/explainable/explain),
     * plus the split's header props: the scoping work and the cross-links
     * ReaderApp renders near the title.
     *
     * @param  array{a: Entity|null, b: Entity|null}  $sideEntities
     * @param  array{current_page: int, per_page: int, total: int, last_page: int}  $meta
     * @param  list<array{href: string, label: string}>  $crossLinks
     */
    private function renderWorkReader(
        Entity $entity,
        Work $work,
        array $crossLinks,
        array $rows,
        array $sideEntities,
        array $meta,
        string $positionKey,
        ?string $defaultSide,
    ): Response {
        ['wordMaps' => $wordMaps, 'highlightable' => $highlightable, 'explainable' => $explainable] = $this->readingRows->wordMapsFor($sideEntities, (int) auth()->id(), auth()->user()->nativeLanguage()?->id, $rows);
        $saved = SavedUiSettings::section(auth()->user(), 'reader');

        // Rows are canonical a/b (ADR 0060) — which side reads first is the
        // client's flip around defaultSide. Languages, word maps and the
        // eligibility flags ride the same side letters, so the client needs
        // no pair-flip knowledge at all; a single-language text has no b side.
        return Inertia::render('Reader', [
            'entity' => [
                'id' => $entity->id,
                'name' => $entity->name,
            ],
            'work' => [
                'id' => $work->id,
                'title' => $work->title,
            ],
            'crossLinks' => $crossLinks,
            'rows' => $rows,
            'defaultSide' => $defaultSide,
            'langs' => [
                'a' => $sideEntities['a']?->language?->code,
                'b' => $sideEntities['b']?->language?->code,
            ],
            'meta' => $meta,
            'positionKey' => $positionKey,
            'fontSize' => SavedUiSettings::int($saved, 'font_size', ...SavedUiSettings::READER_FONT_SIZE),
            'highlight' => SavedUiSettings::bool($saved, 'highlight', true),
            // The annotation display preferences (ADR 0067): one prop per
            // registry annotation, camelCased from its setting key —
            // stressMarks, phrasalVerbs, ...
            ...SavedUiSettings::annotations($saved, $this->enrichers->annotations()),
            'wordMaps' => $wordMaps,
            'highlightable' => $highlightable,
            // The AI explanation tab follows the same "not your native
            // language" rule as highlighting; the model itself is the user's
            // stored explanation preference, resolved server-side.
            'explainable' => $explainable,
            'explain' => $this->modelResolver->explainConfig(),
        ]);
    }

    /**
     * The entity reader's header cross-link: the work's alignments list —
     * an entity can sit in many matches, so we link the list, not one
     * match. Present only while the work has at least one match the user
     * may read at status completed or stale (pending/aligning/failed have
     * no meaning-match rows to read).
     *
     * @return list<array{href: string, label: string}>
     */
    private function entityCrossLinks(Work $work): array
    {
        // The same count rule the sections page's Alignments card uses, so
        // the header link appears exactly when the card's count is non-zero.
        $hasReadableAlignments = $this->readableWorkAlignments($work, auth()->user())->exists();

        if (! $hasReadableAlignments) {
            return [];
        }

        return [
            [
                // Relative like the other cross-link hrefs: the payload
                // carries paths, not host-absolute URLs.
                'href' => route('reader.works.alignments.index', ['work' => $work->id], absolute: false),
                'label' => __('reader.alignments'),
            ],
        ];
    }

    /**
     * The alignment reader's header cross-links: each side's entity, linked
     * to its monolingual reader.
     *
     * @return list<array{href: string, label: string}>
     */
    private function alignmentCrossLinks(Work $work, EntityMatch $match): array
    {
        $links = [];

        foreach (['a', 'b'] as $side) {
            $entity = $side === 'a' ? $match->aEntity : $match->bEntity;

            $links[] = [
                'href' => '/reader/works/'.$work->id.'/entities/'.$entity->id,
                'label' => $entity->name,
            ];
        }

        return $links;
    }

    /**
     * The work's readable completed/stale alignments — the count rule
     * shared by the sections page's Alignments count and the entity
     * reader's cross-link presence check (both sides readable; pending,
     * aligning and failed matches have no meaning-match rows to read).
     *
     * The work comes via the A-side entity: an EntityMatch has no work_id
     * of its own, and going through Work::alignments()' hasManyThrough join
     * turns unqualified column names ambiguous in Postgres.
     *
     * @return Builder<EntityMatch>
     */
    private function readableWorkAlignments(Work $work, User $user): Builder
    {
        return EntityMatch::query()
            ->whereHas('aEntity', fn (Builder $query): Builder => $query->where('work_id', $work->id))
            ->whereIn('status', ['completed', 'stale'])
            ->where($this->access->readableMatchConstraint($user));
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

    /**
     * The Library's ilike search needle: %-wrapped with the user's
     * wildcards escaped, so a % in a name matches literally.
     */
    private function searchNeedle(string $q): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
    }

    /**
     * @return array{rows: list<array<string, mixed>>, sideEntities: array{a: Entity|null, b: Entity|null}, meta: array{current_page: int, per_page: int, total: int, last_page: int}, positionKey: string, defaultSide: string|null}
     */
    private function buildRows(Entity $entity, ?int $nativeLanguageId, int $page): array
    {
        $entityMatch = EntityMatch::query()
            ->where(function ($query) use ($entity): void {
                $query->where('a_entity_id', $entity->id)
                    ->orWhere('b_entity_id', $entity->id);
            })
            ->with(['aEntity.language', 'aEntity.work', 'bEntity.language', 'bEntity.work'])
            ->first();

        if ($entityMatch === null) {
            return $this->singleLanguageRows($entity, $page);
        }

        // The URL entity only anchors the match; the side rule — native side
        // translates, then the work's original, then the A-side — picks
        // which language reads first. It ships as defaultSide: the rows
        // themselves stay canonical a/b and the client flips (ADR 0060).
        $defaultSide = $entityMatch->readingSideFor($nativeLanguageId);
        $readingEntity = $defaultSide === 'a' ? $entityMatch->aEntity : $entityMatch->bEntity;
        $translationEntity = $defaultSide === 'a' ? $entityMatch->bEntity : $entityMatch->aEntity;

        if ($readingEntity === null || $translationEntity === null
            || ! $this->access->canRead(auth()->user(), $readingEntity)
            || ! $this->access->canRead(auth()->user(), $translationEntity)) {
            return $this->singleLanguageRows($entity, $page);
        }

        $paginator = $this->paginateRows(
            $this->presenter->meaningMatchesQuery($entityMatch),
            $page,
        );

        // The position key is the entity match: both reading sides page
        // through the same rows.
        return [
            'rows' => $this->readingRows->toReadingRows($paginator->getCollection()),
            'sideEntities' => ['a' => $entityMatch->aEntity, 'b' => $entityMatch->bEntity],
            'meta' => $this->readingRows->metaFor($paginator),
            'positionKey' => 'mm:'.$entityMatch->id,
            'defaultSide' => $defaultSide,
        ];
    }

    /**
     * Rows of the bare entity, one Reading row per sentence with no
     * translation side. Illustrations ride their row as the image sentence
     * shape, caption as text.
     *
     * @return array{rows: list<array<string, mixed>>, sideEntities: array{a: Entity, b: null}, meta: array{current_page: int, per_page: int, total: int, last_page: int}, positionKey: string, defaultSide: string|null}
     */
    private function singleLanguageRows(Entity $entity, int $page): array
    {
        // The annotation columns ride along so the presenter can ship them
        // (ADR 0067) — one per registry annotation.
        $columns = ['id', 'content', 'image_path', 'image_width', 'image_height'];

        foreach ($this->enrichers->annotations() as $annotation) {
            $columns[] = $annotation->column;
        }

        $paginator = $this->paginateRows(
            $entity->sentences()->orderBy('order')->getQuery(),
            $page,
            $columns,
        );

        return [
            'rows' => $this->readingRows->forEntitySentences($paginator->getCollection()),
            'sideEntities' => ['a' => $entity, 'b' => null],
            'meta' => $this->readingRows->metaFor($paginator),
            // Deliberately not es: — that prefix names a single entity
            // sentence in row-key vocabulary; a position keys the whole text.
            'positionKey' => 'ent:'.$entity->id,
            'defaultSide' => null,
        ];
    }

    /**
     * Paginate rows at the fixed reader page size, clamping the requested
     * page into range so a stale saved position or a hand-edited ?page=
     * lands on the nearest valid page instead of an empty one.
     *
     * @param  Builder<MeaningMatch>|Builder<EntitySentence>  $query
     * @param  list<string>  $columns
     */
    private function paginateRows(Builder $query, int $page, array $columns = ['*']): LengthAwarePaginator
    {
        $paginator = $query->paginate(perPage: self::ROWS_PER_PAGE, columns: $columns, pageName: 'page', page: $page);

        $lastPage = max(1, $paginator->lastPage());
        if ($page > $lastPage) {
            $paginator = $query->paginate(perPage: self::ROWS_PER_PAGE, columns: $columns, pageName: 'page', page: $lastPage);
        }

        return $paginator;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function entitiesForLanguage(Language $language): array
    {
        return $this->access
            ->readableQuery(auth()->user(), $language->id)
            ->select('id', 'name')
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->all();
    }

    private function resolveLanguage(string $lang): Language
    {
        return Language::query()
            ->enabled()
            ->where('code', $lang)
            ->firstOrFail();
    }
}
