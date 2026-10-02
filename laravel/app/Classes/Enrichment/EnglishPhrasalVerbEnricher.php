<?php

namespace App\Classes\Enrichment;

use App\Classes\MultiwordVerbShape;
use App\Models\Entity;
use App\Models\Form;
use App\Models\Word;

/**
 * English multi-word verbs: spaCy dependency parsing finds verb + particle/
 * preposition structures, and the caller supplies the curated dictionary
 * lexicon that gates prepositional matches ("depend on") while particles
 * alone ("give up", "looked it up") are parser evidence (ADR 0059). The
 * verb-lemma hints this enricher contributes ride the shared base
 * resolution: every verb-class headword the dictionary has for a surface —
 * a phrasal lead can be buried under another class's page (ADR 0058).
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

    /** v2: spaCy dependency parsing replaced n-gram matching; v3: directional-adverb guard (ADR 0059). */
    public function version(): int
    {
        return 3;
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
     * Multi-word verb headwords for the lexicon-gated matches: only rows in
     * the particle/preposition shape the parser can confirm (ADR 0059).
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
            ->filter(fn (string $lWord): bool => MultiwordVerbShape::isValid($lWord))
            ->values()
            ->all();
    }
}
