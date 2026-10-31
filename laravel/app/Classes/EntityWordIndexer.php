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

    /**
     * Stale sentences re-tokenized per bounded queue run — the budget of the
     * self-restarting chain (see RefreshEntityWords), so a fresh book upload
     * flows through many small yieldable runs instead of one giant job.
     */
    public const SENTENCES_PER_RUN = 500;

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
     * Index the entity's STALE sentences only: a sentence is stale when its
     * per-sentence stamp is missing or older than its own content update.
     * Fresh sentences keep their occurrence rows and stamps untouched; the
     * entity word list is merged from the rows (links preserved) and the
     * entity-level marker is written as the max per-sentence stamp — so a
     * fully-fresh entity is a phase-1 no-op that only re-merges.
     *
     * The synchronous full-pass entry point (crossword:index, tests, setup
     * flows): no per-run budget — every stale sentence in one transaction.
     *
     * @return int Number of unique words written.
     */
    public function index(Entity $entity): int
    {
        DB::transaction(function () use ($entity): void {
            $this->reindexStale($entity, afterId: 0, budget: null);

            $this->mergeEntityWords($entity);

            $this->writeDerivedMarker($entity);
        });

        return EntityWord::query()->where('entity_id', $entity->id)->count();
    }

    /**
     * One bounded run of the incremental index: re-tokenize up to $budget
     * stale sentences with id > $afterId (the cursor), replacing each
     * sentence's occurrence rows and re-stamping just those sentences.
     *
     * When no stale sentence remains ahead of the cursor the chain is done:
     * the entity word list is merged from the per-sentence rows (surviving
     * keys keep their dictionary link) and the entity marker is written as
     * the max per-sentence stamp — only
     * then, so a mid-build entity keeps its stale/null marker and every
     * consumer of that column (crossword generate, recommendations, word
     * knowledge, frequency accrual) reads the list as unfinished exactly as
     * under the old single-job full build. A run that processed nothing on a
     * still-unmarked entity (crashed chain, sentence-less entity) heals the
     * marker; a run that processed nothing on an already-marked entity is a
     * full no-op, like the old job's staleness re-check skip.
     *
     * @return array{processed: int, cursor: int, remaining: bool, derived: bool, unique_words: int|null}
     */
    public function indexBatch(Entity $entity, int $afterId = 0, int $budget = self::SENTENCES_PER_RUN): array
    {
        $stats = [];

        DB::transaction(function () use ($entity, $afterId, $budget, &$stats): void {
            $processedIds = $this->reindexStale($entity, $afterId, $budget);

            $cursor = $processedIds === [] ? $afterId : end($processedIds);

            $remaining = EntitySentence::query()
                ->where('entity_id', $entity->id)
                ->where('id', '>', $cursor)
                ->where(function ($query): void {
                    $query->whereNull('words_indexed_at')
                        ->orWhereColumn('words_indexed_at', '<', 'updated_at');
                })
                ->exists();

            $derived = false;
            $uniqueWords = null;

            if (! $remaining && ($processedIds !== [] || $entity->words_indexed_at === null)) {
                $this->mergeEntityWords($entity);

                $this->writeDerivedMarker($entity);

                $derived = true;
                $uniqueWords = EntityWord::query()->where('entity_id', $entity->id)->count();
            }

            $stats = [
                'processed' => count($processedIds),
                'cursor' => $cursor,
                'remaining' => $remaining,
                'derived' => $derived,
                'unique_words' => $uniqueWords,
            ];
        });

        return $stats;
    }

    /**
     * Re-tokenize stale sentences (missing stamp, or stamp older than the
     * sentence's own updated_at) with id > $afterId, at most $budget of them
     * (null = all), replacing each one's occurrence rows. The walk paginates
     * by sentence id — immune to the stale filter mutating under it as
     * sentences get stamped, unlike chunkById over the moving filter.
     *
     * @return list<int> Ids of the re-tokenized sentences, ascending.
     */
    private function reindexStale(Entity $entity, int $afterId, ?int $budget): array
    {
        $processedIds = [];
        $rows = [];
        $cursor = $afterId;

        while ($budget === null || count($processedIds) < $budget) {
            $sentences = EntitySentence::query()
                ->where('entity_id', $entity->id)
                ->where('id', '>', $cursor)
                ->where(function ($query): void {
                    $query->whereNull('words_indexed_at')
                        ->orWhereColumn('words_indexed_at', '<', 'updated_at');
                })
                ->orderBy('id')
                ->limit($budget === null ? self::CHUNK_SIZE : min(self::CHUNK_SIZE, $budget - count($processedIds)))
                ->get(['id', 'content']);

            if ($sentences->isEmpty()) {
                break;
            }

            // Replace exactly the fetched sentences' rows — a stale
            // sentence's old rows are wrong by definition; fresh sentences
            // outside this window are never touched.
            EntitySentenceWord::query()
                ->whereIn('entity_sentence_id', $sentences->modelKeys())
                ->delete();

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

                $processedIds[] = $sentence->id;
                $cursor = $sentence->id;
            }
        }

        if ($rows !== []) {
            EntitySentenceWord::query()->insert($rows);
        }

        foreach (array_chunk($processedIds, self::CHUNK_SIZE) as $ids) {
            // Re-stamp ONLY the re-tokenized sentences, each with its own
            // updated_at (never now() — the stamp must survive replays). The
            // base builder is required: it bypasses model events (no parent
            // touch) and the Eloquent updated_at injection — bumping
            // updated_at here would permanently re-stale the sentence
            // against its own stamp.
            EntitySentence::query()
                ->whereIn('id', $ids)
                ->toBase()
                ->update(['words_indexed_at' => DB::raw('updated_at')]);
        }

        return $processedIds;
    }

    /**
     * The entity-level marker becomes a DERIVED MAX over the per-sentence
     * stamps (now() fallback for a sentence-less entity, as before): one
     * column the cheap staleness checks keep comparing. Written only when a
     * chain completes, so the marker's meaning is unchanged — non-null still
     * means "the whole list was built".
     */
    private function writeDerivedMarker(Entity $entity): void
    {
        $maxStamp = EntitySentence::query()
            ->where('entity_id', $entity->id)
            ->max('words_indexed_at');

        $entity->forceFill(['words_indexed_at' => $maxStamp ?? now()])->save();
    }

    /**
     * Derive the entity word list from the per-sentence occurrence rows by
     * MERGING it into entity_words instead of wiping and refilling: one row
     * per lookup key, count = number of occurrence rows, representative
     * token = the first occurrence's surface in document order.
     *
     * Keys that already have a row keep that row — its dictionary link
     * (word_id), unmatchable stamp and audit timestamps stay untouched, only
     * count/token are rewritten (and only when they actually changed) — so a
     * re-index no longer throws every surviving word back into the unlinked
     * "not in the dictionary" state until the next link pass. Keys that
     * vanished from the text have their row deleted; genuinely new keys
     * insert unlinked (word_id/unmatchable_at null) for the ordinary link
     * pass to pick up, as they always have. Idempotent: merging an aggregate
     * that matches the current rows writes nothing.
     */
    private function mergeEntityWords(Entity $entity): void
    {
        $words = $this->aggregateOccurrences($entity);

        $existing = EntityWord::query()
            ->where('entity_id', $entity->id)
            ->get(['id', 'l_word', 'token', 'count'])
            ->keyBy('l_word');

        $removeIds = [];
        $inserts = [];
        $updates = [];

        foreach ($existing as $lWord => $row) {
            if (! isset($words[$lWord])) {
                $removeIds[] = $row->id;
            }
        }

        foreach ($words as $lWord => $entry) {
            $row = $existing->get($lWord);

            if ($row === null) {
                $inserts[] = [
                    'entity_id' => $entity->id,
                    'l_word' => $lWord,
                    'token' => $entry['token'],
                    'count' => $entry['count'],
                ];
            } elseif ((int) $row->count !== $entry['count'] || $row->token !== $entry['token']) {
                $updates[] = ['id' => $row->id, 'token' => $entry['token'], 'count' => $entry['count']];
            }
        }

        foreach (array_chunk($removeIds, self::CHUNK_SIZE) as $ids) {
            EntityWord::query()->whereIn('id', $ids)->delete();
        }

        foreach (array_chunk($inserts, self::CHUNK_SIZE) as $rows) {
            EntityWord::query()->insert($rows);
        }

        foreach ($updates as $update) {
            // The base builder is required: an Eloquent update would inject
            // updated_at = now() on rows whose audit columns must stay
            // exactly as the link/adoption passes left them — the same trap
            // the per-sentence stamps already avoid.
            EntityWord::query()
                ->where('id', $update['id'])
                ->toBase()
                ->update(['token' => $update['token'], 'count' => $update['count']]);
        }
    }

    /**
     * Aggregate the per-sentence occurrence rows into per-key word entries:
     * count = number of occurrence rows, representative token = the first
     * occurrence's surface in document order.
     *
     * @return array<string, array{token: string, count: int}>
     */
    private function aggregateOccurrences(Entity $entity): array
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

        return $words;
    }
}
