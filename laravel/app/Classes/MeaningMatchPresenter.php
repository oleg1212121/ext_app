<?php

namespace App\Classes;

use App\Models\EntityMatch;
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

    private function sideText(MeaningMatch $meaningMatch, string $side): string
    {
        return $meaningMatch->sentenceMeaningMatches
            ->where('side', $side)
            ->map(fn ($match) => [
                'order' => $match->entitySentence?->order ?? 0,
                'content' => $match->entitySentence?->content ?? '',
            ])
            ->sortBy('order')
            ->pluck('content')
            ->filter()
            ->implode("\n");
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
