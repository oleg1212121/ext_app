<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityWord;

/**
 * Compact per-entity word map for interactive text rendering:
 * l_word => {w: dictionary word id, s: the reader's familiarity (0-100) or
 * null when untouched}.
 *
 * Only dictionary-linked tokens appear in the map — everything else renders
 * as plain text. Positions are deliberately NOT part of the map: segmentation
 * is derived at render time (ADR 0027).
 */
class EntityWordMap
{
    /**
     * @return array<string, array{w: int, s: int|null}>
     */
    public function forEntity(Entity $entity, int $userId): array
    {
        return EntityWord::query()
            ->where('entity_words.entity_id', $entity->id)
            ->whereNotNull('entity_words.word_id')
            ->leftJoin('user_word', function ($join) use ($userId): void {
                $join->on('user_word.word_id', '=', 'entity_words.word_id')
                    ->where('user_word.user_id', $userId);
            })
            ->get(['entity_words.l_word', 'entity_words.word_id', 'user_word.familiarity'])
            ->mapWithKeys(fn ($row): array => [$row->l_word => [
                'w' => (int) $row->word_id,
                's' => $row->familiarity === null ? null : (int) $row->familiarity,
            ]])
            ->all();
    }
}
