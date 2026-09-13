<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityWord;
use App\Models\Word;
use App\Models\WordClass;
use Illuminate\Support\Facades\DB;

class EntityWordLinker
{
    private const CLASS_PRIORITY = [
        'noun', 'verb', 'adjective', 'adverb', 'pronoun', 'preposition',
        'conjunction', 'interjection', 'numeral', 'particle', 'article',
        'proper noun', 'phrase', 'prefix', 'suffix', 'character', 'unknown',
    ];

    private const BATCH_SIZE = 500;

    /**
     * Fill word_id on the entity's unlinked words by (language, lowercase form).
     * Idempotent: only touches word_id IS NULL rows, so re-running after a
     * dictionary import links the previously unmatched tokens.
     *
     * @return array{linked: int, unmatched: int}
     */
    public function link(Entity $entity): array
    {
        $classPriority = $this->classPriorityFor($entity->language_id);

        $linked = 0;
        $unmatched = 0;
        $updates = [];

        EntityWord::query()
            ->where('entity_id', $entity->id)
            ->whereNull('word_id')
            ->select(['id', 'l_word'])
            ->chunkById(self::BATCH_SIZE, function ($words) use ($entity, $classPriority, &$linked, &$unmatched, &$updates): void {
                foreach ($words as $entityWord) {
                    $wordId = Word::query()
                        ->where('language_id', $entity->language_id)
                        ->where('l_word', $entityWord->l_word)
                        ->get(['id', 'word_class_id'])
                        ->sortBy(fn (Word $word): int => $classPriority[$word->word_class_id] ?? PHP_INT_MAX)
                        ->first()
                        ?->id;

                    if ($wordId === null) {
                        $unmatched++;

                        continue;
                    }

                    $updates[] = ['id' => $entityWord->id, 'entity_id' => $entity->id, 'word_id' => $wordId];
                    $linked++;

                    if (count($updates) >= self::BATCH_SIZE) {
                        $this->flush($updates);
                        $updates = [];
                    }
                }
            });

        if ($updates !== []) {
            $this->flush($updates);
        }

        return ['linked' => $linked, 'unmatched' => $unmatched];
    }

    /**
     * @return array<int, int> word class id => priority (lower = preferred)
     */
    private function classPriorityFor(int $languageId): array
    {
        $priorityBySlug = array_flip(self::CLASS_PRIORITY);

        return WordClass::query()
            ->where('language_id', $languageId)
            ->get(['id', 'slug'])
            ->mapWithKeys(fn ($class) => [$class->id => $priorityBySlug[$class->slug] ?? PHP_INT_MAX])
            ->all();
    }

    private function flush(array $updates): void
    {
        $ids = implode(', ', array_map(fn ($u) => (int) $u['id'], $updates));
        $cases = implode(' ', array_map(
            fn ($u) => sprintf('when %d then %d', (int) $u['id'], (int) $u['word_id']),
            $updates,
        ));

        DB::statement("update entity_words set word_id = case id {$cases} end where id in ({$ids})");
    }
}
