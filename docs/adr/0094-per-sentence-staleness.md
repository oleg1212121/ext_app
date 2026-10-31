# ADR 0094: Per-sentence staleness for the word index and sentence enrichment

Date: 2026-10-31
Status: Accepted

## Context

Two derived surfaces hang off an entity's sentences, and both were stamped
and re-staled at the **entity** level:

- The word list ([ADR 0025](0025-crossword-word-index-and-progress.md),
  refreshed in the background per [ADR
  0026](0026-background-word-list-refresh.md)) carried one
  `entities.words_indexed_at` marker; a refresh re-tokenized the whole book
  in one wholesale in-memory pass and **wiped `entity_words` before
  refilling it** — so any sentence edit threw the entire book's popups into
  "not in the dictionary" mode until the relink pass caught up.
- Sentence enrichment ([ADR 0052](0052-local-sentence-enrichment.md), the
  per-enricher stamps of [ADR 0057](0057-enricher-registry.md) with the
  versioning addenda of [ADR 0059](0059-spacy-multiword-verb-matching.md)
  and the python-parity addendum of [ADR
  0067](0067-annotation-descriptor-and-python-version-parity.md)) kept its
  stamp map on the entity: one edited sentence re-enriched **every**
  sentence of the book — hundreds of chunk jobs and python round trips for
  a one-word fix.

Both pipelines had the wrong staleness unit. Content changes arrive per
sentence, so one sentence's edit is one sentence's work; everything else
was already correct and is not re-litigated here — this ADR only moves the
staleness boundary.

## Decisions

### 1. The sentence is the staleness unit for both pipelines

Each sentence carries its own completion stamps, and each build walks only
the stale sentences in bounded id-cursor windows (the [ADR
0043](0043-bounded-job-runs.md) discipline: per-run budgets, durable
cursors, self-restarting chains):

- **Word index** (`entity_sentences.words_indexed_at`; the
  `entity_sentence_words` occurrence rows are the substrate): a sentence is
  stale when its stamp is null or older than its own `updated_at` — the
  stamp is written with the sentence's `updated_at` copied, not `now()`, so
  equality means fresh and clock skew cannot flip it. `RefreshEntityWords`
  re-tokenizes at most 500 stale sentences per run past its cursor and
  re-dispatches itself.
- **Enrichment** (`entity_sentences.enrichment_stamps`, the same
  `{enricher key: {v, pv, at}}` map ADR 0057 defined — contract factored
  into `Stamp`, legacy tolerances and both version axes intact): a sentence
  is stale when it was never enriched, when its content changed after
  enrichment, or when an enricher's declared version bumped past its stamp
  on either side. A version bump therefore still re-stales **corpus-wide**,
  evaluated sentence by sentence. `EnrichEntitySentences` walks its stale
  window 2×75 per run; the sweep picks an entity when **at least one** of
  its sentences is stale and dispatches the union of its sentences' stale
  keys, so a bump re-runs only the bumped analysis per sentence.

### 2. The entity word list is derived by a link-preserving merge — never wiped

`entity_words` is merged from the occurrence rows keyed on `(entity_id,
l_word)`: keys that survive keep their physical row — `word_id`,
`unmatchable_at`, and the audit columns exactly as the link/adoption passes
left them — with only the occurrence count and representative token
rewritten (and only when they actually differ); keys that vanished from the
text are deleted; genuinely new keys insert unlinked for the ordinary link
pass. The merge runs on **every** derivation path — incremental completing
runs and full rebuilds (post-deploy backfill, dictionary re-imports) alike
— so the popup-outage window is structurally gone: there is no wipe
anywhere in the pipeline left to close. `LinkPreservingMergeTest` is the
executable spec of the guarantee.

### 3. The entity-level word-index marker stays — as a derived max

`entities.words_indexed_at` is not dropped: it is written as
`max(per-sentence stamps)` by the **completing run** of a chain only. The
cheap staleness checks — crossword generate's "still building" answer,
recommendations exclusion, word-knowledge display, frequency accrual — stay
a single-column compare, and a mid-chain entity still reads unfinished (a
half-built max would read as fresh). `isStale()` never changed.

### 4. Enrichment staleness reads sentences only — the entity stamp column is dropped

`entities.enrichment_stamps` has no reader left and is removed (column,
cast, and the completing-run/empty-language writes). Nothing replaces its
"count as done" role because nothing needs one: a language with no
enrichers is filtered out by the sweep SQL and maps to no stale keys, and a
sentence-less entity has no stale sentence — neither can be picked, so
there is no infinite-sweep loop to guard against and no record to write.
Stamps stay quiet base-builder writes merged via jsonb `||`, committed in
the same transaction as the annotations they cover (a failed chunk stamps
nothing and the sweep re-picks).

## Consequences

- **Editing one sentence costs exactly that sentence** — one small
  re-tokenization plus one small python `/enrich` payload — instead of a
  whole-book pass; a fresh upload flows through many small yieldable runs
  instead of one giant job.
- **Deploy choreography is one bounded self-healing pass per column.** Both
  per-sentence stamp columns start null (null = stale) and are
  deliberately not backfilled: the corpus rebuilds through the existing
  five-minute sweeps — `crossword:refresh` for the word index,
  `entities:enrich` for enrichment — each run bounded per ADR 0043;
  accelerate with `--limit`, `entities:enrich --enricher=<key>`, or
  `crossword:index` per entity. Enrichment re-runs are idempotent
  recomputations; only genuinely edited sentences change results.
- The **stamps are the only completion record**: there is no entity-level
  enrichment state to consult, reset, or backfill anymore; the per-sentence
  word-index stamps and the derived entity marker keep their ticket-02
  discipline unchanged.
- `EntityWordMap` (the popup word map) stays populated across every refresh
  — the guarantee decision 2 pins — while `entity_sentence_words` gives the
  pipeline a substrate that later consumers (positions, phrase indexing)
  can build on.
