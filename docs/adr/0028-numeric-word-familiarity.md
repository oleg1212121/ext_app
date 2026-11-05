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

## Amended 2026-11-05 (word-popup-overhaul ticket 01: the flat popup-open penalty replaces the lookup penalty)

Decision 2's Lookup (−2, first popup open per sentence row) is **retired
end to end** and replaced by a flat **popup-open penalty: every popup
open of a dictionary-mapped word costs −5, every time** — the ledger's
once-per-row dedupe no longer applies to popup opens, because an open is
a deliberate act of *not knowing*, not an exposure that saturates: the
reader who re-opens a word is still re-consulting it, and a per-row
first-open rule priced the second consultation of the same sentence at
zero.

- **The ledger narrows to read credits.** The `user_word_event` table and
  its unique (user, word, row_key, kind) rule survive for the
  sentence-reveal **read** credits alone (+1, unchanged): `POST
  /word-events` accepts `kind: read` only, the `lookup` kind (and its
  `LOOKUP_PENALTY`) are deleted from validation, service and browser
  helper alike, and historical `lookup` ledger rows stay untouched (no
  migration).
- **The open penalty is ledger-free and ungated.** `POST /words/opens`
  (`WordController::recordOpens`, auth + approved, throttled 60/min like
  the lookup resolve it sits beside, validated through
  `RecordWordOpensRequest` — a bounded `word_ids` list) applies −5 per
  word through `WordFamiliarityService::applyOpens` → `applyDeltas` with
  no ledger write and no dedupe: repeat opens apply independently. First
  touch creates the `user_word` row at 0, where the penalty clamps —
  decision 2's 0–100 band holds, scores never go negative.
- **The native-language exemption is server-side.** Words whose
  `language_id` equals the caller's Native setting cost nothing — the
  filter lives in `applyOpens`, not the client, so it is the single
  source of truth for every popup surface: reader columns, simulator
  columns, and the AI answer panel. An unset Native setting excludes
  nothing (nothing is provably native).
- **The response recolors, as before.** It mirrors `POST /word-events`'s
  shape — `{familiarity: {wordId: value}}` covering every referenced
  word, native words echoing their unchanged reading (null while they
  have no row) — so the client recolors the token and the popup header
  from the response alone, exactly as the old lookup event's response
  did.
- **The AI answer panel pays per popup instance.** Its mount has no word
  map (ADR 0096), so the penalty lands when its on-open resolve hits —
  once per popup instance, keyed on the resolved id; a token that stays
  missing has no row to charge and costs nothing. An adoption swap alone
  never charges: the hook is the resolve.

Rejected: **keeping the ledger for opens with a per-day or per-row
window** (a cap on a penalty for not knowing is a rule the reader can
feel but never reason about — the flat price is honest precisely because
it never varies); and **charging client-side** (farms the score the same
way the 0028 ledger exists to prevent).
