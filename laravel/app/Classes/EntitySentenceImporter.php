<?php

namespace App\Classes;

use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\RuSentenceMeaningMatch;
use App\Models\SentenceType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EntitySentenceImporter
{
    public function __construct(
        private readonly SparseOrderService $sparseOrder,
    ) {}

    /**
     * @return list<array{en: string, ru: string}>
     */
    public function parsePairs(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException("Cannot read file: {$path}");
        }

        $expecting = 'en';
        $currentEn = null;
        $pairs = [];

        try {
            while (($raw = fgets($handle)) !== false) {
                $line = trim($raw);

                if ($line === '') {
                    continue;
                }

                if ($expecting === 'en') {
                    $currentEn = $line;
                    $expecting = 'ru';

                    continue;
                }

                $pairs[] = ['en' => $currentEn, 'ru' => $line];
                $expecting = 'en';
            }
        } finally {
            fclose($handle);
        }

        if ($expecting === 'ru') {
            throw new \RuntimeException('Missing Russian sentence for the last English sentence.');
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

    public function import(EnEntity $enEntity, RuEntity $ruEntity, string $path): EntitySentenceImportResult
    {
        $pairs = $this->parsePairs($path);

        if ($pairs === []) {
            throw new \RuntimeException('No sentence pairs found in file.');
        }

        $sentenceTypeId = SentenceType::query()->where('name', 'sentence')->value('id');

        if ($sentenceTypeId === null) {
            throw new \RuntimeException('Sentence type "sentence" not found. Run the SentenceTypeSeeder first.');
        }

        $entityMatch = DB::transaction(function () use ($enEntity, $ruEntity, $pairs, $sentenceTypeId): EnRuEntityMatch {
            $entityMatch = EnRuEntityMatch::query()->firstOrCreate([
                'en_entity_id' => $enEntity->id,
                'ru_entity_id' => $ruEntity->id,
            ]);

            $entityMatch->meaningMatches()->delete();
            $enEntity->sentences()->delete();
            $ruEntity->sentences()->delete();

            $now = Carbon::now();
            $pairCount = count($pairs);

            $enSentenceRows = [];
            $ruSentenceRows = [];

            foreach ($pairs as $index => $pair) {
                $order = $this->sparseOrder->initial($index);

                $enSentenceRows[] = [
                    'en_entity_id' => $enEntity->id,
                    'sentence_type_id' => $sentenceTypeId,
                    'content' => $pair['en'],
                    'order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $ruSentenceRows[] = [
                    'ru_entity_id' => $ruEntity->id,
                    'sentence_type_id' => $sentenceTypeId,
                    'content' => $pair['ru'],
                    'order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($enSentenceRows, 500) as $chunk) {
                EnEntitySentence::query()->insert($chunk);
            }

            foreach (array_chunk($ruSentenceRows, 500) as $chunk) {
                RuEntitySentence::query()->insert($chunk);
            }

            $enSentences = EnEntitySentence::query()
                ->where('en_entity_id', $enEntity->id)
                ->orderBy('order')
                ->get();

            $ruSentences = RuEntitySentence::query()
                ->where('ru_entity_id', $ruEntity->id)
                ->orderBy('order')
                ->get();

            $meaningMatchRows = [];

            foreach ($pairs as $index => $pair) {
                $meaningMatchRows[] = [
                    'en_ru_entity_match_id' => $entityMatch->id,
                    'order' => $this->sparseOrder->initial($index),
                    'similarity' => 1.0,
                    'alignment_chunk' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($meaningMatchRows, 500) as $chunk) {
                EnRuMeaningMatch::query()->insert($chunk);
            }

            $meaningMatches = EnRuMeaningMatch::query()
                ->where('en_ru_entity_match_id', $entityMatch->id)
                ->orderBy('order')
                ->get();

            $enJunctionRows = [];
            $ruJunctionRows = [];

            foreach ($meaningMatches as $index => $meaningMatch) {
                $enJunctionRows[] = [
                    'en_entity_sentence_id' => $enSentences[$index]->id,
                    'en_ru_meaning_match_id' => $meaningMatch->id,
                    'order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $ruJunctionRows[] = [
                    'ru_entity_sentence_id' => $ruSentences[$index]->id,
                    'en_ru_meaning_match_id' => $meaningMatch->id,
                    'order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($enJunctionRows, 500) as $chunk) {
                EnSentenceMeaningMatch::query()->insert($chunk);
            }

            foreach (array_chunk($ruJunctionRows, 500) as $chunk) {
                RuSentenceMeaningMatch::query()->insert($chunk);
            }

            $entityMatch->update([
                'status' => 'completed',
                'en_total_sentences' => $pairCount,
                'ru_total_sentences' => $pairCount,
                'linked_count' => $pairCount,
                'completed_at' => $now,
            ]);

            return $entityMatch;
        });

        return new EntitySentenceImportResult(
            entityMatch: $entityMatch,
            enEntity: $enEntity,
            ruEntity: $ruEntity,
            pairCount: count($pairs),
        );
    }
}
