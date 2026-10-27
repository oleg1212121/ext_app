# ADR 0090: The word test samples CEFR tag groups and persists a vocabulary level

Date: 2026-10-26
Status: Accepted

## Context

ADR [0071](0071-word-test-presumed-known-baseline.md) built the word test on
the only grouping the corpus offered at the time: 20 equal-count quantile
buckets of the language's ranked inventory, each credited 1000 × its known
share. Since then the words tag pool gained six curated **CEFR level tags**
(`a1`…`c2`, ~7 200 English headwords embedded in `CefrWordTagSeeder`,
ADR [0076](0076-per-type-tag-pools.md)), and the bucket design showed two
weaknesses the tags let us fix:

1. **The buckets are an inventory artifact, not a curriculum.** Equal-count
   slices of whatever the ranked inventory holds move on every corpus
   re-import, and they say nothing about what a learner at a given level
   should know. A score of 5 000 names a different slice of vocabulary
   whenever the inventory shifts — and any derived grouping inherits this:
   anchors recomputed from live data silently re-mean every number that was
   ever shown or stored against them.
2. **The placement left no record.** ADR 0071 deliberately persisted only
   the changed `user_word` rows, so retakes re-tested from zero (a strong
   learner re-ticked dozens of basic words), and the "you haven't taken the
   test" nudges had to guess from `user_word` row existence — a signal a
   crossword-only user trips without ever being placed.

Constraints carried over from ADR 0071 and the 2026-10-26 spec: the format
stays a shuffled checklist of bare headwords, ticked and submitted once —
no quiz, no group labels; samples remain server-held under a uuid token so
a submit is scored against what was served, never the client; and the
presumed-known baseline marking stays raise-only.

## Decisions

### 1. CEFR tag groups are the sampling frame; the anchors are fixed constants

The sample draws from the language's CEFR tag groups (`a1`…`c2`) instead of
ranked-inventory buckets. Each group owns a fixed span of the 0–20 000
scale, ended by a **band anchor** hard-coded in `WordTestService`:
`a1→1000, a2→3000, b1→6000, b2→10000, c1→15000, c2→20000` (band widths
1000/2000/3000/4000/5000/5000, summing exactly to 20 000). The score is
`base + Σ band(g) × known(g)/drawn(g)`, clamped to 20 000.

Alternatives rejected: **data-derived anchors** — equal-count or rank-width
slices recomputed from the live corpus drift with every seeder or import
update and silently re-mean stored levels (a stored 10 000 would name a
different vocabulary after the next import; persistence requires anchors
that never move) — and keeping ADR 0071's quantile buckets, which share the
drift and carry no curriculum meaning. A level beyond c2's anchor (a perfect
test) saturates the scale; nothing scores above 20 000.

### 2. Retakes adapt to the stored level

A first test (stored level 0) tests all six groups, 6 × 10 = 60 words. A
retake tests only the bands whose anchor is at or above the stored level
(stored 10 000 → b2/c1/c2), redistributes the 60 words evenly over the
tested bands (6→10, 5→12, 4→15, 3→20, 2→30, 1→60 per band), and lets the
untested lower bands contribute their floor anchor as the **base**
(stored 10 000 → base 6 000). Sampling mechanics: one query per band
joining `tagged_words` + `tags`, `DISTINCT ON (l_word)` keeping each
spelling's most common word-class row; lower bands claim spellings first so
a spelling tagged in two levels draws at most once; tag membership defines
the pool with **no frequency filter** (unranked c2 words are legitimate);
any tested band with zero tagged headwords makes the test unavailable
(`null` — the existing empty state). Fresh-sample-per-load and the
server-held token cache (24 h) are ADR 0071 decision 3 unchanged; the page
receives the tested slugs (`groups`) for its adaptive-retake note, but
never the band layout — the list stays a flat shuffle.

### 3. The level persists raise-only in `users.vocabulary_level`

A submit persists `max(old, new)` into `users.vocabulary_level`
(`unsignedSmallInteger`, default 0 — safely holds 0–20 000): a bad retake
never lowers the stored level, the same raise-only rule as the baseline
marking. The column is the placement record ADR 0071's score lacked: the
result panel's primary number, the header's current-level chip, the
adaptive sampling input, and the "hasn't taken the test" nudges
(`vocabulary_level === 0` — a crossword-only user with plenty of
`user_word` rows has still never been placed) all read it.

Alternatives rejected: **derived-only counts** (recomputing the level from
`user_word` rows — the rows move with organic familiarity, so the level
would drift after placement and the nudge would fire for active readers)
and **latest-wins overwrite** (a weak retake would lower the level,
contradicting placement-corrects-upward). Per-user-per-language storage is
deferred: only English carries CEFR tags today, so the column is
deliberately language-agnostic; if another language gains CEFR lists, this
needs revisiting.

### 4. English only; no fallback sampler

Languages whose words carry no CEFR tags — or where any tested band has
zero tagged headwords — serve the existing empty state. The 20-bucket
quantile sampler is deleted; there is one sampling path.

Rejected: a frequency-quantile fallback for untagged languages — two
sampling paths with different semantics feeding one score scale means the
score silently changes meaning by language, and the empty state already
tells the learner exactly what is missing.

## Consequences

- ADR 0071's decisions 2 (presumed-known baseline, raise-only) and 3
  (fresh server-held samples) remain in force; its decision 1 (inventory
  quantile buckets) is superseded by this ADR.
- **Sampling is tag-defined, marking is rank-defined.** `mark()` is
  untouched (`frequency <= level` → familiarity 60), so a checked unranked
  word lifts its band's credit but is never marked (the 1 100 000 sentinel
  never matches `≤ 20 000`) — the opposite of ADR 0071, where unranked
  words could be neither sampled nor marked.
- The anchors are code, not data: changing them is an API-breaking decision
  for stored levels, whose meaning is frozen at write time. This rigidity
  is the point.
- The stored level is language-agnostic because only English is tagged;
  bilingual users share one number across languages until per-language
  storage is justified.
- The known empty state now covers two cases — no CEFR tags for the
  language, and an exhausted scale (a stored level above c2's anchor leaves
  nothing to test).
