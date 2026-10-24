<?php

namespace App\Classes\Wiktionary;

use App\Support\WordLookupKey;

/**
 * The relay→base map behind `wiktionary:demote-form-of`, built one
 * classified dump entry at a time and finalized once the stream ends.
 *
 * The dump says which entries are relays, but the raw verdicts alone are
 * not demotable — three entry-level unions must resolve first, mirroring
 * the importer's merge semantics:
 *
 * - A surface with ANY real (non-relay) dump entry stays a word, whatever
 *   the line order — a relay line merged with a real line is a mixed entry.
 * - Relay lines of one surface that disagree on the base stay a word —
 *   there is no single base to demote onto.
 * - Chains close onto the terminal base (X→Y→Z demotes both X and Y onto
 *   Z): without closure X would re-point at Y's id and Y's row would be
 *   deleted underneath it in the same run. Cycles (X→Y→X) demote nobody —
 *   every participant stays a word, and entries pointing INTO a cycle keep
 *   pointing at it (it survives).
 *
 * The map is keyed on `WordLookupKey::normalize` on both sides: stress
 * marks and case cannot divide a dump entry from its database row, so the
 * command resolves ids by `(language_id, l_word)` alone.
 */
final class RelayDemotionMap
{
    /** @var array<string, list<string>> relay l_word => every base l_word its relay lines named */
    private array $bases = [];

    /** @var array<string, true> l_words with at least one real (non-relay) dump entry */
    private array $real = [];

    private int $alsoReal = 0;

    private int $conflicts = 0;

    private int $cycles = 0;

    private int $chained = 0;

    /** @var array<string, string>|null relay l_word => terminal base l_word */
    private ?array $resolved = null;

    /**
     * One classified dump entry: the verbatim `word`, the shared
     * `FormOfClassifier` verdict, and the verbatim base (when relay).
     *
     * @param  array{word: string, relay: bool, base: ?string}  $entry
     */
    public function add(array $entry): self
    {
        if ($this->resolved !== null) {
            throw new \LogicException('Cannot add entries after finalize().');
        }

        $key = WordLookupKey::normalize($entry['word']);

        $base = $entry['base'];
        if (! $entry['relay'] || ! is_string($base) || trim($base) === '') {
            $this->real[$key] = true;

            return $this;
        }

        $baseKey = WordLookupKey::normalize($base);
        if ($baseKey === $key) {
            // Defensive duplicate of the classifier gate: a relay with no
            // base to demote onto stays a word.
            $this->real[$key] = true;

            return $this;
        }

        $this->bases[$key][] = $baseKey;

        return $this;
    }

    /**
     * Resolve the unions and close the chains. Must precede map().
     */
    public function finalize(): self
    {
        $map = [];
        foreach ($this->bases as $key => $baseKeys) {
            if (isset($this->real[$key])) {
                $this->alsoReal++;

                continue;
            }

            $distinct = array_values(array_unique($baseKeys));
            if (count($distinct) > 1) {
                $this->conflicts++;

                continue;
            }

            $map[$key] = $distinct[0];
        }

        // Chain closure, iterative with a memo: every map key walks its base
        // chain to the first key outside the map (the terminal base) or to a
        // memoized resolution. A walk revisiting its own path is a cycle —
        // the cycle's members leave the map and resolve to themselves (they
        // stay words, so outsiders may keep pointing at them).
        $terminal = [];
        foreach (array_keys($map) as $start) {
            if (array_key_exists($start, $terminal)) {
                continue;
            }

            $path = [];
            $seen = [];
            $cur = $start;
            $end = null;

            while (true) {
                if (array_key_exists($cur, $terminal)) {
                    $end = $terminal[$cur];

                    break;
                }

                if (! isset($map[$cur])) {
                    $end = $cur;

                    break;
                }

                if (isset($seen[$cur])) {
                    $cycleStart = $seen[$cur];
                    for ($i = $cycleStart, $n = count($path); $i < $n; $i++) {
                        unset($map[$path[$i]]);
                        $terminal[$path[$i]] = $path[$i];
                    }
                    $this->cycles++;
                    $end = null;

                    break;
                }

                $seen[$cur] = count($path);
                $path[] = $cur;
                $cur = $map[$cur];
            }

            if ($end !== null) {
                $n = count($path);
                foreach ($path as $i => $key) {
                    $terminal[$key] = $end;
                    if ($n - $i > 1) {
                        $this->chained++;
                    }
                }
            }
        }

        // Cycle members resolved to themselves: no demotion for them.
        $this->resolved = array_filter(
            $terminal,
            fn (string $end, string $key): bool => $end !== $key,
            ARRAY_FILTER_USE_BOTH,
        );

        return $this;
    }

    /**
     * The demotion map: relay l_word => terminal base l_word, chains closed.
     *
     * @return array<string, string>
     */
    public function map(): array
    {
        if ($this->resolved === null) {
            throw new \LogicException('Call finalize() before map().');
        }

        return $this->resolved;
    }

    /** Relay surfaces that also carried a real dump entry — kept words. */
    public function alsoReal(): int
    {
        return $this->alsoReal;
    }

    /** Relay surfaces whose relay lines disagreed on the base — kept words. */
    public function conflicts(): int
    {
        return $this->conflicts;
    }

    /** Relay cycles dropped — every participant stays a word. */
    public function cycles(): int
    {
        return $this->cycles;
    }

    /** Relay entries demoting through another relay (chains longer than one hop). */
    public function chained(): int
    {
        return $this->chained;
    }
}
