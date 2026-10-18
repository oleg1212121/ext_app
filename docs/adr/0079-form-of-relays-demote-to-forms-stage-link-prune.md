# ADR 0079: Form-of relays demote to forms, every word is its own lemma form, and translations follow one stage → link → prune lifecycle

Date: 2026-10-18
Status: Accepted

Implements the dictionary-word-purity spec on top of ADR
[0078](0078-goldendict-plain-words-link-only-translations.md) (whose
2026-10-18 addendum reverses the kaikki display-headword carve-out and
retires the flagged auto-fetch follow-up). This ADR records the decisions
that spec added beyond it, with the numbers of the first real dev-DB run
that validated them.

## Context

The first full run of the purity rules against real data (kaikki en+ru
dumps, cmudict, the bilingual GoldenDict dictionaries) surfaced four
structural gaps the per-importer fixes could not cover:

1. **Form-of relay entries were words.** Wiktionary marks inflected
   variants standing alone as full entries whose EVERY sense carries the
   sense-level `form_of` list (+ the `form-of` tag): "четвереньках —
   prepositional of четвере́ньки". The kaikki parser ignored the signal
   entirely, so relay rows with relay glosses filled the `words` table —
   ~379k of 444k ru and ~974k of 1.5M en rows at run time. The pathology
   compounds: relays hold links, entity tokens and user familiarity rows,
   split the forms join, and can invert frequency (the relay
   `четвереньках` held the unranked sentinel 1,100,000 while its base
   `четвереньки` held rank 11,314 — and the relay even held a
   self-referential form row).
2. **The forms table could not resolve lookups alone.** Only ~14% of form
   rows matched their word's own `l_word`; en 1,507,795 / ru 29,171 words
   carried no self form at all. Every forms-join consumer (the frequency
   band lists, the two-pass entity linker, the popup's base-word groups)
   silently missed words whose inflections were all stored.
3. **Staged translations were a one-way ratchet.** `words.translations`
   JSON grew on every import (91,552 staged rows) and the only re-reader,
   `wiktionary:link-translations`, linked candidates without pruning
   them — a re-run would re-convert the same strings forever.
4. **Duplicate word rows.** 2,954 `unknown`-class twins of properly
   classed words (cmudict and entity adoption `firstOrCreate`d `unknown`
   rows beside classed same-`l_word` rows) and 7,244 `adj`/`adjective`
   duplicate en pairs (the kaikki parser minted a word class per raw dump
   slug, and kaikki emits both spellings) split every `l_word` lookup
   between two rows.

## Decision

Five rules — three data shapes, one shared toolkit, one creator contract:

1. **Form-of relays demote to forms of their base — at import and in
   place.** The verdict lives in the parser-agnostic
   `App\Classes\Wiktionary\FormOfClassifier` (decoded entry in → relay +
   base out): an entry is a relay iff every sense carries the sense-level
   `form_of`/`form-of` signal — the dump's exact mark, not gloss-pattern
   heuristics — and its base (`form_of[0].word`) normalizes to a lookup
   key distinct from the entry's own; mixed entries (some relay senses +
   some real glosses), senseless entries and self-relays stay words. The
   import attaches the relay headword to the base as a form (the base is
   created as a proxy headword when the dump never lists it as a lemma).
   The already-stored relay rows are repaired by
   `wiktionary:demote-form-of`: it streams the same dump, builds the exact
   relay→base map through the classifier plus the pure
   `App\Classes\Wiktionary\RelayDemotionMap` unions (a surface with any
   real dump entry stays a word; relay lines disagreeing on the base stay
   a word; chains close onto the terminal base; cycles demote nobody) and
   applies it set-based per chunk — translation links and `entity_words`
   re-point onto the base (canonical pairs deduped, self-pairs dropped),
   the relay's staged JSON union-merges into the base, the relay surface
   attaches as a form, its frequency rank folds into the base as **LEAST**
   (ranks: lower = more common, ADR
   [0070](0070-frequency-lists-min-merge.md) — never raises a rank), then
   the relay rows delete. Deliberately **not** a wipe + re-import: the run
   deleted ru 363,446 + en 500,776 relay rows (ru words 444,149 → 80,703,
   en 1,523,426 → 1,022,650) while preserving every link, entity token and
   familiarity row that pointed at them.
2. **Every word carries itself as its lemma form.** The shared
   `App\Support\LemmaForms` row builder — the word's stripped headword
   first, then the dictionary's inflected forms, under one hygiene (column
   cap, stress strip, empty-after-strip guard, `(form, word_id)` dedupe) —
   backs every importer, and `words:normalize` phase 4 backfilled the
   stored rows (1,018,396 en+ru forms; zero words missing their lemma form
   after). The two-pass lookups stay unchanged (ADR 0078's structure):
   lemma-as-form simply guarantees the forms pass always resolves.
3. **Translations follow one lifecycle: stage → link → prune.** Every
   writer stages unmatched candidates as the exact stored strings in
   `words.translations` (flat deduped list, union-merge — never clobber,
   `App\Support\StagedTranslations`); `dictionary:link-translations`
   converts staged strings into canonical `word_translations` rows for
   every language pair and **prunes exactly what it linked** (emptied JSON
   → null), so re-runs are idempotent and staging shrinks monotonically.
   The popup auto-fetch stages its unmatched provider candidates into the
   same JSON and records the outcome on its ledger row
   (`linked_count`/`staged_count`). Run: 126,202 staged candidates
   converted into links; staged JSON 91,552 → 52,472 rows.
4. **One resolver, one alias table, one lemma rule.** All link-only
   writers — the GoldenDict bilingual import, the popup auto-fetch, the
   link pass, and the demote/normalize repair commands' link re-pointing —
   resolve targets through the shared `App\Support\TranslationTargets`
   semantics (same class as the source word, `phrase` for multi-word, else
   any class, lowest id); every pos canonicalizes through the shared
   `App\Support\PosAliases` table before any word class is minted; every
   form upsert goes through `LemmaForms`. No writer keeps a private copy
   of any of the three.
5. **Creators attach to existing rows.** cmudict, entity adoption and the
   Filament manual creators look the language's existing `l_word`s up
   before minting — a match attaches (the cmudict transcription lands on
   the rows already there; an adopted token stamps the existing row), only
   a genuinely new surface creates a row, and a new row stores plain
   display + `WordLookupKey` key + lemma form. `words:normalize` repairs
   the stored damage idempotently in four phases (stress-strip merges,
   unknown-twin merges, alias-class fold, lemma backfill): run totals
   2,451 twins merged, 7,514 alias duplicates merged, 216,440 rows
   reclassed, 12 orphan alias class rows deleted, 9,976 rows absorbed.
   Case-variant same-class duplicates stay unmerged by design
   (March/march can be genuinely different words).

## Consequences

- The repair commands are dump-scale batch jobs: both
  `wiktionary:demote-form-of` and `words:normalize` raise their own
  `memory_limit` to 2G (the in-memory relay map and merge maps hold
  full-language row sets — the 128M CLI default OOM-killed the first real
  runs), expect tens of minutes each, and are run `--dry` first (the
  runbook in `wiki/playbooks/import-dictionary-data.md`; pipeline order:
  demote → normalize → link-translations → frequency → crossword:link).
- Relay demotion is dump-driven on purpose: a database-only heuristic
  (relay-gloss patterns) misfires on mixed entries and on language-mismatched
  glosses, while the dump's sense-level signal is exact. Bases the dump
  never lists stay missing, and their relays keep existing as words —
  counted and sampled per run.
- `word_translations` can legitimately shrink: the repair dedupe collapsed
  5,927 redundant pairs while the link pass added ~4,898 genuinely new
  (net −1,029 in the run). Growth is measured at the candidate→link level
  (126,202 conversions), not the raw table count.
- Frequency folds are LEAST everywhere a rank moves between rows (relay
  demotion, normalize merges, the band-list import) — lower = more common;
  the unranked sentinel 1,100,000 never demotes a ranked row, and the 0
  column default on cmudict/adoption rows is guarded out of folds.
- The `WordFamily` read-layer relay-gloss defense stays — harmless once
  the relays are gone, and a safety net for any relay imported before the
  rule or from a future source.
- Data gates pinned the run (all passing): zero combining marks in ru
  `words.word`/`forms.form`; `четвереньках` only a form of `четвереньки`;
  zero unknown-class twins and zero `adj` class rows; zero words missing
  their lemma form.
