<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\EntitySentenceWord;
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
     * Every sentence gets its token occurrences stored as individual
     * entity_sentence_words rows (lookup key, surface form, ordinal, char
     * span into the sentence content), each stamped with its own updated_at;
     * the entity-level word list is derived from those rows by aggregation.
     * Staleness and the wholesale wipe + refill of the entity list behave
     * exactly as before this substrate existed.
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

            EntitySentenceWord::query()
                ->where('entity_id', $entity->id)
                ->delete();

            EntitySentence::query()
                ->where('entity_id', $entity->id)
                ->orderBy('order')
                ->select('id', 'content')
                ->chunkById(self::CHUNK_SIZE, function ($sentences) use ($entity): void {
                    $rows = [];

                    foreach ($sentences as $sentence) {
                        foreach ($this->tokenizer->tokenizeCountedOccurrences($sentence->content) as $position => $occurrence) {
                            $rows[] = [
                                'entity_id' => $entity->id,
                                'entity_sentence_id' => $sentence->id,
                                'position' => $position,
                                'start' => $occurrence['start'],
                                'end' => $occurrence['end'],
                                'l_word' => $occurrence['l_word'],
                                'token' => $occurrence['token'],
                            ];

                            if (count($rows) >= self::CHUNK_SIZE) {
                                EntitySentenceWord::query()->insert($rows);
                                $rows = [];
                            }
                        }
                    }

                    if ($rows !== []) {
                        EntitySentenceWord::query()->insert($rows);
                    }
                });

            // One statement stamps every sentence with its own updated_at
            // (never now() — a replayed full pass stays stamp-stable). The
            // base builder is required on both counts: it bypasses model
            // events (no parent touch) and the Eloquent updated_at injection
            // — bumping updated_at here would permanently re-stale the
            // entity against its own marker.
            EntitySentence::query()
                ->where('entity_id', $entity->id)
                ->toBase()
                ->update(['words_indexed_at' => DB::raw('updated_at')]);

            $this->deriveEntityWords($entity);

            $entity->forceFill(['words_indexed_at' => $indexedAt])->save();
        });

        return EntityWord::query()->where('entity_id', $entity->id)->count();
    }

    /**
     * Aggregate the per-sentence occurrence rows into the entity word list:
     * one entity_words row per lookup key, count = number of occurrence rows,
     * representative token = the first occurrence's surface in document
     * order — content-identical to the in-memory tokenizer accumulation this
     * replaces.
     */
    private function deriveEntityWords(Entity $entity): void
    {
        $words = [];

        EntitySentence::query()
            ->where('entity_id', $entity->id)
            ->orderBy('order')
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function ($sentences) use (&$words): void {
                $occurrences = EntitySentenceWord::query()
                    ->whereIn('entity_sentence_id', $sentences->modelKeys())
                    ->join('entity_sentences as es', 'es.id', '=', 'entity_sentence_words.entity_sentence_id')
                    ->orderBy('es.order')
                    ->orderBy('entity_sentence_words.position')
                    ->get(['entity_sentence_words.l_word', 'entity_sentence_words.token']);

                foreach ($occurrences as $occurrence) {
                    if (isset($words[$occurrence->l_word])) {
                        $words[$occurrence->l_word]['count']++;
                    } else {
                        $words[$occurrence->l_word] = [
                            'token' => $occurrence->token,
                            'count' => 1,
                        ];
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
    }
}
