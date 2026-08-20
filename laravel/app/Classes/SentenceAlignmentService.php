<?php

namespace App\Classes;

use App\Models\EnEntity;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\RuEntity;
use App\Models\RuSentenceMeaningMatch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class SentenceAlignmentService
{
    private const VERIFY_THRESHOLD = 0.70;

    private const RETRY_DELAYS_MS = [500, 1_500, 3_000];

    public function __construct(
        private readonly string $apiUrl,
        private readonly int $timeout,
        private readonly ?int $alignTimeout = null,
    ) {}

    public static function create(): self
    {
        return new self(
            apiUrl: config('services.python.url', 'http://ext_python:8000'),
            timeout: (int) config('services.python.timeout', 30),
            alignTimeout: (int) config('services.python.align_timeout', 300),
        );
    }

    /**
     * Verify that two entities are translations of the same text.
     */
    public function verifyEntityPair(EnEntity $enEntity, RuEntity $ruEntity): array
    {
        $enSignature = json_decode($enEntity->signature, true);
        $ruSignature = json_decode($ruEntity->signature, true);

        if (! is_array($enSignature) || ! is_array($ruSignature)) {
            return ['similarity' => 0.0, 'passed' => false, 'message' => 'Missing entity signatures'];
        }

        $similarity = $this->cosineSimilarity($enSignature, $ruSignature);
        $passed = $similarity >= self::VERIFY_THRESHOLD;

        return [
            'similarity' => round($similarity, 4),
            'passed' => $passed,
            'message' => $passed
                ? "Entity signatures match (score: {$similarity})"
                : "Entity signatures too different (score: {$similarity}, threshold: ".self::VERIFY_THRESHOLD.')',
        ];
    }

    /**
     * Align a chunk of sentences via the python service and adapt the result
     * into links + dpPath steps for storeAlignmentSegment(). The raw python
     * matches are returned too so the caller can trim to the last confident
     * anchor before persisting (see AlignEntitySentences).
     *
     * Optional landmarks (hard human-made pins) and a high-confidence prepass
     * bar are passed straight through to the python service. When omitted the
     * request payload is byte-identical to the previous shape.
     *
     * @param  list<array{en_start: int, en_end: int, ru_start: int, ru_end: int}>  $landmarks
     * @return array{links: array, dpPath: array, matches: array}
     */
    public function alignChunkRemote(
        Collection $enSentences,
        Collection $ruSentences,
        int $maxN = 3,
        array $landmarks = [],
        ?float $highConfidence = null,
    ): array {
        $enIds = $enSentences->pluck('id')->values()->all();
        $ruIds = $ruSentences->pluck('id')->values()->all();

        if (count($enIds) === 0) {
            return [
                'links' => [],
                'dpPath' => $this->buildSkipOnlyPath('skip_ru', $ruIds),
                'matches' => [],
            ];
        }

        if (count($ruIds) === 0) {
            return [
                'links' => [],
                'dpPath' => $this->buildSkipOnlyPath('skip_en', $enIds),
                'matches' => [],
            ];
        }

        $payload = [
            'en_sentences' => $enSentences->pluck('content')->map(fn ($c) => (string) $c)->values()->all(),
            'ru_sentences' => $ruSentences->pluck('content')->map(fn ($c) => (string) $c)->values()->all(),
            'max_window' => max(1, $maxN),
        ];

        if ($landmarks !== []) {
            $payload['landmarks'] = $landmarks;
        }

        if ($highConfidence !== null) {
            $payload['high_confidence'] = $highConfidence;
        }

        $response = Http::timeout($this->alignTimeout ?? $this->timeout)
            ->retry(
                self::RETRY_DELAYS_MS,
                0,
                fn (Throwable $exception, PendingRequest $request): bool => $exception instanceof ConnectionException,
                false,
            )
            ->post("{$this->apiUrl}/align", $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Python alignment service error: {$response->status()} - {$response->body()}"
            );
        }

        $matches = [];

        foreach ($response->json('matches', []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $matches[] = [
                'en_start' => (int) ($raw['en_start'] ?? 0),
                'en_end' => (int) ($raw['en_end'] ?? 0),
                'ru_start' => (int) ($raw['ru_start'] ?? 0),
                'ru_end' => (int) ($raw['ru_end'] ?? 0),
                'score' => (float) ($raw['score'] ?? 0.0),
            ];
        }

        $adapted = $this->adaptMatches($matches, $enSentences, $ruSentences);

        return [...$adapted, 'matches' => $matches];
    }

    /**
     * Convert python alignment matches (index spans) into links + dpPath steps.
     * Unmatched sentences (gaps between/around matches) become skip steps.
     *
     * @param  list<array{en_start: int, en_end: int, ru_start: int, ru_end: int, score: float}>  $matches
     * @return array{links: array, dpPath: array}
     */
    private function adaptMatches(array $matches, Collection $enSentences, Collection $ruSentences): array
    {
        return $this->buildCommittedPath(
            $matches,
            $enSentences,
            $ruSentences,
            $enSentences->count(),
            $ruSentences->count(),
        );
    }

    /**
     * Build links + dpPath for a committed prefix of python matches. Skip
     * steps are only emitted up to the last committed match's end indices
     * (or an explicit stop), so sentences after the commit boundary are left
     * untouched — they are re-aligned with fresh context in the next chunk.
     *
     * @param  list<array{en_start: int, en_end: int, ru_start: int, ru_end: int, score: float}>  $committedMatches
     * @return array{links: array, dpPath: array}
     */
    private function buildCommittedPath(
        array $committedMatches,
        Collection $enSentences,
        Collection $ruSentences,
        ?int $enStop = null,
        ?int $ruStop = null,
    ): array {
        $enIds = $enSentences->pluck('id')->values()->all();
        $ruIds = $ruSentences->pluck('id')->values()->all();
        $enOrders = $enSentences->pluck('order', 'id')->toArray();
        $ruOrders = $ruSentences->pluck('order', 'id')->toArray();

        $lastCommitted = $committedMatches[array_key_last($committedMatches)] ?? null;
        $enStop ??= (int) ($lastCommitted['en_end'] ?? 0);
        $ruStop ??= (int) ($lastCommitted['ru_end'] ?? 0);

        $steps = [];
        $i = 0;
        $j = 0;

        foreach ($committedMatches as $match) {
            $enStart = (int) $match['en_start'];
            $enEnd = (int) $match['en_end'];
            $ruStart = (int) $match['ru_start'];
            $ruEnd = (int) $match['ru_end'];

            while ($i < $enStart) {
                $steps[] = ['type' => 'skip_en', 'index' => $i];
                $i++;
            }
            while ($j < $ruStart) {
                $steps[] = ['type' => 'skip_ru', 'index' => $j];
                $j++;
            }

            $steps[] = [
                'type' => 'match',
                'en_start' => $enStart,
                'en_end' => $enEnd,
                'ru_start' => $ruStart,
                'ru_end' => $ruEnd,
                'score' => (float) ($match['score'] ?? 0.0),
            ];

            $i = $enEnd;
            $j = $ruEnd;
        }

        while ($i < $enStop) {
            $steps[] = ['type' => 'skip_en', 'index' => $i];
            $i++;
        }
        while ($j < $ruStop) {
            $steps[] = ['type' => 'skip_ru', 'index' => $j];
            $j++;
        }

        $links = [];
        $dpPath = [];
        $linkGroup = 0;

        foreach ($steps as $alignmentOrder => $step) {
            if ($step['type'] === 'match') {
                $linkGroup++;

                for ($ei = $step['en_start']; $ei < $step['en_end']; $ei++) {
                    for ($rj = $step['ru_start']; $rj < $step['ru_end']; $rj++) {
                        if (! isset($enIds[$ei]) || ! isset($ruIds[$rj])) {
                            continue;
                        }

                        $links[] = [
                            'en_entity_sentence_id' => $enIds[$ei],
                            'ru_entity_sentence_id' => $ruIds[$rj],
                            'en_order' => $enOrders[$enIds[$ei]],
                            'ru_order' => $ruOrders[$ruIds[$rj]],
                            'link_group' => $linkGroup,
                            'similarity' => round($step['score'], 4),
                            'alignment_order' => $alignmentOrder,
                        ];
                    }
                }

                $dpPath[] = ['type' => 'match', 'alignment_order' => $alignmentOrder];
            } elseif ($step['type'] === 'skip_en') {
                $dpPath[] = [
                    'type' => 'skip_en',
                    'en_sentence_id' => $enIds[$step['index']] ?? null,
                    'alignment_order' => $alignmentOrder,
                ];
            } else {
                $dpPath[] = [
                    'type' => 'skip_ru',
                    'ru_sentence_id' => $ruIds[$step['index']] ?? null,
                    'alignment_order' => $alignmentOrder,
                ];
            }
        }

        return [
            'links' => $links,
            'dpPath' => $dpPath,
        ];
    }

    /**
     * Store alignment meaning matches for a single chunk.
     */
    public function storeAlignmentSegment(
        EnRuEntityMatch $entityMatch,
        int $alignmentChunk,
        array $links,
        array $dpPathSegment,
    ): void {
        $this->persistSegment($entityMatch, $alignmentChunk, $links, $dpPathSegment);
    }

    /**
     * Store the committed prefix of python matches for a single chunk. Only
     * the passed matches are persisted; sentences after the last committed
     * match (up to chunk end) are left untouched and re-fed on the next
     * invocation. On the last chunk the full window is stored, including
     * trailing skip markers.
     *
     * @param  list<array{en_start: int, en_end: int, ru_start: int, ru_end: int, score: float}>  $committedMatches
     */
    public function storeAlignmentSegmentFromMatches(
        EnRuEntityMatch $entityMatch,
        int $alignmentChunk,
        array $committedMatches,
        Collection $enSentences,
        Collection $ruSentences,
        bool $isLastChunk = false,
    ): void {
        if ($committedMatches === []) {
            return;
        }

        $path = $isLastChunk
            ? $this->buildCommittedPath($committedMatches, $enSentences, $ruSentences, $enSentences->count(), $ruSentences->count())
            : $this->buildCommittedPath($committedMatches, $enSentences, $ruSentences);

        $this->persistSegment($entityMatch, $alignmentChunk, $path['links'], $path['dpPath']);
    }

    /**
     * Store single-sided (skip) meaning matches for sentences on one side.
     * Each sentence becomes a meaning match with only that side junctioned,
     * keeping it visible in the reader while the other column stays empty.
     *
     * @param  'en'|'ru'  $side
     */
    public function storeSkipSentences(
        EnRuEntityMatch $entityMatch,
        int $alignmentChunk,
        string $side,
        Collection $sentences,
    ): void {
        if ($sentences->isEmpty()) {
            return;
        }

        $type = $side === 'en' ? 'skip_en' : 'skip_ru';

        $this->persistSegment(
            $entityMatch,
            $alignmentChunk,
            [],
            $this->buildSkipOnlyPath($type, $sentences->pluck('id')->values()->all()),
        );
    }

    private function persistSegment(
        EnRuEntityMatch $entityMatch,
        int $alignmentChunk,
        array $links,
        array $dpPathSegment,
    ): void {
        DB::transaction(function () use ($entityMatch, $alignmentChunk, $links, $dpPathSegment) {
            $now = now();

            EnRuMeaningMatch::query()
                ->where('en_ru_entity_match_id', $entityMatch->id)
                ->where('alignment_chunk', $alignmentChunk)
                ->delete();

            $maxOrder = EnRuMeaningMatch::query()
                ->where('en_ru_entity_match_id', $entityMatch->id)
                ->max('order');

            $sparseOrder = app(SparseOrderService::class);
            $nextAlignmentOrder = $maxOrder === null ? 0 : ((int) $maxOrder) + SparseOrderService::STRIDE;

            $linksByOrder = collect($links)->groupBy('alignment_order');

            foreach ($dpPathSegment as $step) {
                $order = $nextAlignmentOrder + $sparseOrder->initial((int) $step['alignment_order']);
                $stepLinks = $linksByOrder->get($step['alignment_order'], collect());

                $similarity = $step['type'] === 'match' && $stepLinks->isNotEmpty()
                    ? round((float) $stepLinks->avg('similarity'), 4)
                    : 0.0;

                $meaningMatch = EnRuMeaningMatch::create([
                    'en_ru_entity_match_id' => $entityMatch->id,
                    'order' => $order,
                    'similarity' => $similarity,
                    'alignment_chunk' => $alignmentChunk,
                ]);

                if ($step['type'] === 'match') {
                    $enRows = $stepLinks
                        ->unique('en_entity_sentence_id')
                        ->sortBy('en_order')
                        ->values()
                        ->map(fn (array $link) => [
                            'en_entity_sentence_id' => $link['en_entity_sentence_id'],
                            'en_ru_meaning_match_id' => $meaningMatch->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])
                        ->all();

                    $ruRows = $stepLinks
                        ->unique('ru_entity_sentence_id')
                        ->sortBy('ru_order')
                        ->values()
                        ->map(fn (array $link) => [
                            'ru_entity_sentence_id' => $link['ru_entity_sentence_id'],
                            'en_ru_meaning_match_id' => $meaningMatch->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])
                        ->all();

                    foreach (array_chunk($enRows, 500) as $chunk) {
                        EnSentenceMeaningMatch::insert($chunk);
                    }

                    foreach (array_chunk($ruRows, 500) as $chunk) {
                        RuSentenceMeaningMatch::insert($chunk);
                    }

                    continue;
                }

                if ($step['type'] === 'skip_en' && ! empty($step['en_sentence_id'])) {
                    EnSentenceMeaningMatch::create([
                        'en_entity_sentence_id' => $step['en_sentence_id'],
                        'en_ru_meaning_match_id' => $meaningMatch->id,
                    ]);

                    continue;
                }

                if ($step['type'] === 'skip_ru' && ! empty($step['ru_sentence_id'])) {
                    RuSentenceMeaningMatch::create([
                        'ru_entity_sentence_id' => $step['ru_sentence_id'],
                        'en_ru_meaning_match_id' => $meaningMatch->id,
                    ]);
                }
            }

            $entityMatch->update([
                'linked_count' => $this->countLinkedPairs($entityMatch->id),
            ]);
        });
    }

    /**
     * Store alignment links and update the entity match.
     */
    public function storeLinks(
        EnRuEntityMatch $entityMatch,
        array $links,
        array $dpPathSegment,
    ): void {
        $this->storeAlignmentSegment($entityMatch, 0, $links, $dpPathSegment);
    }

    private function countLinkedPairs(int $entityMatchId): int
    {
        return (int) EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatchId)
            ->count();
    }

    /**
     * @param  list<int>  $sentenceIds
     */
    private function buildSkipOnlyPath(string $type, array $sentenceIds): array
    {
        $path = [];

        foreach (array_values($sentenceIds) as $alignmentOrder => $sentenceId) {
            $path[] = [
                'type' => $type,
                ($type === 'skip_en' ? 'en_sentence_id' : 'ru_sentence_id') => $sentenceId,
                'alignment_order' => $alignmentOrder,
            ];
        }

        return $path;
    }

    /**
     * Cosine similarity between two vectors.
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        return $this->dotProduct($a, $b);
    }

    /**
     * Dot product of two vectors (optimized for L2-normalized vectors).
     */
    private function dotProduct(array $a, array $b): float
    {
        $dot = 0.0;
        $count = min(count($a), count($b));

        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
        }

        return $dot;
    }
}
