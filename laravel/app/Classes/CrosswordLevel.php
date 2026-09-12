<?php

namespace App\Classes;

use InvalidArgumentException;

/**
 * Global frequency-rank bands selectable as the crossword "level of words".
 * A rank is the position in the language's frequency list (lower = more
 * common); a word is eligible for a level when rank <= cutoff.
 */
class CrosswordLevel
{
    /**
     * Rank cutoffs per level, mirroring the legacy less_* bands.
     */
    private const BANDS = [100, 500, 1000, 3000, 5000, 10000, 20000, 1000000];

    public static function count(): int
    {
        return count(self::BANDS);
    }

    public static function cutoff(int $level): int
    {
        if (! isset(self::BANDS[$level])) {
            throw new InvalidArgumentException("Unknown crossword level [{$level}].");
        }

        return self::BANDS[$level];
    }

 /**
  * @return array<int, int>
  */
    public static function levels(): array
    {
        return array_keys(self::BANDS);
    }
}
