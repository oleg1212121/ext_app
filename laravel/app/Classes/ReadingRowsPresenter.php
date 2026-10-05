<?php

namespace App\Classes;

use App\Classes\Enrichment\Annotation;
use App\Classes\Enrichment\EnricherRegistry;
use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The reading surfaces' payload seam (ADR 0060): reading rows in canonical
 * a/b order, the interactive-word payload that annotates them, and the flat
 * pagination meta. Presenting the sides (which one reads first) is the
 * client's flip; this module never reorders.
 *
 * One sentence object is either
 *  - an illustration: {id, image: {url, width, height}, text: caption}
 *  - a text sentence: {id, text, stressed?, phrasal?}
 * and an annotation key is present only when that sentence has the data —
 * there is no null-for-absent level anywhere in the shape.
 */
class ReadingRowsPresenter
{
    /** @var list<Annotation> */
    private array $annotations;

    public function __construct(?EnricherRegistry $enrichers = null)
    {
        $this->annotations = ($enrichers ?? new EnricherRegistry)->annotations();
    }

    /**
     * @param  Collection<int, MeaningMatch>  $meaningMatches
     * @return list<array{key: string, a: array, b: array}>
     */
    public function toReadingRows(Collection $meaningMatches): array
    {
        return $meaningMatches
            ->map(fn (MeaningMatch $meaningMatch): array => [
                'key' => 'mm:'.$meaningMatch->id,
                'a' => ['sentences' => $this->sideSentences($meaningMatch, 'a')],
                'b' => ['sentences' => $this->sideSentences($meaningMatch, 'b')],
            ])
            ->values()
            ->all();
    }

    /**
     * One Reading row per entity sentence; the translation side is always
     * null. Illustrations ride as the image sentence shape.
     *
     * @param  Collection<int, EntitySentence>  $sentences
     * @return list<array{key: string, a: array, b: null}>
     */
    public function forEntitySentences(Collection $sentences): array
    {
        return $sentences
            ->map(fn (EntitySentence $sentence): array => [
                'key' => 'es:'.$sentence->id,
                'a' => ['sentences' => [$this->sentencePayload($sentence)]],
                'b' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * The side's junctioned sentences in document order — text sentences
     * and illustrations alike, so the client can render them interleaved.
     * Empty-content junctions contribute nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function sideSentences(MeaningMatch $meaningMatch, string $side): array
    {
        return $meaningMatch->sentenceMeaningMatches
            ->where('side', $side)
            ->sortBy(fn ($match) => $match->entitySentence?->order ?? 0)
            ->map(fn ($match) => $match->entitySentence)
            ->filter(fn (?EntitySentence $sentence): bool => $sentence !== null
                && ($sentence->image_path !== null || $sentence->content !== ''))
            ->map(fn (EntitySentence $sentence): array => $this->sentencePayload($sentence))
            ->values()
            ->all();
    }

    /**
     * The interactive-word payload both reading surfaces ship: word maps
     * filtered to the tokens on the current page's rows (the client renders
     * page rows only), plus the highlight/explain eligibility flags. Both
     * eligibility flags follow the same "not the user's native language"
     * rule; a missing side (single-language text) is never eligible.
     *
     * @param  array{a: Entity|null, b: Entity|null}  $sideEntities
     * @param  list<array<string, mixed>>  $rows
     * @return array{wordMaps: array{a: array, b: array}, highlightable: array{a: bool, b: bool}, explainable: array{a: bool, b: bool}}
     */
    public function wordMapsFor(array $sideEntities, int $userId, ?int $nativeLanguageId, array $rows): array
    {
        return [
            'wordMaps' => [
                'a' => isset($sideEntities['a'])
                    ? $this->wordMapForRows((new EntityWordMap)->forEntity($sideEntities['a'], $userId), $rows, 'a')
                    : [],
                'b' => isset($sideEntities['b'])
                    ? $this->wordMapForRows((new EntityWordMap)->forEntity($sideEntities['b'], $userId), $rows, 'b')
                    : [],
            ],
            'highlightable' => [
                'a' => $this->isNotNative($sideEntities['a'] ?? null, $nativeLanguageId),
                'b' => $this->isNotNative($sideEntities['b'] ?? null, $nativeLanguageId),
            ],
            'explainable' => [
                'a' => $this->isNotNative($sideEntities['a'] ?? null, $nativeLanguageId),
                'b' => $this->isNotNative($sideEntities['b'] ?? null, $nativeLanguageId),
            ],
        ];
    }

    /**
     * The flat meta shape every paginated surface in the app ships.
     *
     * @return array{current_page: int, per_page: int, total: int, last_page: int}
     */
    public function metaFor(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => max(1, $paginator->lastPage()),
        ];
    }

    /**
     * Keep only the map entries whose token occurs in these rows — the
     * client renders page rows only, so the payload must not carry a map
     * for the whole text. Tokens come from the same tokenizer that built
     * the map's l_word keys, so presence matches what the client will
     * segment and look up. A side's tokens come from its text sentences;
     * illustration captions are not lookup text.
     *
     * @param  array<string, array{w: int, s: int|null}>  $map
     * @param  list<array<string, mixed>>  $rows
     */
    private function wordMapForRows(array $map, array $rows, string $side): array
    {
        if ($map === []) {
            return [];
        }

        $tokenizer = new WordTokenizer;
        $present = [];

        foreach ($rows as $row) {
            foreach ($row[$side]['sentences'] ?? [] as $sentence) {
                if (! isset($sentence['image'])) {
                    $present += $tokenizer->tokenize($sentence['text']);
                }
            }
        }

        return array_intersect_key($map, $present);
    }

    /**
     * "Not the user's native language" — the highlighting and explanation
     * eligibility rule. A missing side (single-language text) is never
     * eligible.
     */
    private function isNotNative(?Entity $entity, ?int $nativeLanguageId): bool
    {
        return $entity !== null && $entity->language_id !== $nativeLanguageId;
    }

    /**
     * @return array<string, mixed>
     */
    private function sentencePayload(EntitySentence $sentence): array
    {
        if ($sentence->image_path !== null) {
            return [
                'id' => $sentence->id,
                'image' => [
                    'url' => route('illustrations.show', ['sentence' => $sentence->id]),
                    'width' => $sentence->image_width !== null ? (int) $sentence->image_width : null,
                    'height' => $sentence->image_height !== null ? (int) $sentence->image_height : null,
                ],
                'text' => $sentence->content,
            ];
        }

        $payload = [
            'id' => $sentence->id,
            'text' => $sentence->content,
        ];

        // The annotation keys ride the registry's verticals (ADR 0067):
        // present only when the sentence has the data — no null-for-absent
        // level anywhere in the shape.
        foreach ($this->annotations as $annotation) {
            $value = $sentence->{$annotation->column};

            if ($value === null || $value === []) {
                continue;
            }

            $payload[$annotation->payloadKey] = is_array($value) ? array_values($value) : $value;
        }

        return $payload;
    }
}
