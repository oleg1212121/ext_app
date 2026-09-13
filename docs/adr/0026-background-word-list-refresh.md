# ADR 0026: Background word-list refresh via scheduled sweep and queue

Date: 2026-09-13
Status: Accepted

## Context

ADR [0025](0025-crossword-word-index-and-progress.md) left the entity word
list lifecycle manual: `crossword:index` and `crossword:link` had to be run
by hand, and `POST /crossword/generate` rebuilt the index inline (blocking
the request) when stale — but never linked, so fresh entities produced
empty crosswords until a human remembered `crossword:link`. Meanwhile the
stack already runs a database queue with worker replicas in production and
a scheduler container (`schedule:work`), and `alignments:resume` had
established the pattern of a scheduled sweep that dispatches queue jobs.

## Decisions

### 1. Scheduled sweep dispatches per-entity queue jobs

`crossword:refresh` (scheduled every five minutes, `withoutOverlapping`)
selects entities that have sentences and either a stale index (never
built, or any sentence `updated_at` after `words_indexed_at`) or any
unlinked `entity_words` row, and dispatches one `RefreshEntityWords` job
per entity. The job indexes when stale and always runs the link pass.
Alternatives rejected:

- *Event-driven dispatch* (after upload, sentence CRUD): every future
  mutation path must remember to dispatch — one missed path silently
  stale. The staleness check already catches all mutation paths.
- *Sweep does the work inline in the scheduler*: no retries, and a large
  backlog starves the scheduler's every-minute ticks (which also drive
  `alignments:resume`).

### 2. Linking is re-attempted for every entity with unlinked tokens

The sweep does not track "already tried": tokens without a dictionary
match are retried every sweep. Linking is idempotent (only touches
`word_id IS NULL` rows), so this makes `wiktionary:import` and
`words:import-frequency` take effect within one sweep interval with zero
manual steps. A `words_linked_at` gate column was rejected as unneeded
precision at this scale.

### 3. Generate stops building inline and reports "still building"

`POST /crossword/generate` no longer rebuilds anything. When the entity's
word list is stale it returns 422 with a new UI string,
`crossword.still_building` (seeded en+ru), distinct from the existing
`crossword.not_enough_words`, which keeps meaning "the list is built and
linked but the level band is thin". Index and link run back-to-back in
the same job, so "indexed but not yet linked" is not a user-visible
state worth a separate response.

### 4. Job uniqueness and link logic placement

`RefreshEntityWords` implements `ShouldBeUnique` keyed by entity id, so a
backed-up queue cannot hold two refreshes of one entity that would race
on the `uk_entity_words_entity_l_word` unique constraint; a dropped
duplicate is harmless because the next sweep re-detects staleness. The
linking logic moved out of `LinkEntityWordsCommand` into
`App\Classes\EntityWordLinker` (the command and the job share it);
`crossword:index` / `crossword:link` remain as manual entry points.

## Consequences

- Freshness lag is bounded by one sweep interval plus job time; a fresh
  upload can 422 "still building" for a few minutes instead of blocking
  one request and silently staying unlinked forever.
- No schema change: staleness reuse `entities.words_indexed_at`; the
  sweep's unlinked-token branch reads `entity_words.word_id IS NULL`.
- Jobs log per-entity stats (`linked`, `unmatched`, reindex flag) — no
  dedicated UI or Filament visibility in this iteration.
- Dev has no `schedule:work` (same as `alignments:resume`): the sweep is
  exercised manually via `php artisan crossword:refresh`; production's
  scheduler container picks it up automatically.
