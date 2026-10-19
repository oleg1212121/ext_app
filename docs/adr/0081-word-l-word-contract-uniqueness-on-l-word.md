# ADR 0081: The word/l_word contract — plain display text, one ё-aware lookup key, and word uniqueness on (l_word, language, class)

Date: 2026-10-19
Status: Accepted

Implements the word-import-contract spec on top of the ADR
[0078](0078-goldendict-plain-words-link-only-translations.md) /
[0079](0079-form-of-relays-demote-to-forms-stage-link-prune.md) purity line
(whose shared toolkit — `StressMarks`, `PosAliases`, `LemmaForms`,
`TranslationTargets`, `StagedTranslations` — this contract extends with one
new guard class, `App\Support\DefinitionLanguage`) and continues ADR
[0080](0080-per-edition-wiktionary-sourcing-merge-on-overlap.md)'s
merge-not-replace decision from editions onto spellings. Records the
display/key split, the е-alias form rows, the uniqueness move and the merge
phase that unblocked it on real data, the majority-script definition guard,
the link-pass chaining into `goldendict:import`, and the tokenizer parity
alignment — with the numbers of the dev-DB switchover run that validated the
whole contract end to end.

## Context

After ADR 0078–0080 the importers stored plain display words and editions
merged instead of replacing, but four frictions remained in the real data:

1. **One word, several spellings, several rows.** Word uniqueness still
   keyed on the display word — `(word, language_id, word_class_id)` — so
   cross-source spellings of one word forked rows: ёлка (kaikki) beside
   елка (GoldenDict), March beside march. Every translation link, entity
   stamp and frequency rank split between the twins, and which spelling a
   reader's text carries decides which twin it resolves to.
2. **The lookup key was almost right.** `l_word` lowercased and stripped
   the combining stress marks, but kept ё — ёлка and елка keyed differently
   — and let zero-width/format characters (`\p{Cf}`: ZWSP, ZWJ, BOM, soft
   hyphen) survive verbatim into keys, silently fragmenting every match
   against them.
3. **Definitions in the wrong language.** The en-Wiktionary edition's ru
   entries carry English glosses by construction (ADR 0080's whole reason
   for the ru edition), and the bilingual GoldenDict dictionaries (Oxford
   en-ru, Mostitsky ru-en) carry the other language's article prose — both
   stored as definitions on the wrong-language words. And GoldenDict linked
   translation candidates inline but never pruned its staged copies, so
   linked strings sat in `words.translations` until someone remembered the
   link pass.
4. **The entity tokenizer kept its own key formula.** `WordTokenizer`
   (with its browser and python parity twins) still derived keys with the
   pre-purity formula — no ё fold — so entity stamps drifted from the
   dictionary keys they are matched against: a ёлка token stamped `ёлка`
   beside a dictionary that (after this contract) keys `елка`.

## Decision

Six rules:

1. **Plain display text; only the key folds.** `words.word` and
   `forms.form` keep the import source's spelling, hand-editable later:
   stress marks are stripped for the ru/be/uk stress-mark languages only
   (the `StressMarks` rule — outside them a mark can be spelling, e.g.
   "café" or Pe̍h-ōe-jī), and ё is **always kept** in display — ёлка or
   елка, whichever the source carried. The lookup key
   (`words.l_word`, `forms.l_word`) is one formula, identical for words
   and forms, computed by the shared `App\Support\WordLookupKey`:
   **trim → lowercase → strip `\p{M}` → strip `\p{Cf}` → fold ё→е**
   (U+0451→U+0435, applied after lowercase so Ё folds too; a final trim
   catches edges a strip bared). The mark strip is unconditional in the
   key — en included, "café" keys `cafe` — while display strips only for
   ru/be/uk. Kaikki headwords (and their relay bases) and staged
   translation candidates trim at storage; GoldenDict's candidate mining
   end-trims its own pieces.
2. **ё-forms carry their е-folded alias rows.** The shared `LemmaForms`
   row builder emits a second row for every lemma/form containing ё —
   ёлка carries form rows ёлка AND елка, both keyed `елка` (the alias's
   display folds case-preserving: Ёлка → Елка) — so texts and dictionaries
   that drop ё resolve through the forms pass without a second word row.
   A source already listing both spellings dedupes to two rows.
3. **Word uniqueness keys on `(l_word, language_id, word_class_id)` — and
   the data merges before the index builds.** Migration
   `2026_10_19_000001` drops `uk_words_word_language_class` (and the
   superseded non-unique `idx_words_l_word_class`) and creates
   `uk_words_l_word_language_class`; NULL keys stay unconstrained. Every
   Word-creation site upserts/firstOrCreates on the key triple, and a
   second source **attaches to the stored row — it never replaces it**
   (ADR 0080's merge semantics continued onto spellings): the first
   source's display spelling stands and later manual edits survive
   (kaikki's upsert refreshes the key + the staged JSON; GoldenDict's the
   key only). Same-`l_word` rows under different classes stay legal
   second rows. The index cannot build over same-key duplicates, so the
   one-time data path precedes it: `words:normalize` phase 2 recomputes
   every `l_word` (words, forms) to the new formula and merges duplicate
   `(l_word, language_id, word_class_id)` groups onto one survivor —
   **ё-display first, then most definitions, then lowest id** (the user:
   display "should be either ёлка or елка… and i can edit it later" — ё
   wins so the fullest spelling survives) — and runs under the OLD schema,
   making **normalize → gate (zero duplicate groups) → migrate** the
   required order on any pre-existing database. Children (definitions,
   forms, links both arms, entity stamps, user familiarity, events, tags,
   the fetch ledger, staged JSON) move through the ADR 0079 merge
   machinery; ranks fold as LEAST.
4. **Definitions import only in the word language's own script.** The
   shared `App\Support\DefinitionLanguage` guard (en → Latin;
   ru/be/uk → Cyrillic; anything else unmapped) keeps a definition only
   when the word language's script holds a **strict majority** of the
   text's letters — an exact tie drops, a letterless string passes, an
   unmapped language passes everything. Wired into the kaikki definition
   flush and the GoldenDict segment→definition path (so `--dry-run` tallies
   what the real flush stores); each drop counts in
   `definitions_skipped_foreign`. Etymologies are deliberately never
   guarded (they cite source-language material in its own script), and
   enforcement is import-time only — stored definitions are never purged.
5. **A bilingual GoldenDict import converts its own backlog.** A default
   `goldendict:import` chains `dictionary:link-translations` at the end
   (mirroring `wiktionary:import-raw`), skipped on `--no-link`,
   `--dry-run`, or when fewer than two languages hold words — so linked
   candidates leave the staged JSON in the same invocation instead of
   waiting for a manual pass.
6. **One key formula, parity-bound across the three tokenizers.** The
   entity tokenizer's `entity_words.l_word` stamps go through the same
   `WordLookupKey::normalize`: the PHP `WordTokenizer` delegates to it,
   and the parity twins — the browser `wordTokenizer.mjs` and the python
   `cyrillic_stress.py` `lookup_key` — carry the identical pipeline,
   pinned by `TokenizerParityTest` (plus new vitest and pytest pins). The
   python change is result-neutral for enrichment, so
   `ALGORITHM_VERSION` deliberately stays 1 on both sides — no
   re-enrichment sweep is owed.

## Alternatives considered

- **Keying uniqueness on the lowercased display word** (status quo ante) —
  rejected: it is exactly the fork this contract removes; a display
  spelling is a source accident, not an identity.
- **Folding ё in the display text** — rejected by the user's contract:
  display "should be either ёлка or елка (depends on the import source)"
  and stays editable; folding would destroy the fullest spelling. The
  е-alias form rows buy the dropped-ё matching without touching display.
- **Letting the uniqueness migration merge duplicates itself** — rejected:
  survivor choice is a content decision (ё-display, definition counts) a
  schema migration should not make silently; the merge lives in the
  reviewed, idempotent, `--dry`-runnable normalize phase with its child-move
  machinery, and the migration stays a dumb gate that fails loudly on
  unmerged data.
- **Purging already-stored foreign-script definitions** — rejected: the
  user scoped the guard to new imports; a purge of existing rows is a
  separate mandate.
- **Teaching the entity linker both key formulas** (keep the tokenizer's
  old one) — rejected: two formulas had already drifted apart; one shared
  implementation with a parity test is the fix, not a second lookup path.

## Consequences

The dev-DB switchover run (2026-10-19, dev DB `ext_app`, no code changed —
the runbook order in `wiki/playbooks/import-dictionary-data.md` is the
contract's own gate) validated every rule on real data:

- **normalize** (baseline `--dry` 102s: 19,986 same-key rows to merge;
  18,277 word + 131,220 form keys to recompute; all other phases 0):
  real run 184s, merged the same 19,986 duplicate rows — plus 14
  unknown-class twins phase 3 exposed post-merge (the old phases' documented
  dry approximations; the new-phase rows matched the dry exactly) —
  **20,000 absorbed rows deleted** in total; keys recomputed 18,277 words +
  131,147 forms.
- **The gate held**: a second `--dry` reported all rows 0, and the SQL
  `having count(*) > 1` check on `(language_id, l_word, word_class_id)`
  returned 0. Only then **migrate** built `uk_words_l_word_language_class`
  — in 1s — and the post-migrate `--dry` was a full no-op.
- **Counts**: en 1,013,927 → 999,884 (−14,043), ru 500,430 → 494,473
  (−5,957) — the 20,000-row drop is exactly the merge count (delta
  reported, not forced back); forms 5,579,192 → 5,568,861 (insert-select
  dedupe on the moves); `entity_words` 7,549 unchanged (185 stamps
  re-pointed in place); NULL-key words 0 (backfilled). The
  `word_translations` total was not snapshotted pre-run — the summary
  table is the record: 1,941 pairs re-pointed, 1,992 deduped onto existing
  pairs.
- **The survivor preference is real in the data**: ёлка/елка collapsed to
  ONE row (id 1964623) — display `ёлка` (ё-display beat the definitions
  count), key `елка`, frequency 4753 (the absorbed row's unranked
  1,100,000 sentinel never beat the ranked 4753 in the LEAST fold) —
  carrying **both** form rows ёлка and елка. собака stayed one row and
  kept all 24 definitions (22 + 2 moved from its absorbed `Собака` case
  twin: 7 Latin-script + 17 Cyrillic, nothing lost, nothing duplicated).
- **The combining-marks gate is per-language.** "Zero combining marks in
  `words.word`/`forms.form`" holds only for the stress-mark languages —
  full scans of 1,494,357 words + 5,568,861 forms found **0 in ru** —
  while en legitimately keeps 100 words + 234 forms carrying marks
  (Pe̍h-ōe-jī, Pha̍k-fa-sṳ, Sḵwx̱wú7mesh, u̠t-Ma'in …): outside ru/be/uk
  the marks are spelling (rule 1). Any future gate or check must scope
  itself to the `StressMarks` languages.
- **Staged-JSON trim gate**: 7,931 candidate strings across a 5,000-row
  sample of the 44,102 staged rows — 0 padded (leading/trailing
  whitespace; some legacy candidates still carry internal stress acutes
  from before the staging-time strip, documented prunable through the
  link passes' pairing-time strip).
- **Tokenizer parity closed the loop**: ticket 05's recompute had already
  folded existing `entity_words.l_word` stamps in the same run, so no
  further data repair is owed; only entities indexed between a pre-parity
  deploy and the next refresh would need `crossword:link`, and the
  5-minute refresh sweep self-heals. `EntityWordAdoption`'s
  `firstOrCreate` on the key triple stays as the permanent race net.
- Going forward: no writer can create a same-key pair (the unique forbids
  it), every matcher — link passes, frequency imports, the entity linker,
  the popup — compares the one folded key, and display spellings remain
  hand-editable in `/admin` without moving the row's identity.
