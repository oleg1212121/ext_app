<?php

namespace App\Http\Controllers;

use App\Classes\AIModelResolver;
use App\Classes\EntityAccessService;
use App\Classes\EntityWordMap;
use App\Classes\MeaningMatchPresenter;
use App\Classes\WordTokenizer;
use App\Http\Requests\ReaderPageRequest;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\Language;
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
        protected AIModelResolver $modelResolver,
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

        if (! $this->access()->canRead(auth()->user(), $entity)) {
            abort(403);
        }

        $nativeLanguageId = auth()->user()->nativeLanguage()?->id;

        ['rows' => $rows, 'rowKeys' => $rowKeys, 'readingEntity' => $readingEntity, 'translationEntity' => $translationEntity, 'meta' => $meta, 'positionKey' => $positionKey, 'readingSide' => $readingSide] = $this->buildRows($entity, $nativeLanguageId, $request->page());

        $userId = (int) auth()->id();
        $wordMap = new EntityWordMap;
        $explanationModel = $this->modelResolver->resolveExplanationModel();

        return Inertia::render('Reader', [
            // The two columns' languages after the side rule — the reading
            // language first, translation null for single-language texts.
            'primaryLang' => $readingEntity->language?->code,
            'translationLang' => $translationEntity?->language?->code,
            'entity' => [
                'id' => $entity->id,
                'name' => $entity->name,
            ],
            'rows' => $rows,
            'rowKeys' => $rowKeys,
            'meta' => $meta,
            'positionKey' => $positionKey,
            'fontSize' => $this->savedReaderFontSize(),
            'highlight' => $this->savedHighlight(),
            'wordMap' => $this->wordMapForRows($wordMap->forEntity($readingEntity, $userId), $rows, 0),
            'primaryHighlightable' => $readingEntity->language_id !== $nativeLanguageId,
            'translationWordMap' => $translationEntity !== null
                ? $this->wordMapForRows($wordMap->forEntity($translationEntity, $userId), $rows, 1)
                : [],
            'translationHighlightable' => $translationEntity !== null && $translationEntity->language_id !== $nativeLanguageId,
            // The AI explanation tab follows the same "not your native
            // language" rule as highlighting; the model itself is the user's
            // stored explanation preference, resolved server-side.
            'primaryExplainable' => $readingEntity->language_id !== $nativeLanguageId,
            'translationExplainable' => $translationEntity !== null && $translationEntity->language_id !== $nativeLanguageId,
            'primarySide' => $readingSide,
            'explain' => [
                'enabled' => auth()->user()->canUseAi(),
                'modelKey' => $explanationModel['id'] ?? null,
                'modelLabel' => $explanationModel['label'] ?? null,
                'followsAnswer' => $this->modelResolver->explanationModelFollowsAnswer(),
            ],
        ]);
    }

    private function savedReaderFontSize(): int
    {
        $saved = auth()->user()->settings?->ui_settings['reader']['font_size'] ?? null;

        if (! is_numeric($saved)) {
            return 20;
        }

        return max(16, min(38, (int) $saved));
    }

    private function savedHighlight(): bool
    {
        return (bool) (auth()->user()->settings?->ui_settings['reader']['highlight'] ?? true);
    }

    /**
     * @param  int  $page  Pre-clamped at the floor by ReaderPageRequest; the
     *                     ceiling is clamped in paginateRows.
     * @return array{rows: list<array{0: string, 1: string}>, rowKeys: list<string>, readingEntity: Entity, translationEntity: Entity|null, meta: array{current_page: int, per_page: int, total: int, last_page: int}, positionKey: string, readingSide: string|null}
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
        // which language is read and which one is shown as translation.
        $readingSide = $entityMatch->readingSideFor($nativeLanguageId);
        $readingEntity = $readingSide === 'a' ? $entityMatch->aEntity : $entityMatch->bEntity;
        $translationEntity = $readingSide === 'a' ? $entityMatch->bEntity : $entityMatch->aEntity;

        if ($readingEntity === null || $translationEntity === null
            || ! $this->access()->canRead(auth()->user(), $readingEntity)
            || ! $this->access()->canRead(auth()->user(), $translationEntity)) {
            return $this->singleLanguageRows($entity, $page);
        }

        $paginator = $this->paginateRows(
            $this->presenter->meaningMatchesQuery($entityMatch),
            $page,
        );

        $bilingualRows = $this->presenter->toSimulatorRows($paginator->getCollection());

        // Row keys stay in meaning-match order — normalizeRowsForReadingSide
        // only flips the text columns, never the keys. The position key is
        // the entity match: both reading sides page through the same rows.
        return [
            'rows' => $this->normalizeRowsForReadingSide($bilingualRows, $readingSide),
            'rowKeys' => $this->presenter->toSimulatorRowKeys($paginator->getCollection()),
            'readingEntity' => $readingEntity,
            'translationEntity' => $translationEntity,
            'meta' => $this->metaFor($paginator),
            'positionKey' => 'mm:'.$entityMatch->id,
            'readingSide' => $readingSide,
        ];
    }

    /**
     * Rows of the bare entity keyed by their entity sentences, with no
     * translation side.
     *
     * @return array{rows: list<array{0: string, 1: string}>, rowKeys: list<string>, readingEntity: Entity, translationEntity: null, meta: array{current_page: int, per_page: int, total: int, last_page: int}, positionKey: string, readingSide: string|null}
     */
    private function singleLanguageRows(Entity $entity, int $page): array
    {
        $paginator = $this->paginateRows(
            $entity->sentences()->orderBy('order')->getQuery(),
            $page,
            ['id', 'content'],
        );

        return [
            'rows' => $paginator->getCollection()
                ->map(fn (EntitySentence $sentence): array => [$sentence->content, ''])
                ->all(),
            'rowKeys' => $paginator->getCollection()
                ->map(fn (EntitySentence $sentence): string => 'es:'.$sentence->id)
                ->all(),
            'readingEntity' => $entity,
            'translationEntity' => null,
            'meta' => $this->metaFor($paginator),
            // Deliberately not es: — that prefix names a single entity
            // sentence in row-key vocabulary; a position keys the whole text.
            'positionKey' => 'ent:'.$entity->id,
            'readingSide' => null,
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
     * The flat meta shape every paginated surface in the app ships.
     *
     * @return array{current_page: int, per_page: int, total: int, last_page: int}
     */
    private function metaFor(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => max(1, $paginator->lastPage()),
        ];
    }

    /**
     * Keep only the map entries whose token occurs in this page's rows —
     * the client renders page rows only, so the payload must not carry a
     * map for the whole text. Tokens come from the same tokenizer that
     * built the map's l_word keys, so presence matches what the client
     * will segment and look up.
     *
     * @param  array<string, array{w: int, s: int|null}>  $map
     * @param  list<array{0: string, 1: string}>  $rows
     */
    private function wordMapForRows(array $map, array $rows, int $column): array
    {
        if ($map === []) {
            return [];
        }

        $tokenizer = new WordTokenizer;
        $present = [];

        foreach ($rows as $row) {
            $present += $tokenizer->tokenize($row[$column]);
        }

        return array_intersect_key($map, $present);
    }

    /**
     * Put the reading language's text first: rows are [a, b] pairs, so flip
     * them when reading from the b side.
     *
     * @param  list<array{0: string, 1: string}>  $rows
     * @return list<array{0: string, 1: string}>
     */
    private function normalizeRowsForReadingSide(array $rows, string $readingSide): array
    {
        if ($readingSide === 'a') {
            return $rows;
        }

        return array_map(
            fn (array $row): array => [$row[1], $row[0]],
            $rows,
        );
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function entitiesForLanguage(Language $language): array
    {
        return $this->access()
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

    private function access(): EntityAccessService
    {
        return new EntityAccessService;
    }
}
