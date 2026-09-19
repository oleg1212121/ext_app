<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityWord;
use App\Models\Form;
use App\Models\Word;
use App\Models\WordClass;
use Illuminate\Database\Eloquent\Builder;
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
     * Part-of-speech preference order of a class slug (lower = preferred).
     * Shared by entity linking and the word popup's entry ordering.
     */
    public static function classPriority(string $slug): int
    {
        $priority = array_search($slug, self::CLASS_PRIORITY, true);

        return $priority === false ? PHP_INT_MAX : $priority;
    }

    /**
     * Fill word_id on the entity's unlinked words by (language, lowercase form),
     * falling back to inflected forms (forms.l_word). Exact dictionary matches
     * always win; the forms pass only sees the tokens the exact pass missed.
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
                    $wordId = $this->exactWordId($entity, $entityWord->l_word, $classPriority)
                        ?? $this->formWordId($entity, $entityWord->l_word, $classPriority);

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

    private function exactWordId(Entity $entity, string $lWord, array $classPriority): ?int
    {
        return $this->bestWordId(
            Word::query()
                ->where('language_id', $entity->language_id)
                ->where('l_word', $lWord),
            $classPriority,
        );
    }

    private function formWordId(Entity $entity, string $lWord, array $classPriority): ?int
    {
        return $this->bestWordId(
            Word::query()
                ->where('words.language_id', $entity->language_id)
                ->whereIn('words.id', Form::query()->where('l_word', $lWord)->select('word_id')),
            $classPriority,
        );
    }

    private function bestWordId(Builder $query, array $classPriority): ?int
    {
        return $query->get(['id', 'word_class_id'])
            ->sortBy(fn (Word $word): int => $classPriority[$word->word_class_id] ?? PHP_INT_MAX)
            ->first()
            ?->id;
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
