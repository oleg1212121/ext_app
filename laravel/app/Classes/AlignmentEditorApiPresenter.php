<?php

namespace App\Classes;

use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use Illuminate\Database\Eloquent\Collection;

class AlignmentEditorApiPresenter
{
    public const UNMATCHED_PER_PAGE = 15;

    /**
     * @return array<string, mixed>
     */
    public function matchPayload(EnRuEntityMatch $entityMatch): array
    {
        return [
            'id' => $entityMatch->id,
            'en_entity_name' => $entityMatch->enEntity?->name ?? '',
            'ru_entity_name' => $entityMatch->ruEntity?->name ?? '',
            'entity_similarity' => $entityMatch->entity_similarity !== null
                ? round((float) $entityMatch->entity_similarity, 4)
                : null,
            'status' => $entityMatch->status,
            'linked_count' => $entityMatch->linked_count,
            'confirmed_count' => $entityMatch->confirmed_count,
            'en_total_sentences' => $entityMatch->en_total_sentences,
            'ru_total_sentences' => $entityMatch->ru_total_sentences,
            'created_at' => $entityMatch->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rowPayload(EnRuMeaningMatch $meaningMatch): array
    {
        $meaningMatch->loadMissing([
            'enSentenceMatches.enEntitySentence',
            'ruSentenceMatches.ruEntitySentence',
        ]);

        return [
            'key' => 'mm-'.$meaningMatch->id,
            'id' => $meaningMatch->id,
            'order' => (int) $meaningMatch->order,
            'similarity' => $meaningMatch->similarity !== null
                ? round((float) $meaningMatch->similarity, 4)
                : null,
            'en_sentences' => $this->linkedSentences($meaningMatch, 'en'),
            'ru_sentences' => $this->linkedSentences($meaningMatch, 'ru'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rowsPayload(Collection $meaningMatches): array
    {
        return $meaningMatches->map(fn (EnRuMeaningMatch $match) => $this->rowPayload($match))->values()->all();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int, per_page: int}}
     */
    public function rowsPagePayload(EnRuEntityMatch $entityMatch, int $page = 1, int $perPage = 25): array
    {
        $rows = EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatch->id)
            ->with([
                'enSentenceMatches.enEntitySentence',
                'ruSentenceMatches.ruEntitySentence',
            ])
            ->orderBy('order')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'rows' => $this->rowsPayload($rows->getCollection()),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
            ],
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int, per_page: int}}
     */
    public function unmatchedPayload(EnRuEntityMatch $entityMatch, string $lang, int $page): array
    {
        $entity = $lang === 'en' ? $entityMatch->enEntity : $entityMatch->ruEntity;

        $linkedIds = $lang === 'en'
            ? EnRuMeaningMatch::query()
                ->where('en_ru_entity_match_id', $entityMatch->id)
                ->with('enSentenceMatches')
                ->get()
                ->flatMap->enSentenceMatches
                ->pluck('en_entity_sentence_id')
            : EnRuMeaningMatch::query()
                ->where('en_ru_entity_match_id', $entityMatch->id)
                ->with('ruSentenceMatches')
                ->get()
                ->flatMap->ruSentenceMatches
                ->pluck('ru_entity_sentence_id');

        $linkedSet = $linkedIds->flip();

        $query = $entity?->sentences()
            ->orderBy('order')
            ->orderBy('id');

        $total = (clone $query)->whereNotIn('id', $linkedSet->keys())->count();

        $items = (clone $query)
            ->whereNotIn('id', $linkedSet->keys())
            ->offset(($page - 1) * self::UNMATCHED_PER_PAGE)
            ->limit(self::UNMATCHED_PER_PAGE)
            ->get()
            ->map(fn ($sentence) => $this->sentencePayload($sentence->id, $sentence->content, $sentence->order, $lang))
            ->values()
            ->all();

        $lastPage = max((int) ceil($total / self::UNMATCHED_PER_PAGE), 1);

        return [
            'items' => $items,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'total' => $total,
                'per_page' => self::UNMATCHED_PER_PAGE,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linkedSentences(EnRuMeaningMatch $meaningMatch, string $lang): array
    {
        $matches = $lang === 'en'
            ? $meaningMatch->enSentenceMatches
            : $meaningMatch->ruSentenceMatches;

        $sentences = [];

        foreach ($matches->sortBy('order') as $match) {
            $sentence = $lang === 'en'
                ? $match->enEntitySentence
                : $match->ruEntitySentence;

            if ($sentence === null) {
                continue;
            }

            $sentences[] = $this->sentencePayload($sentence->id, $sentence->content, $sentence->order, $lang);
        }

        return $sentences;
    }

    /**
     * @return array<string, mixed>
     */
    public function sentencePayload(int $id, string $content, int $order, string $lang): array
    {
        return [
            'key' => $lang.':s-'.$id,
            'id' => $id,
            'content' => $content,
            'order' => (int) $order,
        ];
    }
}
