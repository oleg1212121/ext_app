<?php

namespace App\Classes;

/**
 * The shape a multi-word verb headword must have to serve multi-word-verb
 * matching (ADR 0059): two to four tokens where every token after the verb
 * is a closed-class particle or preposition.
 *
 * Dictionary imports arrive verbatim from Wiktionary and carry junk under
 * the verb class — "do it", "could have", "be there" — and idioms ("kick
 * the bucket") whose middle tokens no particle/preposition structure can
 * validate: the spaCy matcher only ever confirms particle/preposition
 * children, so headwords outside this shape must never reach the lexicon
 * (or the words table's verb class, which words:reclass-multiword repairs).
 */
class MultiwordVerbShape
{
    /** Adverbial particles ("up" in "give up"). */
    private const PARTICLES = [
        'about', 'aboard', 'above', 'across', 'ahead', 'along', 'apart',
        'around', 'aside', 'away', 'back', 'behind', 'below', 'between',
        'beyond', 'by', 'down', 'forth', 'forward', 'in', 'off', 'on',
        'out', 'over', 'past', 'round', 'through', 'together', 'under', 'up',
    ];

    /** Prepositions prepositional and phrasal-prepositional verbs end in. */
    private const PREPS = [
        'about', 'after', 'against', 'among', 'as', 'at', 'before', 'behind',
        'between', 'beyond', 'by', 'for', 'from', 'in', 'into', 'of', 'off',
        'on', 'onto', 'out', 'over', 'through', 'to', 'toward', 'towards',
        'under', 'upon', 'with', 'without',
    ];

    private static ?array $trailing = null;

    public static function isValid(string $lWord): bool
    {
        $tokens = explode(' ', trim($lWord));

        if (count($tokens) < 2 || count($tokens) > 4) {
            return false;
        }

        foreach (array_slice($tokens, 1) as $token) {
            if (! in_array($token, self::trailing(), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function trailing(): array
    {
        return self::$trailing ??= array_values(array_unique([
            ...self::PARTICLES,
            ...self::PREPS,
        ]));
    }
}
