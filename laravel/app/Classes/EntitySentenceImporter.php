<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EntitySentenceImporter
{
    public function __construct(
        private readonly SparseOrderService $sparseOrder,
    ) {}

    /**
     * Parse an alternating first/second sentence file into pairs.
     *
     * @return list<array{first: string, second: string}>
     */
    public function parsePairs(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException("Cannot read file: {$path}");
        }

        $expectingSecond = false;
        $currentFirst = null;
        $pairs = [];

        try {
            while (($raw = fgets($handle)) !== false) {
                $line = trim($raw);

                if ($line === '') {
                    continue;
                }

                if (! $expectingSecond) {
                    $currentFirst = $line;
                    $expectingSecond = true;

                    continue;
                }

                $pairs[] = ['first' => $currentFirst, 'second' => $line];
                $expectingSecond = false;
            }
        } finally {
            fclose($handle);
        }

        if ($expectingSecond) {
            throw new \RuntimeException('Missing second sentence for the last first sentence.');
        }

        return $pairs;
    }

    public function resolvePath(string $file): ?string
    {
        if (is_file($file)) {
            return $file;
        }

        $basePath = base_path($file);

        if (is_file($basePath)) {
            return $basePath;
        }

        $publicPath = public_path($file);

        if (is_file($publicPath)) {
            return $publicPath;
        }

        return null;
    }

    public function import(Entity $aEntity, Entity $bEntity, string $path): EntitySentenceImportResult
    {
        if ($aEntity->work_id !== $bEntity->work_id) {
            throw new \RuntimeException('Both entities must belong to the same work.');
        }

        $pairs = $this->parsePairs($path);

        if ($pairs === []) {
            throw new \RuntimeException('No sentence pairs found in file.');
        }

        $sentenceTypeId = SentenceType::query()->where('name', 'sentence')->value('id');

        if ($sentenceTypeId === null) {
            throw new \RuntimeException('Sentence type "sentence" not found. Run the SentenceTypeSeeder first.');
        }

        [$aEntity, $bEntity] = $aEntity->id < $bEntity->id
            ? [$aEntity, $bEntity]
            : [$bEntity, $aEntity];

        $entityMatch = DB::transaction(function () use ($aEntity, $bEntity, $pairs, $sentenceTypeId): EntityMatch {
            $entityMatch = EntityMatch::query()->firstOrCreate([
                'a_entity_id' => $aEntity->id,
                'b_entity_id' => $bEntity->id,
            ]);

            $entityMatch->meaningMatches()->delete();
            $aEntity->sentences()->delete();
            $bEntity->sentences()->delete();

            $now = Carbon::now();
            $pairCount = count($pairs);

            $aSentenceRows = [];
            $bSentenceRows = [];

            foreach ($pairs as $index => $pair) {
                $order = $this->sparseOrder->initial($index);

                $aSentenceRows[] = [
                    'entity_id' => $aEntity->id,
                    'sentence_type_id' => $sentenceTypeId,
                    'content' => $pair['first'],
                    'order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $bSentenceRows[] = [
                    'entity_id' => $bEntity->id,
                    'sentence_type_id' => $sentenceTypeId,
                    'content' => $pair['second'],
                    'order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($aSentenceRows, 500) as $chunk) {
                EntitySentence::query()->insert($chunk);
            }

            foreach (array_chunk($bSentenceRows, 500) as $chunk) {
                EntitySentence::query()->insert($chunk);
            }

            $aSentences = EntitySentence::query()
                ->where('entity_id', $aEntity->id)
                ->orderBy('order')
                ->get();

            $bSentences = EntitySentence::query()
                ->where('entity_id', $bEntity->id)
                ->orderBy('order')
                ->get();

            $meaningMatchRows = [];

            foreach ($pairs as $index => $pair) {
                $meaningMatchRows[] = [
                    'entity_match_id' => $entityMatch->id,
                    'order' => $this->sparseOrder->initial($index),
                    'similarity' => 1.0,
                    'alignment_chunk' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($meaningMatchRows, 500) as $chunk) {
                MeaningMatch::query()->insert($chunk);
            }

            $meaningMatches = MeaningMatch::query()
                ->where('entity_match_id', $entityMatch->id)
                ->orderBy('order')
                ->get();

            $junctionRows = [];

            foreach ($meaningMatches as $index => $meaningMatch) {
                $junctionRows[] = [
                    'entity_sentence_id' => $aSentences[$index]->id,
                    'meaning_match_id' => $meaningMatch->id,
                    'side' => 'a',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $junctionRows[] = [
                    'entity_sentence_id' => $bSentences[$index]->id,
                    'meaning_match_id' => $meaningMatch->id,
                    'side' => 'b',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($junctionRows, 500) as $chunk) {
                SentenceMeaningMatch::query()->insert($chunk);
            }

            $entityMatch->update([
                'status' => 'completed',
                'a_total_sentences' => $pairCount,
                'b_total_sentences' => $pairCount,
                'linked_count' => $pairCount,
                'completed_at' => $now,
            ]);

            // Bulk writes bypass model events; mark both sentence sets changed.
            Entity::touchSentencesFor($aEntity->id);
            Entity::touchSentencesFor($bEntity->id);

            return $entityMatch;
        });

        return new EntitySentenceImportResult(
            entityMatch: $entityMatch,
            aEntity: $aEntity,
            bEntity: $bEntity,
            pairCount: count($pairs),
        );
    }
}
