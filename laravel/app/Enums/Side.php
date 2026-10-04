<?php

namespace App\Enums;

/**
 * The canonical A-side/B-side of an entity match — positional, not semantic
 * (the lower entity id is the A-side; the original text can sit on either
 * side). The junction `side` column, the payload keys, and the totals
 * columns all spell sides as lowercase strings, so DB and payload writes
 * go through ->value.
 */
enum Side: string
{
    case A = 'a';
    case B = 'b';

    public function other(): self
    {
        return $this === self::A ? self::B : self::A;
    }

    /**
     * The payload key holding this side's junctioned sentences within one
     * row ('a_sentences' / 'b_sentences').
     */
    public function sentencesKey(): string
    {
        return $this->value.'_sentences';
    }

    /**
     * The unmatched-pool payload key for this side
     * ('unmatched_a' / 'unmatched_b').
     */
    public function unmatchedKey(): string
    {
        return 'unmatched_'.$this->value;
    }

    /**
     * The entity_matches totals column for this side
     * ('a_total_sentences' / 'b_total_sentences').
     */
    public function totalColumn(): string
    {
        return $this->value.'_total_sentences';
    }
}
