<?php

namespace App\Classes;

use App\Models\Entity;
use Illuminate\Support\Collection;

/**
 * Python-match adapter for the auto-align pipeline: verifies that two
 * entities are translations of the same text, aligns a chunk of sentences
 * through the python service, and adapts raw index-span matches into the
 * links / dpPath arrays that MeaningMatchStore persists. Transport lives in
 * PythonClient (ADR 0061); every DB write lives in MeaningMatchStore.
 */
class SentenceAlignmentService
{
    private const VERIFY_THRESHOLD = 0.70;

    public function __construct(private readonly PythonClient $python) {}

    public static function create(): self
    {
        return new self(PythonClient::create());
    }

    /**
     * Verify that two entities are translations of the same text.
     */
    public function verifyEntityPair(Entity $aEntity, Entity $bEntity): array
    {
        $aSignature = json_decode($aEntity->signature, true);
        $bSignature = json_decode($bEntity->signature, true);

        if (! is_array($aSignature) || ! is_array($bSignature)) {
            return ['similarity' => 0.0, 'passed' => false, 'message' => 'Missing entity signatures'];
        }

        $similarity = $this->cosineSimilarity($aSignature, $bSignature);
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
     * into links + dpPath steps for MeaningMatchStore::storeAlignmentSegmentFromMatches().
     * The raw python matches are returned too so the caller can trim to the
     * last confident anchor before persisting (see AlignEntitySentences).
     *
     * Optional landmarks (hard human-made pins) and a high-confidence prepass
     * bar are passed straight through to the python service.
     *
     * @param  list<array{a_start: int, a_end: int, b_start: int, b_end: int}>  $landmarks
     * @return array{links: array, dpPath: array, matches: array}
     */
    public function alignChunkRemote(
        Collection $aSentences,
        Collection $bSentences,
        int $maxN = 3,
        array $landmarks = [],
        ?float $highConfidence = null,
    ): array {
        $aIds = $aSentences->pluck('id')->values()->all();
        $bIds = $bSentences->pluck('id')->values()->all();

        if (count($aIds) === 0) {
            return [
                'links' => [],
                'dpPath' => $this->buildSkipOnlyPath('skip_b', $bIds),
                'matches' => [],
            ];
        }

        if (count($bIds) === 0) {
            return [
                'links' => [],
                'dpPath' => $this->buildSkipOnlyPath('skip_a', $aIds),
                'matches' => [],
            ];
        }

        $payload = [
            'a_sentences' => $aSentences->pluck('content')->map(fn ($c) => (string) $c)->values()->all(),
            'b_sentences' => $bSentences->pluck('content')->map(fn ($c) => (string) $c)->values()->all(),
            'max_window' => max(1, $maxN),
        ];

        if ($landmarks !== []) {
            $payload['landmarks'] = $landmarks;
        }

        if ($highConfidence !== null) {
            $payload['high_confidence'] = $highConfidence;
        }

        $matches = $this->python->align($payload);

        $adapted = $this->adaptMatches($matches, $aSentences, $bSentences);

        return [...$adapted, 'matches' => $matches];
    }

    /**
     * Convert python alignment matches (index spans) into links + dpPath steps.
     * Unmatched sentences (gaps between/around matches) become skip steps.
     *
     * @param  list<array{a_start: int, a_end: int, b_start: int, b_end: int, score: float}>  $matches
     * @return array{links: array, dpPath: array}
     */
    private function adaptMatches(array $matches, Collection $aSentences, Collection $bSentences): array
    {
        return $this->buildCommittedPath(
            $matches,
            $aSentences,
            $bSentences,
            $aSentences->count(),
            $bSentences->count(),
        );
    }

    /**
     * Build links + dpPath for a committed prefix of python matches. Skip
     * steps are only emitted up to the last committed match's end indices
     * (or an explicit stop), so sentences after the commit boundary are left
     * untouched — they are re-aligned with fresh context in the next chunk.
     *
     * This is the seam between the adapter and the write path: MeaningMatchStore
     * consumes the arrays these builders produce. Link rows carry
     * {a_sentence_id, b_sentence_id, a_order, b_order, link_group, similarity,
     * alignment_order}; dpPath steps are {type: match|skip_a|skip_b,
     * a/b_sentence_id?, alignment_order}. Match steps carry the links of one
     * aligned window group (link_group / alignment_order index into them).
     *
     * @param  list<array{a_start: int, a_end: int, b_start: int, b_end: int, score: float}>  $committedMatches
     * @return array{links: array, dpPath: array}
     */
    public function buildCommittedPath(
        array $committedMatches,
        Collection $aSentences,
        Collection $bSentences,
        ?int $aStop = null,
        ?int $bStop = null,
    ): array {
        $aIds = $aSentences->pluck('id')->values()->all();
        $bIds = $bSentences->pluck('id')->values()->all();
        $aOrders = $aSentences->pluck('order', 'id')->toArray();
        $bOrders = $bSentences->pluck('order', 'id')->toArray();

        $lastCommitted = $committedMatches[array_key_last($committedMatches)] ?? null;
        $aStop ??= (int) ($lastCommitted['a_end'] ?? 0);
        $bStop ??= (int) ($lastCommitted['b_end'] ?? 0);

        $steps = [];
        $i = 0;
        $j = 0;

        foreach ($committedMatches as $match) {
            $aStart = (int) $match['a_start'];
            $aEnd = (int) $match['a_end'];
            $bStart = (int) $match['b_start'];
            $bEnd = (int) $match['b_end'];

            while ($i < $aStart) {
                $steps[] = ['type' => 'skip_a', 'index' => $i];
                $i++;
            }
            while ($j < $bStart) {
                $steps[] = ['type' => 'skip_b', 'index' => $j];
                $j++;
            }

            $steps[] = [
                'type' => 'match',
                'a_start' => $aStart,
                'a_end' => $aEnd,
                'b_start' => $bStart,
                'b_end' => $bEnd,
                'score' => (float) ($match['score'] ?? 0.0),
            ];

            $i = $aEnd;
            $j = $bEnd;
        }

        while ($i < $aStop) {
            $steps[] = ['type' => 'skip_a', 'index' => $i];
            $i++;
        }
        while ($j < $bStop) {
            $steps[] = ['type' => 'skip_b', 'index' => $j];
            $j++;
        }

        $links = [];
        $dpPath = [];
        $linkGroup = 0;

        foreach ($steps as $alignmentOrder => $step) {
            if ($step['type'] === 'match') {
                $linkGroup++;

                for ($ai = $step['a_start']; $ai < $step['a_end']; $ai++) {
                    for ($bj = $step['b_start']; $bj < $step['b_end']; $bj++) {
                        if (! isset($aIds[$ai]) || ! isset($bIds[$bj])) {
                            continue;
                        }

                        $links[] = [
                            'a_sentence_id' => $aIds[$ai],
                            'b_sentence_id' => $bIds[$bj],
                            'a_order' => $aOrders[$aIds[$ai]],
                            'b_order' => $bOrders[$bIds[$bj]],
                            'link_group' => $linkGroup,
                            'similarity' => round($step['score'], 4),
                            'alignment_order' => $alignmentOrder,
                        ];
                    }
                }

                $dpPath[] = ['type' => 'match', 'alignment_order' => $alignmentOrder];
            } elseif ($step['type'] === 'skip_a') {
                $dpPath[] = [
                    'type' => 'skip_a',
                    'a_sentence_id' => $aIds[$step['index']] ?? null,
                    'alignment_order' => $alignmentOrder,
                ];
            } else {
                $dpPath[] = [
                    'type' => 'skip_b',
                    'b_sentence_id' => $bIds[$step['index']] ?? null,
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
     * Build a dpPath of only skip steps — the shape storeSkipSentences()
     * persists for single-sided sentences and alignChunkRemote() returns for
     * an empty side. One of skip_a/skip_b; each step carries its sentence id.
     *
     * @param  'skip_a'|'skip_b'  $type
     * @param  list<int>  $sentenceIds
     */
    public function buildSkipOnlyPath(string $type, array $sentenceIds): array
    {
        $path = [];

        foreach (array_values($sentenceIds) as $alignmentOrder => $sentenceId) {
            $path[] = [
                'type' => $type,
                ($type === 'skip_a' ? 'a_sentence_id' : 'b_sentence_id') => $sentenceId,
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
