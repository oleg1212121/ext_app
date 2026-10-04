<?php

namespace App\Enums;

/**
 * Where a sentence lands in its entity's document order. The one explicit
 * type at the ordering pipeline's interface — the wire conventions
 * (after_sentence_id 0/null on the entities frontend, the Filament sentinel
 * string) translate to it at the edge, in the controllers.
 *
 * A plain enum cannot carry the After case's sentence id (no case payloads
 * in PHP), so this is a sealed value object: the private constructor makes
 * the named constructors the only way in.
 */
final class SentenceAnchor
{
    private function __construct(
        public readonly ?int $sentenceId,
        public readonly bool $end,
    ) {}

    /** Before every sentence of the document. */
    public static function beginning(): self
    {
        return new self(null, false);
    }

    /** After the document's last sentence. */
    public static function end(): self
    {
        return new self(null, true);
    }

    /** Directly after the given sentence of the same entity. */
    public static function after(int $sentenceId): self
    {
        return new self($sentenceId, false);
    }
}
