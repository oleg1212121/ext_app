<?php

namespace App\Classes;

use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use Illuminate\Support\Str;

class AlignmentEditorPresenter
{
    public function __construct(
        private readonly MeaningMatchPresenter $meaningMatchPresenter,
    ) {}

    /**
     * @return array{
     *     meaning_rows: list<array<string, mixed>>,
     *     unmatched_a: list<array<string, mixed>>,
     *     unmatched_b: list<array<string, mixed>>
     * }
     */
    public function toDraft(EntityMatch $entityMatch): array
    {
        $entityMatch->load(['aEntity', 'bEntity']);

        $meaningMatches = $this->meaningMatchPresenter
            ->meaningMatchesQuery($entityMatch)
            ->get();

        $linkedAIds = [];
        $linkedBIds = [];

        $meaningRows = [];

        foreach ($meaningMatches as $meaningMatch) {
            $aSentences = $this->mapLinkedSentences($meaningMatch, 'a', $linkedAIds);
            $bSentences = $this->mapLinkedSentences($meaningMatch, 'b', $linkedBIds);

            $meaningRows[] = [
                'key' => 'mm-'.$meaningMatch->id,
                'id' => $meaningMatch->id,
                'order' => $meaningMatch->order,
                'a_sentences' => $aSentences,
                'b_sentences' => $bSentences,
            ];
        }

        $unmatchedA = $this->unmatchedSentences(
            $entityMatch->aEntity->sentences()->orderBy('order')->get(),
            $linkedAIds,
        );

        $unmatchedB = $this->unmatchedSentences(
            $entityMatch->bEntity->sentences()->orderBy('order')->get(),
            $linkedBIds,
        );

        return [
            'meaning_rows' => $meaningRows,
            'unmatched_a' => $unmatchedA,
            'unmatched_b' => $unmatchedB,
        ];
    }

    /**
     * @param  'a'|'b'  $side
     * @param  list<int>  $linkedIds
     * @return list<array<string, mixed>>
     */
    private function mapLinkedSentences(MeaningMatch $meaningMatch, string $side, array &$linkedIds): array
    {
        $matches = $meaningMatch->sentenceMeaningMatches->where('side', $side);

        $sentences = [];

        foreach ($matches as $match) {
            $sentence = $match->entitySentence;

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
     * @param  iterable<int, EntitySentence>  $sentences
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
            'a_sentences' => [],
            'b_sentences' => [],
        ];
    }
}
