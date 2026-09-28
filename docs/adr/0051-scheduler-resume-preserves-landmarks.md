# ADR 0051: Scheduler resume preserves landmarks for set-up matches

Date: 2026-09-28
Status: Accepted

## Context

The 5-minute `alignments:resume` command picked up every `pending` entity
match and ran it through `AlignEntitySentences::beginFromScratch()`, which
deletes **all** `meaning_matches` rows — including human-edited ones
(`alignment_chunk = -1`) and auto-landmarks — before re-aligning from zero.

That was correct for freshly created matches, but sentence add/edit/delete/
reorder on the entity page flips a **completed** match back to `pending`
(ADR 0015's sentence-mutation rule). Within five minutes of any such edit —
even a pure drag-reorder — the scheduler silently destroyed the user's manual
alignment work and replaced it with machine output. This happened in
production on 2026-09-28 (~17:35 and 17:55 UTC) while a user was hand-editing
alignments; the illustrations survived only because they are
`entity_sentences` rows (ADR 0050), which the pipeline never deletes.

The landmark-preserving entry point `AlignEntitySentences::begin()` already
existed (Filament "Re-align" uses it) and `wiki/domains/sentence-alignment.md`
Stage 4 already documented the scheduler as running through it — the code had
diverged from the documented contract. Two defects in `begin()` itself had to
be fixed before it could carry the scheduler:

- Its "never set up" guard was `$entityMatch->a_total_sentences === null` —
  **dead code**: the column is `NOT NULL DEFAULT 0`, so the delegation to
  `beginFromScratch()` could never fire and a row-less match would have been
  "re-aligned" against stale zero totals with no verify pass.
- It reused the snapshot (`a_total_sentences`/`b_total_sentences`,
  `chunk_size`, `max_n`) from the previous run, so sentences added since that
  snapshot would silently stay outside the re-aligned span.

## Decision

- `AlignmentsResumeCommand` dispatches through
  `AlignEntitySentences::begin()` instead of `beginFromScratch()`. The
  command's dry-run and dispatch output report the path taken
  ("landmarks preserved" / "from scratch").
- `begin()` delegates to `beginFromScratch()` exactly when the match has **no
  `meaning_matches` rows** — the direct data-safety property: the scheduler
  never wipes rows that already exist, and a row-less match gets the full
  fresh path with its verify pass. Row existence replaces the impossible
  null-totals check as the "never set up" discriminator.
- `begin()` re-snapshots the run plan (image-less counts per ADR 0050,
  `chunk_size`/`max_n` clamps, small-entity single-chunk raise) via the same
  helper `beginFromScratch()` uses, and mirrors its zero-side immediate
  finalize. Sentences added or removed since the previous run are therefore
  inside the re-aligned span.
- `beginFromScratch()` remains the entry point for genuinely fresh matches
  (all creation dispatch sites) and the explicit Filament "Run from scratch"
  action, whose modal already warns that it deletes human rows.

## Consequences

- Human edits and ≥0.90 auto-landmarks survive every scheduler-triggered
  re-align; only machine rows below `LANDMARK_THRESHOLD` (0.90) are
  re-derived, as with the Filament "Re-align" action.
- `begin()` no longer runs the entity-pair verify (consistent with the
  Filament action): a re-pended match keeps its verified pair status and
  re-aligns around its landmarks. Fresh matches still verify via
  `beginFromScratch()`.
- Known limitation, unchanged by this ADR: flipping an `aligning` match to
  `pending` (a sentence edit landing mid-run) races the in-flight chunk job;
  junction-uniqueness constraints (ADR 0048) make such a collision fail
  loudly rather than duplicate junctions.
- Today's prod incident data is not recoverable from this fix; restoring the
  lost manual rows requires the pg backup taken before ~17:32 UTC.
