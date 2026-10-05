<?php

namespace App\Http\Controllers;

use App\Classes\AIModelResolver;
use App\Classes\Enrichment\EnricherRegistry;
use App\Classes\EntityAccessService;
use App\Classes\MeaningMatchPresenter;
use App\Classes\ReadingRowsPresenter;
use App\Http\Requests\ReaderPageRequest;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\Language;
use App\Support\SavedUiSettings;
use Illuminate\Database\Eloquent\Builder;
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
            'languages' => Language::query()->enabled()->orderBy('sort_order')->pluck('code')->all(),
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
        $saved = SavedUiSettings::section('reader');

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
            'fontSize' => SavedUiSettings::int($saved, 'font_size', 16, 38, 20),
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
