# ADR 0084: Word rows carry an insert-stamped source; first importer wins, re-runs fill nulls only

Date: 2026-10-20
Status: Accepted (amended 2026-10-24 — the Wiktionary value renamed from 'kaikki' to 'wiktionary')

Implements popup-provenance ticket 03. The word popup's provenance badge
(ticket 04) reads this column through the payload; this ADR records the
data layer alone. Extends the provenance line of
[ADR 0082](0082-ask-ai-enrichment-merge-inline-lock-on-success.md): the
content tables carry per-row `source` columns (null = imported / `'ai'`),
and now the `words` row itself says which dictionary creator minted it.

## Context

A word row can be minted by five writers: the Wiktionary (kaikki) import,
the GoldenDict import, the CMUdict import, entity adoption from reading
tokens, and the ask-AI adopt-on-ask mint. By the word-import contract
([ADR 0081](0081-word-l-word-contract-uniqueness-on-l-word.md)) they all
merge into the same `(l_word, language_id, word_class_id)` row — a second
source's spelling of one word attaches to the first source's row and
updates it. Nothing on the row recorded WHO created it: admin triage
("why does this word exist with no definitions?") had no answer, and the
popup cannot badge a word by its importer. The content tables' per-row
`source` columns do not help — they mark content rows written by the AI
merge, not the creator of the word itself, and the importers ignore them
by design (ADR 0082).

## Decision

1. **`words.source` is a nullable string(16) over a closed set stamped at
   INSERT.** The values are exactly `wiktionary | golddict | cmudict |
   entity | ai` (`Word::SOURCES`; amended 2026-10-24, below). Every
   creating writer stamps its value
   in the INSERT payload: the three importers in their `Word::upsert`
   row arrays, entity adoption and the ask-AI mint in their
   `firstOrCreate` values. The admin's Filament Words form gets no source
   field — provenance is machine-written, never hand-editable.
2. **First importer wins — `source` never appears in an update.** Because
   later imports merge into the same row by design, the column must not
   flip as sources pile on: the kaikki upsert's update list stays
   `['l_word', 'translations']`, the GoldenDict one `['l_word']`, and the
   CMUdict upsert pins its conflict update to `['word']` explicitly (its
   update list was previously derived from the row keys, so an un-pinned
   `source` would have leaked into it). The two `firstOrCreate` mints
   touch source only on their create branch.
3. **The fill-if-null backdoor (the agreed no-backfill backdoor).** There
   is no one-time backfill — pre-column rows keep null. Instead every
   creating writer's per-batch write runs one scoped UPDATE that claims
   only still-null rows: the importers scope it to the batch's
   `(l_word, word_class_id)` pairs in the language
   (`Word::fillSourceIfNull`, run after the batch's upsert), the single-row
   mints scope it to the row their `firstOrCreate` found (the race net: a
   concurrent ask or adoption that lost the insert claims the winner's
   row if nobody else has). Re-running an import therefore badges the
   pre-column rows it touches; rows nothing touches again stay null —
   which is honest: their creator is genuinely unknown. A resolve-hit on
   the adopt paths is a read, never a claim: `resolveSurface` hits and
   `EntityWordAdoption`'s adopt-onto-existing path (a creating write it
   is not) leave the row's source exactly as it is.
4. **The admin reads it: a Source column (dash for null) and a Source
   filter on the Filament Words table.** Column + filter only, no form
   field.

## Consequences

- The dev/prod database gains the column on the next `php artisan
  migrate`; existing rows read null until their creator's import re-runs
  (the whole point of the backdoor), so the popup badge (ticket 04) and
  the admin filter must treat null as a normal state, not an error.
- Attribution of a filled pre-column row is a heuristic: a re-run claims
  rows whose true creator was a different import (e.g. a pre-column row
  actually minted by GoldenDict gets 'kaikki' when the kaikki import
  re-runs over it). Agreed trade-off — perfect pre-column attribution is
  unrecoverable, and a re-run is the best available witness.
- The claim UPDATE bumps `updated_at` on the rows it touches, which marks
  them stale for the completeness recalc — harmless (one lazy recompute),
  and semantically right: the import touched the row.
- A sixth creator (if one ever appears) means a new `Word::SOURCES` value,
  an insert stamp, and — if it re-touches existing rows — a claim call;
  the Filament filter picks the set up automatically.
- Cross-source display spellings still collapse into one row (ADR 0081),
  so `source` names the creator of the row's FIRST spelling, not of every
  spelling that merged into it. The content tables' per-row `source`
  columns (null/ai) are a different mechanism and are unchanged, per the
  ADR 0082 amendment.

## Amendment 2026-10-24: the Wiktionary value is 'wiktionary', not 'kaikki'

The popup renders the stored value verbatim (the provenance badge, since
moved to each section label's end and, the same day, rendered as one
small bordered label per source part — the "wiktionary" chip plus the
"ai" chip when asked), and "kaikki" is the dump service's
name (kaikki.org), not the source's — the content comes from Wiktionary.
The stored value was renamed for user-facing honesty:

- `Word::SOURCE_WIKTIONARY = 'wiktionary'` (constant renamed with the
  value; every writer reads the constant, so the Wiktionary import stamps
  the new value with no further change).
- A one-line data migration flips the existing rows
  (`UPDATE words SET source = 'wiktionary' WHERE source = 'kaikki'`) —
  dev and prod both carried stamped rows.
- The closed set, the insert-stamp rule, first-importer-wins and the
  fill-if-null backdoor are unchanged. 'wiktionary' fits the string(16)
  column.
- The *pipeline* vocabulary deliberately keeps "kaikki": the dump file
  paths (`laravel/kaikki/`, `--file=kaikki/...`), the
  `UsageLabels::fromKaikkiTags` helper, and the import commands'
  descriptions name the kaikki.org dump format/service, which is a
  different thing from the content source the column value names.
