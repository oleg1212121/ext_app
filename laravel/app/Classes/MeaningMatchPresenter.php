<?php

namespace App\Classes;

use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use Illuminate\Support\Collection;

class MeaningMatchPresenter
{
    /**
     * @param  Collection<int, EnRuMeaningMatch>  $meaningMatches
     * @return list<array{0: string, 1: string}>
     */
    public function toSimulatorRows(Collection $meaningMatches): array
    {
        $rows = [];

        foreach ($meaningMatches as $meaningMatch) {
            $enText = $meaningMatch->enSentenceMatches
                ->map(fn ($match) => [
                    'order' => $match->enEntitySentence?->order ?? 0,
                    'content' => $match->enEntitySentence?->content ?? '',
                ])
                ->sortBy('order')
                ->pluck('content')
                ->filter()
                ->implode("\n");

            $ruText = $meaningMatch->ruSentenceMatches
                ->map(fn ($match) => [
                    'order' => $match->ruEntitySentence?->order ?? 0,
                    'content' => $match->ruEntitySentence?->content ?? '',
                ])
                ->sortBy('order')
                ->pluck('content')
                ->filter()
                ->implode("\n");

            $rows[] = [$enText, $ruText];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, EnRuMeaningMatch>  $meaningMatches
     * @return list<array<string, mixed>>
     */
    public function toDisplayRows(Collection $meaningMatches): array
    {
        $rows = [];
        $colorIndex = 0;

        foreach ($meaningMatches as $meaningMatch) {
            $enItems = $meaningMatch->enSentenceMatches
                ->map(fn ($match) => [
                    'order' => $match->enEntitySentence?->order ?? 0,
                    'content' => $match->enEntitySentence?->content ?? '',
                ])
                ->sortBy('order')
                ->filter(fn (array $item) => $item['content'] !== '')
                ->values()
                ->all();

            $ruItems = $meaningMatch->ruSentenceMatches
                ->map(fn ($match) => [
                    'order' => $match->ruEntitySentence?->order ?? 0,
                    'content' => $match->ruEntitySentence?->content ?? '',
                ])
                ->sortBy('order')
                ->filter(fn (array $item) => $item['content'] !== '')
                ->values()
                ->all();

            if ($enItems !== [] && $ruItems !== []) {
                $rows[] = [
                    'id' => $meaningMatch->id,
                    'type' => 'match',
                    'en' => $enItems,
                    'ru' => $ruItems,
                    'similarity' => round((float) $meaningMatch->similarity, 4),
                    'color_index' => $colorIndex % 6,
                ];
                $colorIndex++;

                continue;
            }

            if ($enItems !== []) {
                $rows[] = [
                    'type' => 'skip_en',
                    'en' => $enItems,
                    'ru' => [],
                    'similarity' => null,
                    'color_index' => -1,
                ];

                continue;
            }

            if ($ruItems !== []) {
                $rows[] = [
                    'type' => 'skip_ru',
                    'en' => [],
                    'ru' => $ruItems,
                    'similarity' => null,
                    'color_index' => -1,
                ];
            }
        }

        return $rows;
    }

    public function meaningMatchesQuery(EnRuEntityMatch $entityMatch): \Illuminate\Database\Eloquent\Builder
    {
        return EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatch->id)
            ->with([
                'enSentenceMatches.enEntitySentence',
                'ruSentenceMatches.ruEntitySentence',
            ])
            ->orderBy('order');
    }
}
