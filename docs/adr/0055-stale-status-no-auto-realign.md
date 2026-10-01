# ADR 0055: Sentence edits mark matches stale; only explicit Re-align re-aligns

Date: 2026-10-01
Status: Accepted

Supersedes the pending-on-edit rule of ADR 0015 ("Match staleness") and
refines ADR 0051: the scheduler is landmark-preserving, but it still
re-aligned every machine row below the landmark bar whenever a sentence edit
re-pended a match.

## Context

A sentence edit on the entity page flips every entity match involving the
entity to `pending` (ADR 0015), and the 5-minute `alignments:resume`
scheduler picks up `pending` matches and re-aligns them through
`AlignEntitySentences::begin()`. Since ADR 0051 that run is
landmark-preserving — human rows (`alignment_chunk = -1`) and auto-landmarks
(`similarity >= 0.90`) survive — but it still **deletes and re-derives every
machine row with `similarity < 0.90`**, and machine rows are re-created in a
new order.

This happened in production again on 2026-09-30 (~20:50 UTC): a user edited a
sentence on a fully hand-tuned match (weak rows deliberately kept after
conflict resolution — deletes, unlinks, reorders — that were never captured
as approved rows), and within five minutes the scheduler re-derived the
machine rows in a different order. No background process should ever be able
to do that; the only thing that re-aligns an existing match must be an
explicit click on Re-align.

`pending` currently means two different things — "fresh match awaiting its
first (desired) automatic run" and "existing match whose rows must be
re-derived" — and only the second is undesired as a scheduler trigger.

## Decision

- Sentence mutations (insert / update / delete / reorder on the entity page)
  now flip affected matches to a new **display-only status `stale`**, via
  `EntityController::markMatchesStale()` (the 4 call sites are unchanged:
  storeSentence, updateSentence, destroySentence, reorderSentences).
  `stale` means: "sentences changed since the last run — re-align manually if
  needed".
- **`pending` is reserved for fresh matches** awaiting their one automatic
  scheduler run. The scheduler (`alignments:resume`) is unchanged and keeps
  picking only `pending`, so stale matches are invisible to it forever.
- **Only explicit human actions re-align or clear the flag:**
  - Filament **Re-align** / **Run from scratch** (now also visible on stale
    matches) — status → `aligning`;
  - a full **Filament alignment editor save** (`AlignmentEditorPersister`) —
    status → `completed`;
  - a full **sentence re-import** (`EntitySentenceImporter`) — status →
    `completed`.
  React alignment-editor row edits (approve / unlink / move / new rows) leave
  `stale` untouched — the badge keeps nagging until a full re-align or save.
- `AlignEntitySentences::finalize()` writes `completed` **only when the
  match is still `aligning`** at completion time (it re-reads the status from
  the DB); the repairs and `linked_count` update always run. Same guard in
  `failed()`: a chain dying after a mid-run edit leaves `stale` untouched.
  An in-flight run therefore cannot silently complete over the staleness
  signal. (It also cannot be cancelled — the chain finishes its row writes;
  the flag survives, which is the point.)
- Re-align semantics are unchanged (per ADR 0051): it keeps human rows and
  ≥0.90 auto-landmarks and re-derives machine rows below the bar. To protect
  a row from a future Re-align, **approve it in the alignment editor** — the
  approve action pins it as a human landmark (`similarity = 1.0`,
  `alignment_chunk = -1`).
- `stale` holds **no processing slot**: `ProcessingLimits::alignmentsInFlight`
  counts only `pending`/`aligning` (ADR 0044). It is also not copyable —
  `AlignmentCopyService` sources from `completed` only.

## Consequences

- No background process can re-align, delete, or reorder an existing match's
  rows. Audited writers of `meaning_matches` after this change: Re-align,
  Run from scratch, both alignment editors (explicit user actions), the
  importer/copy service (explicit), the sentence-delete cascade (integrity:
  junctions removed, a row only if it empties completely), and the daily
  `entity-orders:rebalance` (renumbers `order` values in existing sequence —
  provably position-preserving, never deletes/creates). The artisan
  `alignments:resequence` / `alignments:repair` commands modify rows but are
  not scheduled — they run only when a human types them.
- Matches sitting `pending` at deploy time still auto-align once (that is
  fresh-match behavior); no prod data migration is needed. The status column
  is a plain varchar — only its comment was updated.
- The known race from ADR 0051 narrows: clicking Re-align on a match whose
  chain is still finishing (possible only if the match went stale mid-run)
  can overlap the in-flight job; junction-uniqueness (ADR 0048) still makes a
  collision fail loudly rather than duplicate junctions.
- Pre-existing inverse gap, unchanged: sentence edits made inside the React
  alignment editor still flag nothing on the entity's other matches.
- Display: `stale` renders as the raw lowercase status text everywhere
  (consistent with every other status), warning/accent-colored in the
  Filament badge, the view-page blade, and the React badge maps. The phantom
  `verifying` value (in the migration comment and React maps, never written
  by any code) was dropped.
