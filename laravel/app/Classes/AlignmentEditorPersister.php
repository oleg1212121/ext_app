<?php

namespace App\Classes;

use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\RuEntitySentence;
use App\Models\RuSentenceMeaningMatch;
use App\Models\SentenceType;
use Illuminate\Support\Facades\DB;

class AlignmentEditorPersister
{
    /**
     * @param  array{
     *     meaning_rows: list<array<string, mixed>>,
     *     unmatched_en: list<array<string, mixed>>,
     *     unmatched_ru: list<array<string, mixed>>
     * }  $draft
     */
    public function persist(EnRuEntityMatch $entityMatch, array $draft): void
    {
        DB::transaction(function () use ($entityMatch, $draft): void {
            $entityMatch->load(['enEntity', 'ruEntity']);

            $sentenceTypeId = SentenceType::query()->where('name', 'sentence')->value('id');

            
            $enIdMap = $this->syncSentences(

                entityId: $entityMatch->en_entity_id,
                lang: 'en',
                meaningRows: $draft['meaning_rows'],
                unmatched: $draft['unmatched_en'],
                sentenceTypeId: $sentenceTypeId,
            );

            $ruIdMap = $this->syncSentences(
                entityId: $entityMatch->ru_entity_id,
                lang: 'ru',
                meaningRows: $draft['meaning_rows'],
                unmatched: $draft['unmatched_ru'],
                sentenceTypeId: $sentenceTypeId,
            );

            $this->syncMeaningMatches($entityMatch, $draft['meaning_rows'], $enIdMap, $ruIdMap);

            $enCount = EnEntitySentence::query()
                ->where('en_entity_id', $entityMatch->en_entity_id)
                ->count();

            $ruCount = RuEntitySentence::query()
                ->where('ru_entity_id', $entityMatch->ru_entity_id)
                ->count();

            $linkedCount = EnRuMeaningMatch::query()
                ->where('en_ru_entity_match_id', $entityMatch->id)
                ->count();

            $entityMatch->update([
                'status' => 'completed',
                'en_total_sentences' => $enCount,
                'ru_total_sentences' => $ruCount,
                'linked_count' => $linkedCount,
            ]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $meaningRows
     * @param  list<array<string, mixed>>  $unmatched
     * @return array<string, int>
     */
    private function syncSentences(
        int $entityId,
        string $lang,
        array $meaningRows,
        array $unmatched,
        ?int $sentenceTypeId,
    ): array {
        $sideKey = $lang === 'en' ? 'en_sentences' : 'ru_sentences';
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

        $existing = $lang === 'en'
            ? EnEntitySentence::query()->where('en_entity_id', $entityId)->get()->keyBy('id')
            : RuEntitySentence::query()->where('ru_entity_id', $entityId)->get()->keyBy('id');

        $updates = [];

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
                        $lang === 'en' ? 'en_entity_id' : 'ru_entity_id' => $entityId,
                        'sentence_type_id' => $sentenceTypeId,
                        'content' => $content,
                        'order' => $order,
                    ];
                }
                $idMap[$sentenceKey] = $sentence['id'];
                $keptDbIds[] = $sentence['id'];

            } else {
                $attributes = [
                    'sentence_type_id' => $sentenceTypeId,
                    'content' => $content,
                    'order' => $order,
                ];

                if ($lang === 'en') {
                    $model = EnEntitySentence::query()->create([
                        'en_entity_id' => $entityId,
                        ...$attributes,
                    ]);
                } else {
                    $model = RuEntitySentence::query()->create([
                        'ru_entity_id' => $entityId,
                        ...$attributes,
                    ]);
                }

                $idMap[$sentenceKey] = $model->id;
                $keptDbIds[] = $model->id;
            }
        }

        if (!empty($updates)) {
            foreach (array_chunk($updates, 1000) as $chunk) {
                if ($lang === 'en') {
                    EnEntitySentence::upsert($chunk, ['id'], ['content', 'order']);
                } else {
                    RuEntitySentence::upsert($chunk, ['id'], ['content', 'order']);
                }
            }
        }

        if ($lang === 'en') {
            EnEntitySentence::query()
                ->where('en_entity_id', $entityId)
                ->when($keptDbIds !== [], fn ($query) => $query->whereNotIn('id', $keptDbIds))
                ->when($keptDbIds === [], fn ($query) => $query)
                ->delete();
        } else {
            RuEntitySentence::query()
                ->where('ru_entity_id', $entityId)
                ->when($keptDbIds !== [], fn ($query) => $query->whereNotIn('id', $keptDbIds))
                ->when($keptDbIds === [], fn ($query) => $query)
                ->delete();
        }

        return $idMap;
    }

    /**
     * @param  list<array<string, mixed>>  $meaningRows
     * @param  array<string, int>  $enIdMap
     * @param  array<string, int>  $ruIdMap
     */
    private function syncMeaningMatches(
        EnRuEntityMatch $entityMatch,
        array $meaningRows,
        array $enIdMap,
        array $ruIdMap,
    ): void {
        $sortedRows = $meaningRows;
        usort($sortedRows, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $existingMeaningMatches = EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatch->id)
            ->get()
            ->keyBy('id');

        $existingMeaningMatchIds = $existingMeaningMatches->keys()->toArray();

        if (!empty($existingMeaningMatchIds)) {
            foreach (array_chunk($existingMeaningMatchIds, 1000) as $chunk) {
                EnSentenceMeaningMatch::query()->whereIn('en_ru_meaning_match_id', $chunk)->delete();
                RuSentenceMeaningMatch::query()->whereIn('en_ru_meaning_match_id', $chunk)->delete();
            }
        }

        $keptMeaningIds = [];
        $meaningUpdates = [];
        $newRows = [];

        foreach ($sortedRows as $index => $row) {
            $enSentenceIds = $this->resolveSentenceIds($row['en_sentences'], $enIdMap);
            $ruSentenceIds = $this->resolveSentenceIds($row['ru_sentences'], $ruIdMap);
            $order = (int) ($row['order'] ?? app(SparseOrderService::class)->initial($index));

            if ($row['id'] !== null && $existingMeaningMatches->has($row['id'])) {
                $meaningId = $row['id'];
                $model = $existingMeaningMatches->get($meaningId);
                
                if ($model->order !== $order || $model->similarity != 1.0) {
                    $meaningUpdates[] = [
                        'id' => $meaningId,
                        'en_ru_entity_match_id' => $entityMatch->id,
                        'order' => $order,
                        'similarity' => 1.0,
                        'alignment_chunk' => $model->alignment_chunk ?? 0,
                    ];
                }
                $keptMeaningIds[] = $meaningId;
                
                $newRows[] = [
                    'is_new' => false,
                    'meaning_id' => $meaningId,
                    'en_sentences' => $enSentenceIds,
                    'ru_sentences' => $ruSentenceIds,
                ];
            } else {
                $newRows[] = [
                    'is_new' => true,
                    'order' => $order,
                    'en_sentences' => $enSentenceIds,
                    'ru_sentences' => $ruSentenceIds,
                ];
            }
        }

        $toDelete = array_diff($existingMeaningMatchIds, $keptMeaningIds);
        if (!empty($toDelete)) {
            foreach (array_chunk($toDelete, 1000) as $chunk) {
                EnRuMeaningMatch::query()->whereIn('id', $chunk)->delete();
            }
        }

        if (!empty($meaningUpdates)) {
            // Temporarily shift orders to negative values to avoid unique constraint violations during swaps
            $tempUpdates = array_map(function ($update) {
                return [
                    'id' => $update['id'],
                    'en_ru_entity_match_id' => $update['en_ru_entity_match_id'],
                    'order' => -($update['id']),
                    'similarity' => $update['similarity'],
                    'alignment_chunk' => $update['alignment_chunk'],
                ];
            }, $meaningUpdates);

            foreach (array_chunk($tempUpdates, 1000) as $chunk) {
                EnRuMeaningMatch::upsert($chunk, ['id'], ['order']);
            }

            foreach (array_chunk($meaningUpdates, 1000) as $chunk) {
                EnRuMeaningMatch::upsert($chunk, ['id'], ['order', 'similarity']);
            }
        }

        $enJunctionInserts = [];
        $ruJunctionInserts = [];
        $now = now()->toDateTimeString();

        foreach ($newRows as $row) {
            if ($row['is_new']) {
                $meaningMatch = EnRuMeaningMatch::query()->create([
                    'en_ru_entity_match_id' => $entityMatch->id,
                    'order' => $row['order'],
                    'similarity' => 1.0,
                    'alignment_chunk' => -1,
                ]);
                $meaningId = $meaningMatch->id;
            } else {
                $meaningId = $row['meaning_id'];
            }

            foreach ($row['en_sentences'] as $junctionOrder => $sentenceId) {
                $enJunctionInserts[] = [
                    'en_entity_sentence_id' => $sentenceId,
                    'en_ru_meaning_match_id' => $meaningId,
                    'order' => $junctionOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($row['ru_sentences'] as $junctionOrder => $sentenceId) {
                $ruJunctionInserts[] = [
                    'ru_entity_sentence_id' => $sentenceId,
                    'en_ru_meaning_match_id' => $meaningId,
                    'order' => $junctionOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (!empty($enJunctionInserts)) {
            foreach (array_chunk($enJunctionInserts, 2000) as $chunk) {
                EnSentenceMeaningMatch::insert($chunk);
            }
        }

        if (!empty($ruJunctionInserts)) {
            foreach (array_chunk($ruJunctionInserts, 2000) as $chunk) {
                RuSentenceMeaningMatch::insert($chunk);
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

