# ADR 0071: Word test writes a presumed-known familiarity baseline

Date: 2026-10-06
Status: Accepted

## Context

Word familiarity (ADR [0028](0028-numeric-word-familiarity.md)) accrues only
through reading, lookups and crosswords — a learner with real vocabulary
starts from zero rows, so tinting and crossword selection treat their known
words as untouched. The Word test fixes this with a self-assessment: it
samples 50 headwords across the language's ranked inventory, the learner
ticks the words they know, and the answers produce a score on the
0–20 000 frequency-rank scale that marks the presumed-known range.

Three constraints shaped the design:

1. **The rank data is coarse.** The requested scoring was "20 groups of
   1000 rank width (0–1000 … 19000–20000), each fully-known group worth
   1000". But on any machine where the corpus import
   (`words:import-frequency`) has not run, ranks under 20 000 are the band
   lists' coarse ties (ADR [0070](0070-frequency-lists-min-merge.md)):
   every word of `20000.txt` sits at exactly rank 20000, and Russian has no
   ranked words at all. Literal rank-width groups would leave 12 of the 20
   empty, and the score would cap at ~8000 even for a perfect test.
2. **`words` holds one row per word class**, and `user_word` mirrors that
   grain; "a word" can mean a headword spelling or one of its rows.
3. **Organic familiarity** (reads +1, lookups −2, crosswords +5, ADR 0028)
   accumulates on the same rows the test writes.

## Decisions

### 1. Score by inventory quantiles, credit per bucket

The language's ranked headwords (distinct on `l_word`, keeping the most
common word-class row, frequency ≤ 20 000) are cut into 20 **equal-count**
buckets; the sample draws 50 headwords proportionally (2–3 per bucket).
Scoring applies the requested rule to these buckets: each contributes
1000 × the known share of its drawn words; the sum is the score. With dense
per-word ranks this is indistinguishable from literal 0–1000 … 19000–20000
groups; with today's band ties it still exercises the whole scale; and a
future corpus import heals the grouping without code changes.

Alternatives rejected: skip-empty-groups-and-renormalize (the surviving
groups would be worth 2500 each, so the score stops being a rank frontier
and marking would claim words the user never demonstrated) and
band-anchored weights (crediting distance-to-next-anchor makes a partially
known coarse band overshoot its frontier).

### 2. One baseline value, raise-only, no provenance

A submit writes `familiarity = 50` (`UserWord::PLACEMENT_BASELINE`) for
every tested-language **word row** with frequency ≤ score — one set-based
`INSERT … SELECT … ON CONFLICT DO UPDATE` with
`GREATEST(existing, 50)`:

- **Raise-only**: words already above 50 keep their value, so organic
  progress is never destroyed; a retake with a better score extends the
  baseline upward over a fresh sample. Rows below 50 are lifted to it.
- **One value, not a gradient**: 50 is the amber "seen enough" band — the
  words stay crossword-eligible (only ≥ 100 is excluded) and organic events
  still grow them to 100. Alternatives rejected: 100 (one checkbox test
  would permanently exclude rare-ish words from puzzles on a lucky guess)
  and depth-based gradients (a second invented semantic with no consumer).
- **No provenance tracking**: the test does not record which rows it
  wrote, so a retake cannot *lower* the level after a worse run. This
  irreversibility is accepted: placement corrects upward only, and a wrong
  early test is outgrown by reading rather than re-tested away. The
  alternative (a provenance column plus rebuild-on-retake) needs a schema
  change and tangles with organic deltas layered on top of the baseline.
- **Marking is row-level** ("all words of the language at rank ≤ score" is
  the decided scope): both rank writers move the word-class rows of an
  `l_word` together, so headword and row semantics coincide today, and the
  entity correction's per-row drift re-converges over time.

### 3. Fresh samples, server-held

Every page load draws a new sample and stores the bucket layout in the
cache under a uuid token (24 h TTL); the submit must present the token and
only word ids from that sample. The client never sees ranks or bucket
membership — the page renders a flat shuffled list — so rarity is hidden
from the learner and a submit cannot be replayed against words that were
never served.

## Consequences

- The score is a rank frontier: marking `frequency <= score` never claims
  words rarer than demonstrated, and an individually checked word above the
  score is *not* marked (its bucket's partial credit still lifts the score).
- A language without ranked words (ru today) serves an empty state until
  its frequency lists are imported.
- The score itself is not persisted — the changed `user_word` rows are the
  only record. A "last placement" display or progress-over-time view needs
  a history table (deliberately out of scope).
- With band ties, retakes can repeat headwords from the same coarse bands;
  dense corpus ranks shrink the repeats.
- The unranked marker (1 100 000) is excluded by the `≤ 20 000` comparison;
  unranked words are never sampled or marked.
