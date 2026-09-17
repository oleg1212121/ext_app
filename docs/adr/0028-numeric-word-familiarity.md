# ADR 0028: Numeric word familiarity (0–100) replaces the ternary word status

Date: 2026-09-14
Status: Accepted

## Context

`user_word.status` (`learning | solved | known`, ADR 0025) recorded only
what the crossword and a manual popup button had done — it was silent about
the far more common signal: reading. Nothing tracked that a user had seen a
word in context, or that they had to open the dictionary popup for it
(a signal of *not* knowing). The interactive-words surfaces (reader,
bilinguals simulator) had all the information needed to capture both, but
no write path on reveal or lookup.

## Decisions

1. **A linear familiarity score replaces the status.** `user_word.status`
   becomes `user_word.familiarity`, an integer 0–100 (100 = the user knows
   the word; no row = never touched). The score is global per (user, word)
   across all works, like the old status.

2. **Two exposure events move the score:**
   - **Read** (+1): the user reveals a sentence pair — checking a row's EN
     checkbox or the `all_en` column checkbox on the bilinguals simulator.
     The learning-language side's dictionary words are credited.
   - **Lookup** (−2): the first popup open of a word within a sentence row,
     on both the simulator and the reader.
   Clamped to the 0–100 band everywhere; a lookup at 100 decays the word to
   98.

3. **Events are deduplicated server-side by a ledger.** `user_word_event`
   (`user_id, word_id, row_key, kind`, unique) records every counted event;
   `row_key` is the sentence-pair scope (`mm:{meaningMatchId}` for aligned
   rows, `es:{entitySentenceId}` for unaligned reader rows, shipped in the
   page payloads as `row_keys`/`rowKeys`). Re-revealing the same sentence
   never re-credits — the score is honest without trusting the client to
   throttle itself. Row keys validate against existing meaning matches /
   entity sentences, so invented keys can't farm reads. This is the
   "real read path" door ADR 0027 left open: the table stores sentence
   references only, never positions, so the render-time segmentation
   decision stands.

4. **Crosswords interact with the score instead of owning statuses.**
   `complete` awards +5 per puzzle word (only words that already have a
   row — the ones `generate` seeded); `generate` excludes only words at
   ≥ 100 and still seeds marker rows at 0. The manual popup actions remain:
   "I know this word" sets 100, "Remove mark" deletes the row.

5. **Tinting shows four graduated bands** (revised 2026-09-17): 0–19/no row =
   rose (`.word-unknown`), 20–59 = amber (`.word-progress`), 60–99 = faint
   amber (`.word-progress-strong`), ≥ 100 = green (`.word-known`). Previously
   0 = unknown, 1–19 = progress, 20–99 = strong-progress, ≥ 100 = no tint;
   the boundary shift makes low scores read strongly negative and ≥ 100
   positive. The popup displays the raw
   score ("Familiarity: 12/100").

6. **Migration**: existing `known` rows became 100; everything else
   (learning/solved) became 0 — the old statuses carried no honest
   exposure information worth preserving.

## Considered Options

- *Client-side session dedupe* (no ledger): rejected — refresh-farmable to
  100, and crossword selection trusts the number.
- *Credit reads on the reader too*: deferred — the reader's EN text is
  always visible, so there is no reveal action to hang the event on; the
  reader only fires lookup events.
- *Per-occurrence table with positions* (rejected in ADR 0027): still
  unnecessary — events reference the sentence pair, not word positions.
- *SRS-style scheduled repetition* (Anki-like intervals): rejected for now
  — a linear exposure counter is transparent and debuggable; scheduling can
  be built on top of the ledger later if wanted.

## Consequences

- The ledger grows once per (word × sentence-pair × kind) ever read/looked
  up — a small fraction of occurrence volume; prune if it ever matters.
- `POST /word-events` is fire-and-forget from the UI (best-effort, never
  blocks reading); the response carries the resulting familiarity per word
  so the client can recolor without a refetch.
- `complete` still trusts the client's word list (same trust level as
  before); it only ever adds, never creates rows.
