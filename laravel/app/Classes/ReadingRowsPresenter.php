<?php

namespace App\Classes;

use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use Illuminate\Support\Collection;

/**
 * Builds the reading surfaces' row payload (ADR 0060): one Reading row per
 * meaning match — or per entity sentence for a single-language text — each
 * carrying its sides as ordered sentence objects in canonical a/b order.
 * Presenting the sides (which one reads first) is the client's flip; this
 * module never reorders.
 *
 * One sentence object is either
 *  - an illustration: {id, image: {url, width, height}, text: caption}
 *  - a text sentence: {id, text, stressed?, phrasal?}
 * and an annotation key is present only when that sentence has the data —
 * there is no null-for-absent level anywhere in the shape.
 */
class ReadingRowsPresenter
{
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

        if ($sentence->stressed_content !== null) {
            $payload['stressed'] = $sentence->stressed_content;
        }

        if (! empty($sentence->phrasal_verbs)) {
            $payload['phrasal'] = array_values($sentence->phrasal_verbs);
        }

        return $payload;
    }
}
