# Symmetric word translations: one row per pair

ADR 0018 specified a directed `word_translations(from_word_id, to_word_id)`
pivot with one row per direction. Direction carries no meaning in practice —
nothing at runtime reads the pivot, the bulk linker derives the same pairs
from either dump, and curators think in "these two words are translations of
each other", not in from/to — so mirroring every manual link into two rows
only bought an asymmetry the app never uses. We store **one row per word
pair**, usable from either word: columns renamed to `word_a_id`/`word_b_id`
with the lower word id as the A-side (the entity-match convention), a unique
index enforcing pair uniqueness in canonical order, links only between words
of different languages, and the bulk linker writing one canonical row per
pair with symmetric existence checks.

**Status**: accepted — supersedes the directed-pivot clause of ADR 0018

## Considered Options

- **One row per pair (chosen).** Storage matches the mental model; the admin
  attach action is one insert, detach one delete; no mirrored-row
  bookkeeping anywhere.
- **Directed rows with symmetric auto-creation** (attach writes both rows,
  detach removes both). Rejected: two rows for an interchangeable fact,
  imported data can still be asymmetric, and every consumer has to merge
  both directions anyway.

## Consequences

- "All translations of a word" matches both columns (`word_a_id = ?` OR
  `word_b_id = ?`); both need indexes.
- Filament's standard AttachAction can't be used as-is: the parent word may
  sit on either side, so attach/detach normalize direction before writing.
- Dev data holding mirrored rows is collapsed by a one-off script (dedupe
  keeping the canonical row, rename, index swap) — no fresh re-import; prod
  is rebuilt fresh at the next deploy regardless (ADR 0018).
