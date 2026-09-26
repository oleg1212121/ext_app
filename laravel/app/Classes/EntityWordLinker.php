<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityWord;
use App\Models\Form;
use App\Models\Word;
use App\Models\WordClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
     * Unlinked word rows examined per link() run. Rows beyond the budget stay
     * unlinked and are picked up by the next crossword:refresh sweep — a run
     * never walks an arbitrarily large word list in one go.
     */
    private const MAX_ROWS_PER_RUN = 20_000;

    /**
     * Cap on inflected-form candidates considered per token (windowed per
     * l_word). A common surface form can map to thousands of dictionary
     * lemmas; beyond the cap they are noise for best-class selection.
     */
    private const FORM_CANDIDATE_LIMIT = 200;

    /**
     * @param  int|null  $maxRowsPerRun  override of MAX_ROWS_PER_RUN for tests
     */
    public function __construct(private readonly ?int $maxRowsPerRun = null) {}

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
     * Tokens with no candidate at all are stamped unmatchable so later runs
     * skip them; a dictionary import clears the stamps (clearUnmatchedForLanguage).
     *
     * Idempotent: only touches word_id IS NULL, unstamped rows, so re-running
     * after a dictionary import links the previously unmatched tokens.
     *
     * @return array{linked: int, unmatched: int, budget_exhausted: bool}
     */
    public function link(Entity $entity): array
    {
        $classPriority = $this->classPriorityFor($entity->language_id);

        $linked = 0;
        $unmatched = 0;
        $updates = [];

        // Budget window: the first MAX_ROWS_PER_RUN unlinked, unstamped rows
        // in id order. Everything beyond it waits for the next run.
        $maxRows = $this->maxRowsPerRun ?? self::MAX_ROWS_PER_RUN;

        $windowIds = EntityWord::query()
            ->where('entity_id', $entity->id)
            ->whereNull('word_id')
            ->whereNull('unmatchable_at')
            ->orderBy('id')
            ->limit($maxRows)
            ->pluck('id');

        $budgetExhausted = $windowIds->count() >= $maxRows;

        foreach (array_chunk($windowIds->all(), self::BATCH_SIZE) as $chunkIds) {
            $words = EntityWord::query()
                ->whereIn('id', $chunkIds)
                ->select(['id', 'l_word'])
                ->get();

            $lWords = $words->pluck('l_word')->unique()->values()->all();
            $exactByWord = $this->exactCandidates($entity, $lWords);
            $formByWord = $this->formCandidates($entity->language_id, $lWords);

            $unmatchedIds = [];

            foreach ($words as $entityWord) {
                $wordId = $this->bestCandidateId($exactByWord[$entityWord->l_word] ?? null, $classPriority)
                    ?? $this->bestCandidateId($formByWord[$entityWord->l_word] ?? null, $classPriority);

                if ($wordId === null) {
                    $unmatchedIds[] = $entityWord->id;
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

            if ($unmatchedIds !== []) {
                EntityWord::query()
                    ->whereIn('id', $unmatchedIds)
                    ->update(['unmatchable_at' => now()]);
            }
        }

        if ($updates !== []) {
            $this->flush($updates);
        }

        return ['linked' => $linked, 'unmatched' => $unmatched, 'budget_exhausted' => $budgetExhausted];
    }

    /**
     * Exact (language, l_word) dictionary candidates for a batch of tokens,
     * keyed by l_word. Homonyms of one exact form are few, so no cap.
     *
     * @param  list<string>  $lWords
     * @return Collection<string, Collection<int, Word>>
     */
    private function exactCandidates(Entity $entity, array $lWords)
    {
        return Word::query()
            ->selectRaw('id as word_id, l_word, word_class_id')
            ->where('language_id', $entity->language_id)
            ->whereIn('l_word', $lWords)
            ->get()
            ->groupBy('l_word');
    }

    /**
     * Inflected-form candidates for a batch of tokens, keyed by l_word,
     * capped at FORM_CANDIDATE_LIMIT words per token via a per-l_word window.
     *
     * @param  list<string>  $lWords
     * @return Collection<string, Collection<int, object>>
     */
    private function formCandidates(int $languageId, array $lWords)
    {
        $ranked = Form::query()
            ->selectRaw('forms.l_word, words.id as word_id, words.word_class_id, row_number() over (partition by forms.l_word order by words.id) as candidate_rank')
            ->join('words', 'words.id', '=', 'forms.word_id')
            ->where('words.language_id', $languageId)
            ->whereIn('forms.l_word', $lWords);

        return DB::table(DB::raw('('.$ranked->toSql().') forms_ranked'))
            ->mergeBindings($ranked->getQuery())
            ->select(['l_word', 'word_id', 'word_class_id'])
            ->where('candidate_rank', '<=', self::FORM_CANDIDATE_LIMIT)
            ->get()
            ->groupBy('l_word');
    }

    private function bestCandidateId($candidates, array $classPriority): ?int
    {
        return $candidates?->sortBy(
            fn (object $candidate): int => $classPriority[$candidate->word_class_id] ?? PHP_INT_MAX,
        )->first()?->word_id;
    }

    /**
     * Re-attempt the tokens a previous run stamped unmatchable — called after
     * a dictionary import so newly imported words can link them.
     */
    public static function clearUnmatchedForLanguage(int $languageId): int
    {
        return EntityWord::query()
            ->whereNotNull('unmatchable_at')
            ->whereHas('entity', fn (Builder $query) => $query->where('language_id', $languageId))
            ->update(['unmatchable_at' => null]);
    }

    /**
     * Clear the unmatchable stamps of the given entities' word rows.
     *
     * @param  list<int>  $entityIds
     */
    public static function clearUnmatchedForEntities(array $entityIds): int
    {
        if ($entityIds === []) {
            return 0;
        }

        return EntityWord::query()
            ->whereIn('entity_id', $entityIds)
            ->whereNotNull('unmatchable_at')
            ->update(['unmatchable_at' => null]);
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
