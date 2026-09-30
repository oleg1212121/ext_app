<?php

namespace App\Classes;

use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MeaningMatchPresenter
{
    /**
     * Row per meaning match as [aText, bText] pairs: index 0 is the match's
     * a side, index 1 the b side, with each side's sentences joined in
     * document order. Consumers label the columns from the match's entity
     * languages and flip the pair when reading from the b side.
     *
     * Illustration sentences (ADR 0050) contribute no text here — their
     * images (and captions) ride toSimulatorImages(); pairing the two
     * methods keeps a caption from rendering twice.
     *
     * @param  Collection<int, MeaningMatch>  $meaningMatches
     * @return list<array{0: string, 1: string}>
     */
    public function toSimulatorRows(Collection $meaningMatches): array
    {
        $rows = [];

        foreach ($meaningMatches as $meaningMatch) {
            $rows[] = [
                $this->sideText($meaningMatch, 'a'),
                $this->sideText($meaningMatch, 'b'),
            ];
        }

        return $rows;
    }

    /**
     * Row-aligned with toSimulatorRows (same collection, same order): row i
     * of toSimulatorRows carries the images of row i here, as an
     * [aImages, bImages] pair in document order. Each image descriptor
     * carries the access-checked URL, the intrinsic dimensions for
     * aspect-ratio reservation, and the optional caption.
     *
     * @param  Collection<int, MeaningMatch>  $meaningMatches
     * @return list<array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}>
     */
    public function toSimulatorImages(Collection $meaningMatches): array
    {
        $rows = [];

        foreach ($meaningMatches as $meaningMatch) {
            $rows[] = [
                $this->sideImages($meaningMatch, 'a'),
                $this->sideImages($meaningMatch, 'b'),
            ];
        }

        return $rows;
    }

    /**
     * Row keys matching toSimulatorRows one-to-one (same order, same count):
     * row i of toSimulatorRows belongs to row key i — the client uses them
     * to scope familiarity events to a sentence pair.
     *
     * @param  Collection<int, MeaningMatch>  $meaningMatches
     * @return list<string>
     */
    public function toSimulatorRowKeys(Collection $meaningMatches): array
    {
        return $meaningMatches
            ->map(fn (MeaningMatch $meaningMatch): string => 'mm:'.$meaningMatch->id)
            ->values()
            ->all();
    }

    /**
     * Stressed variants row-aligned with toSimulatorRows (ADR 0052): same
     * sides, same sentence selection and order. A side is null when none of
     * its sentences has a stressed variant; sentences without one fall back
     * to their plain content so the sides stay "\n"-aligned with rows.
     *
     * @param  Collection<int, MeaningMatch>  $meaningMatches
     * @return list<array{0: ?string, 1: ?string}>
     */
    public function toSimulatorStressedRows(Collection $meaningMatches): array
    {
        $rows = [];

        foreach ($meaningMatches as $meaningMatch) {
            $rows[] = [
                $this->sideStressed($meaningMatch, 'a'),
                $this->sideStressed($meaningMatch, 'b'),
            ];
        }

        return $rows;
    }

    /**
     * Intonation annotations row- and sentence-aligned with toSimulatorRows
     * (ADR 0052): {terminal, nuclear} per sentence of each side — the
     * terminal contour ('rise'|'fall') and the nuclear-stressed word's char
     * span into the sentence's content (null when unresolvable). Null for a
     * sentence with no annotation. The client maps the span onto its own
     * word segmentation, so the stressed variant's combining marks don't
     * shift it.
     *
     * @param  Collection<int, MeaningMatch>  $meaningMatches
     * @return list<array{0: list<?array{terminal: string, nuclear: array{start: int, end: int}|null}>, 1: list<?array{terminal: string, nuclear: array{start: int, end: int}|null}>}>
     */
    public function toSimulatorIntonationRows(Collection $meaningMatches): array
    {
        $rows = [];

        foreach ($meaningMatches as $meaningMatch) {
            $rows[] = [
                $this->sideSentences($meaningMatch, 'a')
                    ->map(fn (EntitySentence $sentence): ?array => $this->intonationAnnotation($sentence))
                    ->all(),
                $this->sideSentences($meaningMatch, 'b')
                    ->map(fn (EntitySentence $sentence): ?array => $this->intonationAnnotation($sentence))
                    ->all(),
            ];
        }

        return $rows;
    }

    /**
     * The sentence's intonation annotation for display: null when never
     * enriched, otherwise the terminal contour with the (nullable) nuclear
     * span.
     *
     * @return array{terminal: string, nuclear: array{start: int, end: int}|null}|null
     */
    public function intonationAnnotation(EntitySentence $sentence): ?array
    {
        $terminal = $sentence->intonation['terminal'] ?? null;

        if ($terminal === null) {
            return null;
        }

        $nuclear = $sentence->intonation['nuclear'] ?? null;

        return [
            'terminal' => $terminal,
            'nuclear' => is_array($nuclear) ? $nuclear : null,
        ];
    }

    /**
     * @return Collection<int, EntitySentence>
     */
    private function sideSentences(MeaningMatch $meaningMatch, string $side): Collection
    {
        return $meaningMatch->sentenceMeaningMatches
            ->where('side', $side)
            ->sortBy(fn ($match) => $match->entitySentence?->order ?? 0)
            ->map(fn ($match) => $match->entitySentence)
            ->filter(fn (?EntitySentence $sentence): bool => $sentence !== null
                && $sentence->image_path === null
                && $sentence->content !== '');
    }

    private function sideText(MeaningMatch $meaningMatch, string $side): string
    {
        return $this->sideSentences($meaningMatch, $side)
            ->pluck('content')
            ->implode("\n");
    }

    private function sideStressed(MeaningMatch $meaningMatch, string $side): ?string
    {
        $sentences = $this->sideSentences($meaningMatch, $side);

        if (! $sentences->contains(fn (EntitySentence $sentence): bool => $sentence->stressed_content !== null)) {
            return null;
        }

        return $sentences
            ->map(fn (EntitySentence $sentence): string => $sentence->stressed_content ?? $sentence->content)
            ->implode("\n");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sideImages(MeaningMatch $meaningMatch, string $side): array
    {
        return $meaningMatch->sentenceMeaningMatches
            ->where('side', $side)
            ->map(fn ($match) => [
                'order' => $match->entitySentence?->order ?? 0,
                'sentence' => $match->entitySentence,
            ])
            ->sortBy('order')
            ->filter(fn (array $item): bool => $item['sentence']?->image_path !== null)
            ->map(fn (array $item): array => $this->imagePayload($item['sentence']))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function imagePayload(EntitySentence $sentence): array
    {
        return [
            'id' => $sentence->id,
            'url' => route('illustrations.show', ['sentence' => $sentence->id]),
            'width' => $sentence->image_width !== null ? (int) $sentence->image_width : null,
            'height' => $sentence->image_height !== null ? (int) $sentence->image_height : null,
            'caption' => $sentence->content,
        ];
    }

    /**
     * @param  Collection<int, MeaningMatch>  $meaningMatches
     * @return list<array<string, mixed>>
     */
    public function toDisplayRows(Collection $meaningMatches): array
    {
        $rows = [];
        $colorIndex = 0;

        foreach ($meaningMatches as $meaningMatch) {
            $aItems = $this->sideItems($meaningMatch, 'a');
            $bItems = $this->sideItems($meaningMatch, 'b');

            if ($aItems !== [] && $bItems !== []) {
                $rows[] = [
                    'id' => $meaningMatch->id,
                    'type' => 'match',
                    'a' => $aItems,
                    'b' => $bItems,
                    'similarity' => round((float) $meaningMatch->similarity, 4),
                    'color_index' => $colorIndex % 6,
                ];
                $colorIndex++;

                continue;
            }

            if ($aItems !== []) {
                $rows[] = [
                    'type' => 'skip_a',
                    'a' => $aItems,
                    'b' => [],
                    'similarity' => null,
                    'color_index' => -1,
                ];

                continue;
            }

            if ($bItems !== []) {
                $rows[] = [
                    'type' => 'skip_b',
                    'a' => [],
                    'b' => $bItems,
                    'similarity' => null,
                    'color_index' => -1,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{order: int, content: string}>
     */
    private function sideItems(MeaningMatch $meaningMatch, string $side): array
    {
        return $meaningMatch->sentenceMeaningMatches
            ->where('side', $side)
            ->map(fn ($match) => [
                'order' => $match->entitySentence?->order ?? 0,
                'content' => $match->entitySentence?->content ?? '',
            ])
            ->sortBy('order')
            ->filter(fn (array $item): bool => $item['content'] !== '')
            ->values()
            ->all();
    }

    public function meaningMatchesQuery(EntityMatch $entityMatch): Builder
    {
        return MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->with(['sentenceMeaningMatches.entitySentence'])
            ->orderBy('order');
    }
}
