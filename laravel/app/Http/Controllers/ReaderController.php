<?php

namespace App\Http\Controllers;

use App\Classes\MeaningMatchPresenter;
use App\Models\EnEntity;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\RuEntity;
use Inertia\Inertia;
use Inertia\Response;

class ReaderController extends Controller
{
    public function __construct(
        protected MeaningMatchPresenter $presenter,
    ) {}

    public function index(string $lang): Response
    {
        return Inertia::render('ReaderReactIndex', [
            'lang' => $lang,
            'languages' => ['en', 'ru'],
            'entities' => $this->entitiesForLanguage($lang),
        ]);
    }

    public function show(string $lang, int $entityId): Response
    {
        $entity = match ($lang) {
            'en' => EnEntity::query()->findOrFail($entityId),
            'ru' => RuEntity::query()->findOrFail($entityId),
            default => abort(404),
        };

        $rows = $this->buildRows($lang, $entityId, $entity);

        return Inertia::render('ReaderReact', [
            'lang' => $lang,
            'entity' => [
                'id' => $entity->id,
                'name' => $entity->name,
            ],
            'rows' => $rows,
        ]);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function buildRows(string $lang, int $entityId, EnEntity|RuEntity $entity): array
    {
        $entityMatch = match ($lang) {
            'en' => EnRuEntityMatch::query()->where('en_entity_id', $entityId)->first(),
            'ru' => EnRuEntityMatch::query()->where('ru_entity_id', $entityId)->first(),
            default => null,
        };

        if ($entityMatch === null) {
            return $this->singleLanguageRows($entity);
        }

        $meaningMatches = EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatch->id)
            ->with([
                'enSentenceMatches.enEntitySentence',
                'ruSentenceMatches.ruEntitySentence',
            ])
            ->orderBy('order')
            ->get();

        $bilingualRows = $this->presenter->toSimulatorRows($meaningMatches);

        return $this->normalizeRowsForLanguage($bilingualRows, $lang);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function singleLanguageRows(EnEntity|RuEntity $entity): array
    {
        return $entity->sentences()
            ->orderBy('order')
            ->pluck('content')
            ->map(fn (string $content): array => [$content, ''])
            ->all();
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows
     * @return list<array{0: string, 1: string}>
     */
    private function normalizeRowsForLanguage(array $rows, string $lang): array
    {
        if ($lang === 'en') {
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
    private function entitiesForLanguage(string $lang): array
    {
        $query = match ($lang) {
            'en' => EnEntity::query(),
            'ru' => RuEntity::query(),
            default => abort(404),
        };

        return $query
            ->select('id', 'name')
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->all();
    }
}
