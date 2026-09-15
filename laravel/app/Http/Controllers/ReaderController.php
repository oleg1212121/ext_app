<?php

namespace App\Http\Controllers;

use App\Classes\EntityAccessService;
use App\Classes\EntityWordMap;
use App\Classes\MeaningMatchPresenter;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\Language;
use App\Models\MeaningMatch;
use Inertia\Inertia;
use Inertia\Response;

class ReaderController extends Controller
{
    public function __construct(
        protected MeaningMatchPresenter $presenter,
    ) {}

    public function index(string $lang): Response
    {
        $language = $this->resolveLanguage($lang);

        return Inertia::render('ReaderReactIndex', [
            'lang' => $lang,
            'languages' => Language::query()->enabled()->orderBy('sort_order')->pluck('code')->all(),
            'entities' => $this->entitiesForLanguage($language),
        ]);
    }

    public function show(string $lang, int $entityId): Response
    {
        $language = $this->resolveLanguage($lang);

        $entity = Entity::query()
            ->where('language_id', $language->id)
            ->findOrFail($entityId);

        if (! $this->access()->canRead(auth()->user(), $entity)) {
            abort(403);
        }

        ['rows' => $rows, 'rowKeys' => $rowKeys, 'translationEntity' => $translationEntity] = $this->buildRows($entity);

        $userId = (int) auth()->id();
        $nativeLanguageId = auth()->user()->nativeLanguage()?->id;
        $wordMap = new EntityWordMap;

        return Inertia::render('ReaderReact', [
            'lang' => $lang,
            'entity' => [
                'id' => $entity->id,
                'name' => $entity->name,
            ],
            'rows' => $rows,
            'rowKeys' => $rowKeys,
            'fontSize' => $this->savedReaderFontSize(),
            'highlight' => $this->savedHighlight(),
            'wordMap' => $wordMap->forEntity($entity, $userId),
            'primaryHighlightable' => $entity->language_id !== $nativeLanguageId,
            'translationWordMap' => $translationEntity !== null ? $wordMap->forEntity($translationEntity, $userId) : [],
            'translationHighlightable' => $translationEntity !== null && $translationEntity->language_id !== $nativeLanguageId,
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
     * @return array{rows: list<array{0: string, 1: string}>, rowKeys: list<string>, translationEntity: Entity|null}
     */
    private function buildRows(Entity $entity): array
    {
        $entityMatch = EntityMatch::query()
            ->where(function ($query) use ($entity): void {
                $query->where('a_entity_id', $entity->id)
                    ->orWhere('b_entity_id', $entity->id);
            })
            ->with(['aEntity', 'bEntity'])
            ->first();

        if ($entityMatch === null) {
            return $this->singleLanguageRows($entity);
        }

        $readingSide = $entityMatch->a_entity_id === $entity->id ? 'a' : 'b';
        $otherEntity = $readingSide === 'a' ? $entityMatch->bEntity : $entityMatch->aEntity;

        if ($otherEntity === null || ! $this->access()->canRead(auth()->user(), $otherEntity)) {
            return $this->singleLanguageRows($entity);
        }

        $meaningMatches = MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->with(['sentenceMeaningMatches.entitySentence'])
            ->orderBy('order')
            ->get();

        $bilingualRows = $this->presenter->toSimulatorRows($meaningMatches);

        // Row keys stay in meaning-match order — normalizeRowsForReadingSide
        // only flips the text columns, never the keys.
        return [
            'rows' => $this->normalizeRowsForReadingSide($bilingualRows, $readingSide),
            'rowKeys' => $this->presenter->toSimulatorRowKeys($meaningMatches),
            'translationEntity' => $otherEntity,
        ];
    }

    /**
     * Rows of the bare entity keyed by their entity sentences, with no
     * translation side.
     *
     * @return array{rows: list<array{0: string, 1: string}>, rowKeys: list<string>, translationEntity: null}
     */
    private function singleLanguageRows(Entity $entity): array
    {
        $sentences = $entity->sentences()
            ->orderBy('order')
            ->get(['id', 'content']);

        return [
            'rows' => $sentences
                ->map(fn (EntitySentence $sentence): array => [$sentence->content, ''])
                ->all(),
            'rowKeys' => $sentences
                ->map(fn (EntitySentence $sentence): string => 'es:'.$sentence->id)
                ->all(),
            'translationEntity' => null,
        ];
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
