<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use Illuminate\Support\Facades\DB;

class AlignmentEditorPersister
{
    /**
     * @param  array{
     *     meaning_rows: list<array<string, mixed>>,
     *     unmatched_a: list<array<string, mixed>>,
     *     unmatched_b: list<array<string, mixed>>
     * }  $draft
     */
    public function persist(EntityMatch $entityMatch, array $draft): void
    {
        DB::transaction(function () use ($entityMatch, $draft): void {
            $entityMatch->load(['aEntity', 'bEntity']);

            $sentenceTypeId = SentenceType::query()->where('name', 'sentence')->value('id');

            $aIdMap = $this->syncSentences(
                entityId: $entityMatch->a_entity_id,
                side: 'a',
                meaningRows: $draft['meaning_rows'],
                unmatched: $draft['unmatched_a'],
                sentenceTypeId: $sentenceTypeId,
            );

            $bIdMap = $this->syncSentences(
                entityId: $entityMatch->b_entity_id,
                side: 'b',
                meaningRows: $draft['meaning_rows'],
                unmatched: $draft['unmatched_b'],
                sentenceTypeId: $sentenceTypeId,
            );

            $this->syncMeaningMatches($entityMatch, $draft['meaning_rows'], $aIdMap, $bIdMap);

            $aCount = EntitySentence::query()->where('entity_id', $entityMatch->a_entity_id)->count();
            $bCount = EntitySentence::query()->where('entity_id', $entityMatch->b_entity_id)->count();

            $linkedCount = MeaningMatch::query()
                ->where('entity_match_id', $entityMatch->id)
                ->count();

            $entityMatch->update([
                'status' => 'completed',
                'a_total_sentences' => $aCount,
                'b_total_sentences' => $bCount,
                'linked_count' => $linkedCount,
            ]);
        });
    }

    /**
     * @param  'a'|'b'  $side
     * @param  list<array<string, mixed>>  $meaningRows
     * @param  list<array<string, mixed>>  $unmatched
     * @return array<string, int>
     */
    private function syncSentences(
        int $entityId,
        string $side,
        array $meaningRows,
        array $unmatched,
        ?int $sentenceTypeId,
    ): array {
        $sideKey = $side === 'a' ? 'a_sentences' : 'b_sentences';
        $allSentences = [];

        foreach ($meaningRows as $row) {
            foreach ($row[$sideKey] as $sentence) {
                if (($sentence['_deleted'] ?? false) === true) {
                    continue;
                }

                $allSentences[] = $sentence;
            }
        }

        foreach ($unmatched as $sentence) {
            if (($sentence['_deleted'] ?? false) === true) {
                continue;
            }

            $allSentences[] = $sentence;
        }

        usort($allSentences, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $idMap = [];
        $keptDbIds = [];

        $existing = EntitySentence::query()->where('entity_id', $entityId)->get()->keyBy('id');

        $updates = [];
        $creates = [];

        foreach ($allSentences as $index => $sentence) {
            $order = (int) ($sentence['order'] ?? app(SparseOrderService::class)->initial($index));
            $content = trim((string) $sentence['content']);

            if ($content === '') {
                continue;
            }

            $sentenceKey = $this->sentenceKey($sentence);

            if ($sentence['id'] !== null && $existing->has($sentence['id'])) {
                $model = $existing->get($sentence['id']);
                if ($model->content !== $content || $model->order !== $order) {
                    $updates[] = [
                        'id' => $sentence['id'],
                        'entity_id' => $entityId,
                        'sentence_type_id' => $sentenceTypeId,
                        'content' => $content,
                        'order' => $order,
                    ];
                }
                $idMap[$sentenceKey] = $sentence['id'];
                $keptDbIds[] = $sentence['id'];

            } else {
                $creates[] = [
                    'key' => $sentenceKey,
                    'attributes' => [
                        'sentence_type_id' => $sentenceTypeId,
                        'content' => $content,
                        'order' => $order,
                    ],
                ];
            }
        }

        if (! empty($updates)) {
            // Park every changed row at a unique negative order first: the
            // final orders are collision-free as a set, but one row's final
            // may be another row's current order, so a single upsert would
            // violate the (entity_id, order) unique index mid-statement.
            foreach ($updates as $update) {
                EntitySentence::query()
                    ->whereKey($update['id'])
                    ->update(['order' => -$update['id'] - 1_000_000_000]);
            }

            foreach (array_chunk($updates, 1000) as $chunk) {
                EntitySentence::upsert($chunk, ['id'], ['content', 'order']);
            }
        }

        foreach ($creates as $create) {
            $model = EntitySentence::query()->create([
                'entity_id' => $entityId,
                ...$create['attributes'],
            ]);

            $idMap[$create['key']] = $model->id;
            $keptDbIds[] = $model->id;
        }

        EntitySentence::query()
            ->where('entity_id', $entityId)
            ->when($keptDbIds !== [], fn ($query) => $query->whereNotIn('id', $keptDbIds))
            ->when($keptDbIds === [], fn ($query) => $query)
            ->delete();

        // Upserts and the bulk delete bypass model events; mark the sentence
        // set changed explicitly.
        Entity::touchSentencesFor($entityId);

        return $idMap;
    }

    /**
     * @param  list<array<string, mixed>>  $meaningRows
     * @param  array<string, int>  $aIdMap
     * @param  array<string, int>  $bIdMap
     */
    private function syncMeaningMatches(
        EntityMatch $entityMatch,
        array $meaningRows,
        array $aIdMap,
        array $bIdMap,
    ): void {
        $sortedRows = $meaningRows;
        usort($sortedRows, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $existingMeaningMatches = MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->get()
            ->keyBy('id');

        $existingMeaningMatchIds = $existingMeaningMatches->keys()->toArray();

        if (! empty($existingMeaningMatchIds)) {
            foreach (array_chunk($existingMeaningMatchIds, 1000) as $chunk) {
                SentenceMeaningMatch::query()->whereIn('meaning_match_id', $chunk)->delete();
            }
        }

        $keptMeaningIds = [];
        $meaningUpdates = [];
        $newRows = [];

        foreach ($sortedRows as $index => $row) {
            $aSentenceIds = $this->resolveSentenceIds($row['a_sentences'], $aIdMap);
            $bSentenceIds = $this->resolveSentenceIds($row['b_sentences'], $bIdMap);
            $order = (int) ($row['order'] ?? app(SparseOrderService::class)->initial($index));

            if ($row['id'] !== null && $existingMeaningMatches->has($row['id'])) {
                $meaningId = $row['id'];
                $model = $existingMeaningMatches->get($meaningId);

                if ($model->order !== $order || $model->similarity != 1.0) {
                    $meaningUpdates[] = [
                        'id' => $meaningId,
                        'entity_match_id' => $entityMatch->id,
                        'order' => $order,
                        'similarity' => 1.0,
                        'alignment_chunk' => $model->alignment_chunk ?? 0,
                    ];
                }
                $keptMeaningIds[] = $meaningId;

                $newRows[] = [
                    'is_new' => false,
                    'meaning_id' => $meaningId,
                    'a_sentences' => $aSentenceIds,
                    'b_sentences' => $bSentenceIds,
                ];
            } else {
                $newRows[] = [
                    'is_new' => true,
                    'order' => $order,
                    'a_sentences' => $aSentenceIds,
                    'b_sentences' => $bSentenceIds,
                ];
            }
        }

        $toDelete = array_diff($existingMeaningMatchIds, $keptMeaningIds);
        if (! empty($toDelete)) {
            foreach (array_chunk($toDelete, 1000) as $chunk) {
                MeaningMatch::query()->whereIn('id', $chunk)->delete();
            }
        }

        if (! empty($meaningUpdates)) {
            // Temporarily shift orders to negative values to avoid unique constraint violations during swaps
            $tempUpdates = array_map(function ($update) {
                return [
                    'id' => $update['id'],
                    'entity_match_id' => $update['entity_match_id'],
                    'order' => -($update['id']),
                    'similarity' => $update['similarity'],
                    'alignment_chunk' => $update['alignment_chunk'],
                ];
            }, $meaningUpdates);

            foreach (array_chunk($tempUpdates, 1000) as $chunk) {
                MeaningMatch::upsert($chunk, ['id'], ['order']);
            }

            foreach (array_chunk($meaningUpdates, 1000) as $chunk) {
                MeaningMatch::upsert($chunk, ['id'], ['order', 'similarity']);
            }
        }

        $junctionInserts = [];
        $now = now()->toDateTimeString();

        foreach ($newRows as $row) {
            if ($row['is_new']) {
                $meaningMatch = MeaningMatch::query()->create([
                    'entity_match_id' => $entityMatch->id,
                    'order' => $row['order'],
                    'similarity' => 1.0,
                    'alignment_chunk' => -1,
                ]);
                $meaningId = $meaningMatch->id;
            } else {
                $meaningId = $row['meaning_id'];
            }

            foreach ($row['a_sentences'] as $sentenceId) {
                $junctionInserts[] = [
                    'entity_sentence_id' => $sentenceId,
                    'meaning_match_id' => $meaningId,
                    'side' => 'a',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($row['b_sentences'] as $sentenceId) {
                $junctionInserts[] = [
                    'entity_sentence_id' => $sentenceId,
                    'meaning_match_id' => $meaningId,
                    'side' => 'b',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (! empty($junctionInserts)) {
            foreach (array_chunk($junctionInserts, 2000) as $chunk) {
                SentenceMeaningMatch::insert($chunk);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $sentences
     * @param  array<string, int>  $idMap
     * @return list<int>
     */
    private function resolveSentenceIds(array $sentences, array $idMap): array
    {
        $ids = [];

        foreach ($sentences as $sentence) {
            if (($sentence['_deleted'] ?? false) === true) {
                continue;
            }
            $key = $this->sentenceKey($sentence);

            if (isset($idMap[$key])) {
                $ids[] = $idMap[$key];
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $sentence
     */
    private function sentenceKey(array $sentence): string
    {
        if ($sentence['id'] !== null) {
            return 's-'.$sentence['id'];
        }

        return (string) ($sentence['key'] ?? $sentence['temp_id'] ?? '');
    }
}
