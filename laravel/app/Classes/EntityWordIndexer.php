<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\EntityWord;
use Illuminate\Support\Facades\DB;

class EntityWordIndexer
{
    private const CHUNK_SIZE = 500;

    public function __construct(private readonly WordTokenizer $tokenizer) {}

    /**
     * The index is stale when it was never built or any sentence changed after
     * the last build.
     */
    public function isStale(Entity $entity): bool
    {
        if ($entity->words_indexed_at === null) {
            return true;
        }

        $lastSentenceChange = EntitySentence::query()
            ->where('entity_id', $entity->id)
            ->max('updated_at');

        return $lastSentenceChange !== null && $lastSentenceChange > $entity->words_indexed_at;
    }

    /**
     * Rebuild the entity word list from the entity's sentences.
     *
     * @return int Number of unique words written.
     */
    public function index(Entity $entity): int
    {
        $lastSentenceChange = EntitySentence::query()
            ->where('entity_id', $entity->id)
            ->max('updated_at');

        $indexedAt = $lastSentenceChange ?? now();

        DB::transaction(function () use ($entity, $indexedAt): void {
            EntityWord::query()
                ->where('entity_id', $entity->id)
                ->delete();

            $words = [];

            EntitySentence::query()
                ->where('entity_id', $entity->id)
                ->orderBy('order')
                ->select('id', 'content')
                ->chunkById(self::CHUNK_SIZE, function ($sentences) use (&$words): void {
                    foreach ($sentences as $sentence) {
                        foreach ($this->tokenizer->tokenize($sentence->content) as $lWord => $entry) {
                            if (isset($words[$lWord])) {
                                $words[$lWord]['count'] += $entry['count'];
                            } else {
                                $words[$lWord] = [
                                    'token' => $entry['token'],
                                    'count' => $entry['count'],
                                ];
                            }
                        }
                    }
                });

            $rows = [];
            foreach ($words as $lWord => $entry) {
                $rows[] = [
                    'entity_id' => $entity->id,
                    'l_word' => $lWord,
                    'token' => $entry['token'],
                    'count' => $entry['count'],
                ];

                if (count($rows) >= self::CHUNK_SIZE) {
                    EntityWord::query()->insert($rows);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                EntityWord::query()->insert($rows);
            }

            $entity->forceFill(['words_indexed_at' => $indexedAt])->save();
        });

        return EntityWord::query()->where('entity_id', $entity->id)->count();
    }
}
