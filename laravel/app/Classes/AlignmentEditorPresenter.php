<?php

namespace App\Classes;

use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use Illuminate\Support\Str;

class AlignmentEditorPresenter
{
    public function __construct(
        private readonly MeaningMatchPresenter $meaningMatchPresenter,
    ) {}

    /**
     * @return array{
     *     meaning_rows: list<array<string, mixed>>,
     *     unmatched_en: list<array<string, mixed>>,
     *     unmatched_ru: list<array<string, mixed>>
     * }
     */
    public function toDraft(EnRuEntityMatch $entityMatch): array
    {
        $entityMatch->load(['enEntity', 'ruEntity']);

        $meaningMatches = $this->meaningMatchPresenter
            ->meaningMatchesQuery($entityMatch)
            ->get();

        $linkedEnIds = [];
        $linkedRuIds = [];

        $meaningRows = [];

        foreach ($meaningMatches as $meaningMatch) {
            $enSentences = $this->mapLinkedSentences($meaningMatch, 'en', $linkedEnIds);
            $ruSentences = $this->mapLinkedSentences($meaningMatch, 'ru', $linkedRuIds);

            $meaningRows[] = [
                'key' => 'mm-'.$meaningMatch->id,
                'id' => $meaningMatch->id,
                'order' => $meaningMatch->order,
                'en_sentences' => $enSentences,
                'ru_sentences' => $ruSentences,
            ];
        }

        $unmatchedEn = $this->unmatchedSentences(
            $entityMatch->enEntity->sentences()->orderBy('order')->get(),
            $linkedEnIds,
        );

        $unmatchedRu = $this->unmatchedSentences(
            $entityMatch->ruEntity->sentences()->orderBy('order')->get(),
            $linkedRuIds,
        );

        return [
            'meaning_rows' => $meaningRows,
            'unmatched_en' => $unmatchedEn,
            'unmatched_ru' => $unmatchedRu,
        ];
    }

    /**
     * @param  list<int>  $linkedIds
     * @return list<array<string, mixed>>
     */
    private function mapLinkedSentences(EnRuMeaningMatch $meaningMatch, string $lang, array &$linkedIds): array
    {
        $matches = $lang === 'en'
            ? $meaningMatch->enSentenceMatches
            : $meaningMatch->ruSentenceMatches;

        $sentences = [];

        foreach ($matches as $match) {
            $sentence = $lang === 'en'
                ? $match->enEntitySentence
                : $match->ruEntitySentence;

            if ($sentence === null) {
                continue;
            }

            $linkedIds[] = $sentence->id;

            $sentences[] = [
                'order' => (int) $sentence->order,
                'payload' => $this->sentencePayload(
                    id: $sentence->id,
                    content: $sentence->content,
                    order: $sentence->order,
                ),
            ];
        }

        usort($sentences, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_column($sentences, 'payload');
    }

    /**
     * @param  iterable<int, \App\Models\EnEntitySentence|\App\Models\RuEntitySentence>  $sentences
     * @param  list<int>  $linkedIds
     * @return list<array<string, mixed>>
     */
    private function unmatchedSentences(iterable $sentences, array $linkedIds): array
    {
        $linkedSet = array_flip($linkedIds);
        $unmatched = [];

        foreach ($sentences as $sentence) {
            if (isset($linkedSet[$sentence->id])) {
                continue;
            }

            $unmatched[] = $this->sentencePayload(
                id: $sentence->id,
                content: $sentence->content,
                order: $sentence->order,
            );
        }

        return $unmatched;
    }

    /**
     * @return array<string, mixed>
     */
    public function sentencePayload(?int $id, string $content, int $order, ?string $tempId = null): array
    {
        $key = $id !== null ? 's-'.$id : ($tempId ?? 'tmp-'.Str::uuid());

        return [
            'key' => $key,
            'id' => $id,
            'temp_id' => $id === null ? ($tempId ?? $key) : null,
            'content' => $content,
            'order' => $order,
            '_deleted' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function newMeaningRow(int $order): array
    {
        $key = 'mm-new-'.Str::uuid();

        return [
            'key' => $key,
            'id' => null,
            'order' => $order,
            'en_sentences' => [],
            'ru_sentences' => [],
        ];
    }
}
