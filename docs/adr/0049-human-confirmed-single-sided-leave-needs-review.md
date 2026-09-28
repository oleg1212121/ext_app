# ADR 0049: Human-confirmed single-sided meaning matches leave Needs review

Date: 2026-09-28
Status: Accepted

## Context

ADR 0048 made single-sided meaning matches a routine part of the data:
total-completeness repair junctions every sentence of both entities, and the
editor's own flows (add sentence into an empty row, drag from an unmatched
pool, unlink one side) deliberately produce rows with one empty column.

The editor's **Needs review** list flagged every one-sided row regardless of
how it came to be. A row the human had just shaped themselves — placed a
sentence with no counterpart, or approved the row as intentionally one-sided —
kept nagging in the review list forever, indistinguishable from pipeline
noise. Reviewers spent clicks re-inspecting their own work.

## Decision

Needs review membership becomes: **one-sided AND similarity < 1.0, OR
similarity < 0.55** (`AlignmentEditorApiPresenter::needsReviewPagePayload`,
`HUMAN_CONFIRMED_SIMILARITY = 1.0`).

A one-sided row trusted at 1.0 is human-made and therefore intentional — it
leaves the list as if resolved, without any new stored state. This rides on
the existing similarity convention (see the glossary's Similarity entry):
every editor mutation that shapes a row structurally writes `similarity = 1.0`
(link, unlink, add sentence, move, create row, approve), while the pipeline
emits its one-sided rows at `0.0`, so the two populations never overlap.

`alignment_chunk = -1` was considered as the discriminator and rejected:
approve and create-row set it, but link/unlink/add-sentence do not, so it
would miss exactly the workflows that motivated this change. Similarity is
the only marker every human path already writes.

## Consequences

- Pipeline-made single-sided rows (similarity 0.0, from total-completeness
  repair or weak-pair fallout) stay in the review list; only human-confirmed
  ones disappear — including the section's count badge and pagination totals,
  which derive from the same query.
- Unlinking one side of a two-sided row immediately removes it from the list
  (unlink writes 1.0). This is correct: the human just made the row
  deliberately one-sided.
- A human-confirmed single-sided row can re-enter the list only through the
  pipeline resetting its similarity below 1.0 (structural re-derivation),
  which is the existing reset rule for human edits.
- No "resolved" state is introduced — the term remains outside the domain
  language; "human-confirmed" (similarity 1.0) is the existing concept doing
  the work.
