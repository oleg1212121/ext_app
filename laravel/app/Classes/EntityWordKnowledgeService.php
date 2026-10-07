<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityWord;
use App\Models\User;
use App\Models\UserEntityWordKnowledge;

/**
 * Word knowledge (ADR 0074): the occurrence-weighted share of an entity's
 * dictionary-linked word occurrences the viewer knows, 0-100.
 *
 * Each occurrence contributes min(familiarity, 60)/60 — the 0-60 familiarity
 * range maps linearly onto 0-100% knowledge and everything above 60 counts as
 * fully known (the frontend's "strong" band). A word without a user_word row
 * contributes 0. Only linked tokens count; the score is null when the entity
 * has no linked words at all.
 */
class EntityWordKnowledgeService
{
    /**
     * Familiarity at which a word counts as fully known — deliberately the
     * frontend's FAMILIARITY_STRONG_AT (resources/js/lib/wordFamiliarity.js);
     * placement-baseline words (50) sit just below it.
     */
    public const KNOWN_CAP = 60;

    /**
     * How old a stored score may get before the sweep recomputes it —
     * familiarity drifts through reads, lookups and crosswords.
     */
    public const REFRESH_AFTER_DAYS = 3;

    /**
     * The knowledge percentage for one user over one entity's linked words,
     * or null when the entity has no dictionary-linked words.
     */
    public function score(Entity $entity, int $userId): ?float
    {
        $row = EntityWord::query()
            ->where('entity_words.entity_id', $entity->id)
            ->whereNotNull('entity_words.word_id')
            ->leftJoin('user_word', function ($join) use ($userId): void {
                $join->on('user_word.word_id', '=', 'entity_words.word_id')
                    ->where('user_word.user_id', $userId);
            })
            ->selectRaw('SUM(entity_words.count * LEAST(COALESCE(user_word.familiarity, 0), ?)) AS earned, SUM(entity_words.count) AS total', [self::KNOWN_CAP])
            ->first();

        $total = (float) $row->total;

        if ($total === 0.0) {
            return null;
        }

        return round((float) $row->earned / (self::KNOWN_CAP * $total) * 100, 2);
    }

    /**
     * The stored snapshot for this viewer, computing it on the spot when it
     * does not exist yet or has gone stale. Null means the word list is not
     * readable right now (never built or sentences changed after the last
     * build) — the page shows a building state instead of a number.
     */
    public function ensure(Entity $entity, User $user): ?UserEntityWordKnowledge
    {
        if (app(EntityWordIndexer::class)->isStale($entity)) {
            return null;
        }

        $pair = UserEntityWordKnowledge::query()
            ->where('user_id', $user->id)
            ->where('entity_id', $entity->id)
            ->first();

        if ($pair !== null && $this->isFresh($pair, $entity)) {
            return $pair;
        }

        return $this->store($entity, $user);
    }

    /**
     * Recompute stored scores that drifted out of date: older than the age
     * cap, or computed before the entity's word list was last rebuilt.
     * Entities whose word list is mid-rebuild are skipped — the score would
     * be invalidated by the next index build anyway. Bounded so a scheduled
     * run chews through the backlog in slices.
     *
     * @return int Number of pairs recomputed.
     */
    public function refreshStale(int $limit): int
    {
        $cutoff = now()->subDays(self::REFRESH_AFTER_DAYS);

        $pairIds = UserEntityWordKnowledge::query()
            ->join('entities as e', 'e.id', '=', 'user_entity_word_knowledge.entity_id')
            ->whereNotNull('e.words_indexed_at')
            ->whereNotExists(function ($q): void {
                $q->selectRaw(1)
                    ->from('entity_sentences as es')
                    ->whereColumn('es.entity_id', 'e.id')
                    ->whereColumn('es.updated_at', '>', 'e.words_indexed_at');
            })
            ->where(function ($q) use ($cutoff): void {
                $q->whereColumn('user_entity_word_knowledge.computed_at', '<', 'e.words_indexed_at')
                    ->orWhere('user_entity_word_knowledge.computed_at', '<', $cutoff);
            })
            ->orderBy('user_entity_word_knowledge.id')
            ->limit($limit)
            ->pluck('user_entity_word_knowledge.id');

        $refreshed = 0;

        foreach ($pairIds as $pairId) {
            $pair = UserEntityWordKnowledge::query()->find($pairId);

            if ($pair === null) {
                continue;
            }

            $entity = $pair->entity;
            $user = $pair->user;

            if ($entity === null || $user === null) {
                $pair->delete();

                continue;
            }

            $this->store($entity, $user);
            $refreshed++;
        }

        return $refreshed;
    }

    private function store(Entity $entity, User $user): UserEntityWordKnowledge
    {
        return UserEntityWordKnowledge::query()->updateOrCreate([
            'user_id' => $user->id,
            'entity_id' => $entity->id,
        ], [
            'score' => $this->score($entity, $user->id),
            'computed_at' => now(),
        ]);
    }

    private function isFresh(UserEntityWordKnowledge $pair, Entity $entity): bool
    {
        if ($entity->words_indexed_at !== null && $pair->computed_at->lt($entity->words_indexed_at)) {
            return false;
        }

        return $pair->computed_at->gte(now()->subDays(self::REFRESH_AFTER_DAYS));
    }
}
