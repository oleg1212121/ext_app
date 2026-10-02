<?php

namespace App\Classes\Enrichment;

use App\Models\Entity;
use App\Models\Form;
use App\Models\Word;

/**
 * English phrasal verbs: python matches 2/3-token windows against the
 * dictionary's multi-word verb headwords ("give up", "kick the bucket") —
 * inert rows the single-token linker can never reach (ADR 0052). The word
 * classes and lemmas the matcher needs ride the shared base resolution;
 * this enricher additionally contributes verb_lemmas — every verb-class
 * headword the dictionary has for the surface — because a phrasal lead can
 * be buried under another class's page: the stained-glass noun "came"
 * outranks the verb in CLASS_PRIORITY, yet "came forward" is a phrasal
 * verb (ADR 0058).
 */
class EnglishPhrasalVerbEnricher implements Enricher
{
    /** Phrasal-verb lexicon rows passed per request (python schema cap). */
    private const LEXICON_LIMIT = 50_000;

    /** Verb-lemma candidates sent per token. */
    private const LEMMA_LIMIT = 8;

    public function key(): string
    {
        return 'en_phrasal';
    }

    public function languages(): array
    {
        return ['en'];
    }

    public function column(): string
    {
        return 'phrasal_verbs';
    }

    public function tokenHints(Entity $entity, array $keys, array $resolved): array
    {
        if ($keys === []) {
            return [];
        }

        $hints = [];

        // Direct verb rows for the surface ("came" has a form-of verb page
        // whose headword is "come").
        Word::query()
            ->where('language_id', $entity->language_id)
            ->whereIn('l_word', $keys)
            ->whereHas('wordClass', fn ($q) => $q->where('slug', 'verb'))
            ->get(['l_word', 'word'])
            ->each(function (Word $word) use (&$hints): void {
                $hints[$word->l_word]['verb_lemmas'][] = $word->word;
            });

        // Verb base words reached via the forms table ("came" -> "come").
        Form::query()
            ->whereIn('forms.l_word', $keys)
            ->join('words as base', 'base.id', '=', 'forms.word_id')
            ->join('word_classes', 'word_classes.id', '=', 'base.word_class_id')
            ->where('base.language_id', $entity->language_id)
            ->where('word_classes.slug', 'verb')
            ->get(['forms.l_word', 'base.word'])
            ->each(function (object $row) use (&$hints): void {
                $hints[$row->l_word]['verb_lemmas'][] = $row->word;
            });

        foreach ($hints as $key => $fields) {
            $hints[$key]['verb_lemmas'] = array_values(array_unique(
                array_slice($fields['verb_lemmas'], 0, self::LEMMA_LIMIT),
            ));
        }

        return $hints;
    }

    public function requestExtras(Entity $entity): array
    {
        return ['phrasal_lexicon' => $this->lexicon($entity)];
    }

    public function toStorage(mixed $output): mixed
    {
        return is_array($output) ? json_encode($output, JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * Multi-word verb headwords for phrasal-verb matching.
     *
     * @return list<string>
     */
    private function lexicon(Entity $entity): array
    {
        return Word::query()
            ->where('language_id', $entity->language_id)
            ->where('l_word', 'like', '% %')
            ->whereHas('wordClass', fn ($q) => $q->where('slug', 'verb'))
            ->orderBy('id')
            ->limit(self::LEXICON_LIMIT)
            ->pluck('l_word')
            ->values()
            ->all();
    }
}
