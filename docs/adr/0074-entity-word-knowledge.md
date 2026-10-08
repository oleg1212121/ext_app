# ADR 0074: Entity pages show a per-user word-knowledge percentage

Date: 2026-10-07
Status: Accepted

## Context

The Library's entity detail page is the place a learner decides whether a
text is worth opening, but nothing on it says how hard the text will be
*for them*. All the raw material exists: the entity's word list
(`entity_words`, ADR [0025](0025-crossword-word-index-and-progress.md)) with
per-token occurrence counts, the dictionary link (`word_id`), and the
viewer's `user_word.familiarity` rows (ADR
[0028](0028-numeric-word-familiarity.md)) fed by reads, lookups, crosswords
and the placement baseline (ADR
[0071](0071-word-test-presumed-known-baseline.md)). No per-user-per-entity
aggregate exists anywhere.

Constraints that shaped the design:

1. **The requested "percentile" is a percentage.** A percentile ranks a
   user against other users; the requirement is one user's share of a
   text's words they know. With the current user base a cross-user rank
   would also be noise.
2. **Familiarity is a 0–100 scale but not linearly "knownness"** from the
   learner's perspective: the placement baseline sits at 50
   (`PLACEMENT_BASELINE`, "presumed known") and the frontend's strong band
   starts at 60 (`FAMILIARITY_STRONG_AT`).
3. **Word identity is per word class** (`user_word` mirrors `words` rows,
   ADR 0071 constraint 2); a headword's noun and verb rows are separate
   progress rows.
4. **Sentence edits rebuild the word list** (`crossword:refresh` sweep,
   ADR 0043-pattern staleness), so any derived score goes stale when
   sentences change, and **familiarity drifts daily**, so it goes stale
   even when they don't.

## Decisions

### 1. A percentage, named Word knowledge

The metric is the occurrence-weighted share of the entity's
dictionary-linked word occurrences the viewer knows, 0–100, stored per
(user, entity) pair. The canonical term is **Word knowledge** — no
"percentile" (not a rank) and no "coverage" (the alignment domain owns
that word). Alternatives rejected: a true percentile (needs every user's
score first; meaningless at this scale) and unique-token weighting (a
word seen once would outweigh "the" seen 500 times — the reading feel of
a text is dominated by its frequent words, and occurrence weighting is
what second-language-acquisition coverage means).

### 2. Capped-linear mapping at 60, no row = 0

Each occurrence contributes `min(familiarity, 60) / 60`: the 0–60 range
maps linearly onto 0–100% knowledge and everything above 60 counts as
fully known. A word without a `user_word` row contributes 0. The cap is
deliberately `FAMILIARITY_STRONG_AT` — the same bar the reader UI already
uses for "strong" tinting — and keeps placement-baseline words (50) just
below it, so a freshly placed learner reads a text as partially known
rather than mastered. One number, one definition; alternatives rejected:
threshold counting (a word is known or not — the 0–60 richness would
collapse) and a smooth average without a cap (familiarity 100 would be
worth 1.67× a "strong" 60 for no learner-visible reason).

The score of an entity with no linked words is null, not 0 — there is
nothing to know or not know yet.

### 3. Sparse snapshot, updated in place, no history

`user_entity_word_knowledge` holds one row per (user, entity) pair,
created the first time that user opens that entity's page and updated in
place afterwards. Pairs nobody opens never exist; there is no history,
because no surface consumes a trend (the word-test made the same call in
ADR 0071). A trend later means adding rows, not redesigning this one.

### 4. Two staleness triggers: rebuild and age

A snapshot is stale when the entity's word list was rebuilt after it was
computed (`computed_at < entities.words_indexed_at` — sentence edits,
re-uploads, clones) or when it is older than three days (familiarity
drift). Two refresh paths share that definition:

- **On view** (`EntityWordKnowledgeService::ensure`): if the word list is
  *currently* stale (`EntityWordIndexer::isStale`) the page gets no score
  at all and shows a "calculating" state; otherwise a missing or stale
  snapshot is recomputed inline (one aggregate SQL).
- **On schedule**: `entities:refresh-word-knowledge` (every 5 min,
  `withoutOverlapping`, `--limit=100` cursor) recomputes stale pairs of
  entities whose index is *not* mid-rebuild — visiting is not required to
  keep a pair current.

Alternatives rejected: recomputing on every familiarity write (a word
event would touch every entity containing that word) and a purely
scheduled refresh (a first-time visitor would wait up to five minutes
for a number).

### 5. Only dictionary-linked tokens count

Unlinked `entity_words` rows (never matched, or stamped unmatchable but
not yet adopted) are excluded from numerator and denominator. The link
pass runs in the same `RefreshEntityWords` job that rebuilds the list,
so the exclusion window is transient — and `ensure` refuses to compute
while the index is stale anyway.

## Consequences

- The stat inherits the word-class grain: a headword's noun and verb
  rows carry separate familiarity (ADR 0071); the score treats them as
  separate words. Accepted — every consumer of `user_word` shares this.
- Adopting an unmatchable token (ADR-worded: `EntityWordAdoption` gives
  it a `words` row) grows the denominator; the score of an untouched
  entity can drop slightly when the linker finishes. The 5-min sweeps
  heal the snapshot within minutes.
- Every stored pair is recomputed roughly every three days whether or
  not the user returns — bounded by the `--limit` cursor, trivially
  cheap (one aggregate query per pair).
- A viewer with no familiarity data at all scores 0% on every entity;
  the page pairs that 0 with a hint linking to the word test rather
  than hiding the number (an honest "you have not told us anything
  yet").
- The 60 cap is duplicated between PHP (`KNOWN_CAP`) and the frontend's
  `FAMILIARITY_STRONG_AT`; they must move together (noted in the service
  docblock).
- Crossword `generate` seeds familiarity-0 marker rows for selected
  words; those rows count as "touched but unknown" (0/60), which is
  exactly their tinting semantics — no special case needed.
