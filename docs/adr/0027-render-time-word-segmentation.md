# ADR 0027: Render-time word segmentation (no persisted word positions)

Date: 2026-09-13
Status: Accepted

## Context

Interactive reading (clickable words showing dictionary info, and word
progress tinting on the reader and bilinguals simulator) needs the entity's
text split into words. `entity_words` (ADR 0025) is a per-entity aggregate —
it has no per-occurrence data: no sentence references, no character offsets.
The obvious-looking fix is a persistence pass: store every word occurrence
with its sentence and offsets during indexing.

## Decisions

1. **Positions are derived at render time, never stored.** The word
   tokenizer (`App\Classes\WordTokenizer` and its browser port
   `resources/js/lib/wordTokenizer.mjs`) is a deterministic pure function of
   the sentence text, so every read path can recompute positions for exactly
   the rows being displayed. No occurrence table exists.

2. **The browser splits the text; the server ships a word map.** Row text
   continues to travel as plain strings. The page payload carries a compact
   per-entity **word map** — `l_word => {w: word id, s: progress status|null}`,
   dictionary-linked tokens only — built by `EntityWordMap`. A small JS
   tokenizer (same regex, byte-for-byte parity enforced by
   `tests/Unit/TokenizerParityTest.php`) segments the text in the browser and
   looks tokens up in the map. This keeps payloads text-sized for whole-book
   reads and works for the paginated simulator without schema changes.

3. **Word details are fetched lazily on click** (`GET /words/{word}` with an
   optional `?surface=` that flags inflected forms via the forms link pass of
   `EntityWordLinker`), and **word progress is set from the popup**
   (`PATCH`/`DELETE /words/{word}/progress`) — the "I know this word" route
   ADR 0025 anticipated.

## Considered Options

- *Persisted occurrence table* (`entity_sentence_words` with sentence id +
  offsets, rebuilt wholesale on every re-index): rejected — no read path in
  this work needs it, it adds ~100k rows per book of staleable derived data,
  and sentence edits would invalidate it. The door stays open: if a real read
  path appears (e.g. word-level bilingual alignment), an occurrence table can
  be added without changing the render path.
- *Server-side segmentation* (controllers pre-split rows): rejected —
  inflates JSON two- to threefold for whole-book reads and couples the reader
  to pagination changes.

## Consequences

- Segmentation can never disagree with sentence content — it is recomputed
  from it.
- The PHP and JS tokenizers must stay in sync; the parity test is the guard.
- Unlinked tokens render as plain text (by design, per ADR 0025's
  token-first inventory).
