---
type: Database Schema
title: Unified Dictionary Tables
description: One words table (+ satellites) keyed by language, per-language word classes and transcription types, and a symmetric word_translations pivot (one row per pair).
tags: [database, schema, dictionary, words]
status: stable
stale_after: 2026-12-25
generated: { by: agent:zcode, at: 2026-09-25T00:00:00Z }
sources:
   - id: migration
     resource: laravel/database/migrations/2026_09_10_000005_create_dictionary_tables.php
     title: Unified dictionary creation (squashed baseline)
   - id: importer
     resource: laravel/app/Classes/WiktionaryParser.php
     title: Wiktionary (Kaikki) import writer
---

# Tables

| Table | Role |
|-------|------|
| `word_classes` | Parts of speech **per language** (`language_id`, `slug`, `title`, unique `(language_id, slug)`); seeded for en/ru |
| `transcription_types` | Transcription kinds per language (ipa, enpr for English; МФА for Russian) |
| `words` | A base-form word in one language: `language_id`, `word`, `l_word` (lowercase), `frequency` (rank: lower = more common, `numeric(12,2)`; 1,100,000 = unranked, `Word::FREQUENCY_UNRANKED` — see ADR 0041), `word_class_id`, raw Wiktionary `translations` JSON. Unique `(word, language_id, word_class_id)`; index `(l_word, word_class_id)` |
| `forms` / `definitions` / `etymologies` / `examples` | Per-word satellites (unique `(form, word_id)` / `(example, word_id)`); `forms` carries `l_word` (indexed, `idx_forms_l_word`) and is the runtime link target for inflected tokens — see the linker's forms pass in [Crossword](/domains/crossword.md) |
| `transcriptions` | Written phonetic notations per word + `transcription_type_id` (ipa, enpr, …; unique triple) |
| `pronunciations` | Audio files with pronunciation examples per word (`path` on the public disk, unique `(path, word_id)`); uploaded via the admin |
| `tags` / `word_tags` | Word tags (e.g. most-used) and the pivot |
| `word_translations` | **One row per word pair** (symmetric, ADR 0020): `word_a_id`, `word_b_id` with canonical order `a < b` (entity-match convention), unique `(word_a_id, word_b_id)`, index on `word_b_id`. A link is usable from either word; links connect words of **different languages** only (enforced in the admin attach action). Supersedes the directed pivot of ADR 0018 |

# Notes

* Runtime consumers now exist: `WordController` serves the word popup
  (`GET /words/{word}`, definitions/transcriptions/native-first
  translations/examples) and reads/writes `user_word` progress on behalf of
  the reader and simulator surfaces — see
  [Interactive words](/domains/interactive-words.md). The crossword was the
  first consumer; Filament admin
  (`WordResource` + relation managers, `WordClassResource`,
  `TranscriptionTypeResource`) and the import/link commands remain.
* `wiktionary:import {file} --lang= --target-lang=` fills `words` and
  satellites for one language (any code in the languages registry),
  storing staged translations as JSON; `wiktionary:link-translations` then
  resolves them into `word_translations` rows for **every language pair**
  (one canonical row per pair; stress-mark stripping applied when the
  target is Russian). The admin **Translations** tab continues the linking
  by hand: attach an existing word, or **Create word & link** for words
  missing from the target language.
* The import auto-creates missing per-language lookups: an unseen dump
  `pos` becomes a `word_classes` row and an unseen sound type a
  `transcription_types` row, both with the slug as placeholder `title` —
  nothing is skipped for a missing lookup, so a new language needs no
  seeders. The seeders remain the source of curated en/ru titles.
* `l_word` keys (words and forms) are lowercased **and stripped of
  combining marks** — Russian Wiktionary headwords carry stress marks
  (`свобо́дный`), and every matcher (the entity-word linker's exact pass,
  `words:import-frequency`) compares against unstressed text. The display
  `word` keeps its marks.
* The legacy 2025 vocabulary domain (`words` in the old shape, `books`,
  `book_word`, `saved_phrases`) was deleted with the 2026-09 rework.
* `words.frequency` is populated by `words:import-frequency {source}`
  (local `rank,word` CSV or named download — OpenSubtitles 2018 for en,
  RNC lemmas for ru) and then nudged per entity by
  `WordFrequencyAccrual` — semantics, sources and the correction math in
  ADR [0041](../../docs/adr/0041-frequency-rank-semantics-and-entity-correction.md)
  and [Crossword](/domains/crossword.md).
