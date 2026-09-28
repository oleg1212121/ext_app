# ADR 0048: Strict alignment invariants — junction uniqueness, total completeness, weak-pair rescue

Date: 2026-09-28
Status: Accepted

## Context

Three production-visible defects shared one root: the alignment pipeline's
invariants were either aspirational or scoped to the wrong side.

1. **Fake 1-sided rows.** The greedy aligner has a hard match bar
   (`ALIGN_DEFAULT_THRESHOLD`, 0.55 live): any pair scoring below it produces
   *no* match, and the skip rule (`_should_skip_en`, weaker-side-first)
   deterministically cascades — skip a[i], skip b[i], match i+1 — the exact
   repeating "1-sided EN, 1-sided RU, then a matched row" pattern. Genuine
   LaBSE pairs on adapted texts score as low as 0.59, so pairs dipping just
   under 0.55 are common and easy. The orphan-merge post-pass explicitly
   ignores gaps with orphans on *both* sides, which is precisely this shape.
2. **Duplicate junctions.** The DB permitted a sentence in N meaning matches
   (the junction unique index was per sentence+row+side). The resequence
   dedupe only dropped *fully subsumed* machine rows; partial overlaps
   survived, single-sided human landmarks delimited no pool so re-aligns
   re-junctioned their sentences, and the editor re-inserted draft junctions
   verbatim — pinning duplicates as landmarks.
3. **Invisible sentences.** The completion repair covered only the work's
   *original* side, so junction-less translation-side sentences appeared
   nowhere in the reader/simulator (only in the editor's live unmatched
   pools). Several mid-run loss paths (empty commits, exhausted sides)
   dropped non-original sentences the same way.

## Decisions

### 1. Weak-pair rescue in the aligner (greedy)

A mutual-best 1:1 that no window combo cleared the match bar for is emitted
as a **real match carrying its true sub-threshold score** when it clears
`ALIGN_RESCUE_THRESHOLD` (live knob, default 0.45, 0 disables; capped at the
match bar). The pair lands in the editor's *Needs review* as a low-similarity
row instead of masquerading as two single-sided rows. Two-sided orphan gaps
are additionally re-walked at the rescue bar (`_rescue_orphan_gaps`) so
window pairings just under the match bar can still pair. Non-mutual weak
pairs stay skipped — the structural guard is what makes the lower bar safe.

### 2. Strict junction uniqueness, DB-enforced

One sentence is junctioned into **at most one meaning match per side per
entity match**, period.

- **Schema**: `sentence_meaning_matches` gains a denormalized NOT NULL
  `entity_match_id` (auto-filled from the parent meaning match by a model
  `creating` hook so no Eloquent writer escapes) and a unique index
  `(entity_match_id, entity_sentence_id)`. The migration backfills the
  column, resolves pre-existing duplicates inline (landmark/human rows win,
  then higher similarity, earlier order, lower id) and deletes empty meaning
  matches before creating the index.
- **App-level resolution** (`duplicateJunctionResolutions`, run by
  `resequenceMatchesByDocumentPosition` at completion, copy, and
  `alignments:repair`): per (side, sentence) a keeper is elected by the
  existing priority tuple (landmark/human → two-sided → richer → higher
  similarity → earlier order → lower id) and losers are **trimmed** — the
  shared junction is deleted, not the whole row; rows left empty are
  deleted. Human-vs-human conflicts keep the earlier-ordered row.
- **Write-time prevention** (`persistSegment`): landmark-junctioned
  sentences are *reserved* — incoming machine windows skip their junctions
  (a window whose every sentence is reserved stores no row), so a re-fed
  window over a single-sided landmark can no longer create even a transient
  duplicate.
- **Editor**: `syncMeaningMatches` dedupes the junction insert list
  (first row in order keeps the sentence); `rowIdOfSentence` is scoped to
  the match.

### 3. Total completeness

`skipSides()` returns both sides unconditionally: the completion gate
repairs junction-less sentences on **both** sides into single-sided rows,
and mid-run no-progress paths store skip rows for whichever side's cursor
actually advances (parked sides still re-feed — the premature-skip guard is
unchanged). Every sentence of both entities is therefore visible in the
reader/simulator — junctioned into a row or as a one-sided row. The
opposite-side repair spread-order collision (translation↔translation pairs
computed identical `spreadOrders` values and rolled the whole side's repair
back) is fixed by a shared claimed-orders set that nudges collisions.

### 4. Repair command

`alignments:repair {id} [--all]` re-runs the strict dedupe + resequence +
both-side backfill over existing (completed) matches — the production
cleanup path, since nothing in the pipeline revisits completed matches.

## Consequences

- Reader/simulator now show one-sided rows for translation-side sentences
  that used to be invisible; editors see fewer 1-sided pairs and more
  low-similarity two-sided rows (flagged in Needs review) for weak pairs.
- Any future writer that junctions a sentence twice fails loudly on the
  unique index instead of corrupting silently.
- Rescued matches carry scores in [0.45, 0.55) — they are *not* landmarks
  (below 0.90) and are re-aligned like any machine row on Re-align.
- Tests that seeded duplicate junctions must suspend the constraint first
  (legacy-state staging); tests asserting original-side-only repair now
  expect both sides.
