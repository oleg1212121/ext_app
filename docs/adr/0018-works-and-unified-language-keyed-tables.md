# Works and unified language-keyed tables replace the mirrored EN/RU schema

The app grew out of a hardcoded EN/RU pair: mirrored `en_*`/`ru_*` tables
(entities, sentences, grants, words + six dictionary satellites each,
word-class and transcription-type lookups, two directed translation pivots),
pair-shaped alignment tables (`en_ru_entity_matches`,
`en_ru_meaning_matches`, per-side junction tables), and an `is_original_en`
boolean. We replaced all of it with a language-neutral schema: a `works`
table (title, author, `original_language_id`) grouping per-language `entities`,
a single `language_id` column on every previously mirrored table, one a/b
entity match chain with a `side` column on the sentence junction, and one
directed `word_translations(from_word_id, to_word_id)` pivot. Adding a
language is now an `INSERT` into `languages` plus UI labels — no DDL, no new
models, no new Filament resources.

**Status**: accepted — the directed `word_translations` pivot clause is
superseded by ADR 0020 (one row per pair)

## Considered Options

- **Unified language-keyed tables (chosen).** One `entities` table with
  `language_id`, one `entity_matches` table with canonical
  `a_entity_id < b_entity_id` ordering, one `sentence_meaning_matches`
  junction with a `side` char(1). The en/ru duplication had already spread
  over ~10 app files as `'en' => EnEntity::class` maps and `$lang === 'en'`
  ternaries, ~20 mirrored dictionary tables, and twin Filament resources —
  all of it growing linearly (satellites) or quadratically (pair tables) per
  new language.
- **Per-language mirrored tables** (keep cloning `de_entities`, `en_de_*`
  matches, …). Rejected: cross-language foreign keys become polymorphic and
  lose referential integrity; pair tables grow N×(N−1)/2; every language
  drags migrations + models + resources + controller maps; cross-language
  queries become UNIONs. Postgres serves per-language lookups from one
  `(language_id, …)`-indexed table as fast as from dedicated tables.
- **Postgres declarative partitioning** (`PARTITION BY LIST (language_id)`).
  Deferred: real physical separation with one logical table, but added ops
  complexity for no present need — the biggest table (Wiktionary words) is a
  few million rows. Revisit only if vacuum/index maintenance on dictionary
  imports becomes painful.
- **`is_original_en` kept per match vs. `works.original_language_id`.** The
  work-level column wins: it is the truth about the book (not about one
  pair), it survives when the original text is never uploaded (French novel,
  only EN+RU entities), and the aligner derives its repair side from it —
  when neither side is the original language, BOTH sides get skip rows and
  finalize repairs (translation↔translation pairs are first-class).

## Consequences

- Access grants stay **per-entity** (ADR 0013/0014/0015 unchanged): access to
  one entity of a work does not unlock its other translations.
- Multiple entities per language per work are allowed (competing
  translations, revised editions); `entities.label` tells them apart.
- The original side of a match is derived at runtime
  (`EntityMatch::originalSide()`), never stored.
- The migrations were **squashed to a fresh baseline** (6 consolidated
  migrations) and the legacy crossword/word-interaction vocabulary domain
  (`words`, `books`, `book_word`, `saved_phrases` + satellites and their
  routes/controllers/Filament resources) was deleted outright — dev data was
  disposable and prod is rebuilt fresh at the next deploy. After that deploy,
  rolling back code requires a DB restore.
- The Python `/align` contract renamed its field names
  (`en_sentences`/`ru_sentences` → `a_sentences`/`b_sentences`, spans
  `a_start`/`a_end`/`b_start`/`b_end`, `skip_a`/`skip_b`); the services
  deploy together, so no compatibility shim was kept.
