<?php

namespace App\Classes;

use App\Models\EntityMatch;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AlignmentEditorApiPresenter
{
    public const UNMATCHED_PER_PAGE = 15;

    public const NEEDS_REVIEW_PER_PAGE = 25;

    public const LOW_SIMILARITY_THRESHOLD = 0.55;

    /**
     * @return array<string, mixed>
     */
    public function matchPayload(EntityMatch $entityMatch): array
    {
        return [
            'id' => $entityMatch->id,
            'a_entity_name' => $entityMatch->aEntity?->name ?? '',
            'b_entity_name' => $entityMatch->bEntity?->name ?? '',
            'a_language_code' => $entityMatch->aEntity?->language?->code ?? '',
            'b_language_code' => $entityMatch->bEntity?->language?->code ?? '',
            'work_title' => $entityMatch->aEntity?->work?->title ?? $entityMatch->bEntity?->work?->title ?? '',
            'original_language_code' => $entityMatch->aEntity?->work?->originalLanguage?->code
                ?? $entityMatch->bEntity?->work?->originalLanguage?->code
                ?? null,
            'entity_similarity' => $entityMatch->entity_similarity !== null
                ? round((float) $entityMatch->entity_similarity, 4)
                : null,
            'status' => $entityMatch->status,
            'linked_count' => $entityMatch->linked_count,
            'confirmed_count' => $entityMatch->confirmed_count,
            'a_total_sentences' => $entityMatch->a_total_sentences,
            'b_total_sentences' => $entityMatch->b_total_sentences,
            'created_at' => $entityMatch->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rowPayload(MeaningMatch $meaningMatch): array
    {
        $meaningMatch->loadMissing(['sentenceMeaningMatches.entitySentence']);

        return [
            'key' => 'mm-'.$meaningMatch->id,
            'id' => $meaningMatch->id,
            'order' => (int) $meaningMatch->order,
            'similarity' => $meaningMatch->similarity !== null
                ? round((float) $meaningMatch->similarity, 4)
                : null,
            'a_sentences' => $this->linkedSentences($meaningMatch, 'a'),
            'b_sentences' => $this->linkedSentences($meaningMatch, 'b'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rowsPayload(Collection $meaningMatches): array
    {
        return $meaningMatches->map(fn (MeaningMatch $match): array => $this->rowPayload($match))->values()->all();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int, per_page: int}, sentences_before: array{a: int, b: int}}
     */
    public function rowsPagePayload(EntityMatch $entityMatch, int $page = 1, int $perPage = 25): array
    {
        $rows = MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->with(['sentenceMeaningMatches.entitySentence'])
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
            'sentences_before' => $this->sentencesBeforePage($entityMatch, $page, $perPage),
        ];
    }

    /**
     * Count linked a- and b-side sentences belonging to meaning-matches on pages before the given page.
     *
     * @return array{a: int, b: int}
     */
    private function sentencesBeforePage(EntityMatch $entityMatch, int $page, int $perPage): array
    {
        if ($page <= 1) {
            return ['a' => 0, 'b' => 0];
        }

        $previousIds = MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->orderBy('order')
            ->pluck('id')
            ->slice(0, ($page - 1) * $perPage)
            ->values();

        if ($previousIds->isEmpty()) {
            return ['a' => 0, 'b' => 0];
        }

        $junctions = SentenceMeaningMatch::query()
            ->whereIn('meaning_match_id', $previousIds)
            ->selectRaw('side, count(*) as total')
            ->groupBy('side')
            ->pluck('total', 'side');

        return [
            'a' => (int) ($junctions['a'] ?? 0),
            'b' => (int) ($junctions['b'] ?? 0),
        ];
    }

    /**
     * @param  'a'|'b'  $side
     * @return array{items: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int, per_page: int}}
     */
    public function unmatchedPayload(EntityMatch $entityMatch, string $side, int $page): array
    {
        $entity = $side === 'a' ? $entityMatch->aEntity : $entityMatch->bEntity;

        $linkedIds = SentenceMeaningMatch::query()
            ->whereIn('meaning_match_id', MeaningMatch::query()
                ->where('entity_match_id', $entityMatch->id)
                ->select('id'))
            ->where('side', $side)
            ->pluck('entity_sentence_id');

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
            ->map(fn ($sentence): array => $this->sentencePayload($sentence->id, $sentence->content, $sentence->order, $side))
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
     * @param  'a'|'b'  $side
     * @return list<array<string, mixed>>
     */
    private function linkedSentences(MeaningMatch $meaningMatch, string $side): array
    {
        $matches = $meaningMatch->sentenceMeaningMatches->where('side', $side);

        $sentences = [];

        foreach ($matches as $match) {
            $sentence = $match->entitySentence;

            if ($sentence === null) {
                continue;
            }

            $sentences[] = [
                'id' => $sentence->id,
                'order' => (int) $sentence->order,
                'match' => $match,
            ];
        }

        usort($sentences, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $result = [];

        foreach ($sentences as $entry) {
            $result[] = $this->sentencePayload(
                $entry['match']->entitySentence->id,
                $entry['match']->entitySentence->content,
                $entry['match']->entitySentence->order,
                $side,
            );
        }

        return $result;
    }

    /**
     * @return array{items: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int, per_page: int}}
     */
    public function needsReviewPagePayload(EntityMatch $entityMatch, int $page = 1): array
    {
        $rows = MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->where(function ($query) {
                $query
                    ->where(function ($oneSided) {
                        $oneSided
                            ->whereHas('sentenceMeaningMatches', fn ($q) => $q->where('side', 'a'))
                            ->whereDoesntHave('sentenceMeaningMatches', fn ($q) => $q->where('side', 'b'));
                    })
                    ->orWhere(function ($oneSided) {
                        $oneSided
                            ->whereDoesntHave('sentenceMeaningMatches', fn ($q) => $q->where('side', 'a'))
                            ->whereHas('sentenceMeaningMatches', fn ($q) => $q->where('side', 'b'));
                    })
                    ->orWhere('similarity', '<', self::LOW_SIMILARITY_THRESHOLD);
            })
            ->with(['sentenceMeaningMatches.entitySentence'])
            ->orderBy('order')
            ->paginate(self::NEEDS_REVIEW_PER_PAGE, ['*'], 'page', $page);

        return [
            'items' => $this->needsReviewItems($entityMatch, $rows->getCollection()),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function needsReviewItems(EntityMatch $entityMatch, Collection $meaningMatches): array
    {
        if ($meaningMatches->isEmpty()) {
            return [];
        }

        $ranks = $this->ranksByRowId($entityMatch, $meaningMatches->pluck('id')->all());

        return $meaningMatches
            ->map(function (MeaningMatch $meaningMatch) use ($ranks): array {
                $aSentences = $this->linkedSentences($meaningMatch, 'a');
                $bSentences = $this->linkedSentences($meaningMatch, 'b');

                return [
                    'key' => 'mm-'.$meaningMatch->id,
                    'id' => $meaningMatch->id,
                    'order' => (int) $meaningMatch->order,
                    'similarity' => $meaningMatch->similarity !== null
                        ? round((float) $meaningMatch->similarity, 4)
                        : null,
                    'a_part' => $this->partContent($aSentences),
                    'b_part' => $this->partContent($bSentences),
                    'one_sided' => ($aSentences !== [] && $bSentences === []) || ($aSentences === [] && $bSentences !== []),
                    'rank' => (int) ($ranks[$meaningMatch->id] ?? 1),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function ranksByRowId(EntityMatch $entityMatch, array $ids): array
    {
        $subquery = DB::table('meaning_matches')
            ->where('entity_match_id', $entityMatch->id)
            ->select('id')
            ->selectRaw('ROW_NUMBER() OVER (ORDER BY "order") AS rn');

        return DB::table($subquery, 't')
            ->whereIn('id', $ids)
            ->pluck('rn', 'id')
            ->map(fn ($rn): int => (int) $rn)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sentences
     */
    private function partContent(array $sentences): string
    {
        return implode(' / ', array_map(fn (array $sentence): string => $sentence['content'], $sentences));
    }

    /**
     * @return array<string, mixed>
     */
    public function sentencePayload(int $id, string $content, int $order, string $side): array
    {
        return [
            'key' => $side.':s-'.$id,
            'id' => $id,
            'content' => $content,
            'order' => (int) $order,
        ];
    }
}
