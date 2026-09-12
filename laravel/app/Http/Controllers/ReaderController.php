<?php

namespace App\Http\Controllers;

use App\Classes\EntityAccessService;
use App\Classes\MeaningMatchPresenter;
use App\Models\Entity;
use App\Models\EntityMatch;
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

        $rows = $this->buildRows($entity);

        return Inertia::render('ReaderReact', [
            'lang' => $lang,
            'entity' => [
                'id' => $entity->id,
                'name' => $entity->name,
            ],
            'rows' => $rows,
            'fontSize' => $this->savedReaderFontSize(),
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

    /**
     * @return list<array{0: string, 1: string}>
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

        return $this->normalizeRowsForReadingSide($bilingualRows, $readingSide);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function singleLanguageRows(Entity $entity): array
    {
        return $entity->sentences()
            ->orderBy('order')
            ->pluck('content')
            ->map(fn (string $content): array => [$content, ''])
            ->all();
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
