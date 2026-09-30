---
type: Database Schema
title: Unified Dictionary Tables
description: One words table (+ satellites) keyed by language, per-language word classes and transcription types, a symmetric word_translations pivot (one row per pair), and the word_translation_fetches ledger driving auto-fetched translations (Yandex→Google) that doubles as the exclusions list.
tags: [database, schema, dictionary, words]
status: stable
stale_after: 2026-12-27
generated: { by: agent:zcode, at: 2026-09-29T19:54:00+03:00 }
sources:
   - id: migration
     resource: laravel/database/migrations/2026_09_10_000005_create_dictionary_tables.php
     title: Unified dictionary creation (squashed baseline)
   - id: fetch-migration
     resource: laravel/database/migrations/2026_09_29_000001_create_word_translation_fetches_table.php
     title: Auto-fetch ledger creation
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
| `forms` / `definitions` / `etymologies` / `examples` | Per-word satellites (unique `(form, word_id)` / `(example, word_id)`); `forms` carries `l_word` (indexed, `idx_forms_l_word`) and is the runtime link target for inflected tokens — the linker's forms pass in [Crossword](/domains/crossword.md) — and the source of the Word popup's base-word groups ([Interactive words](/domains/interactive-words.md), ADR 0045) |
| `transcriptions` | Written phonetic notations per word + `transcription_type_id` (ipa, enpr, …; unique triple) |
| `pronunciations` | Audio files with pronunciation examples per word (`path` on the public disk, unique `(path, word_id)`); uploaded via the admin |
| `tags` / `word_tags` | Word tags (e.g. most-used) and the pivot |
| `word_translations` | **One row per word pair** (symmetric, ADR 0020): `word_a_id`, `word_b_id` with canonical order `a < b` (entity-match convention), unique `(word_a_id, word_b_id)`, index on `word_b_id`. A link is usable from either word; links connect words of **different languages** only (enforced in the admin attach action). Supersedes the directed pivot of ADR 0018 |
| `word_translation_fetches` | Auto-fetch ledger, one row per `(word_id, target_language_id)` (unique): `provider` (yandex/google), `status` (`pending`/`succeeded`/`empty`/`failed`), `attempts`, `last_attempted_at` (migration `2026_09_29_000001`). `status = 'empty'` is the **exclusions list** — every provider answered "no translation", so the pair is never looked up again |

# Notes

* The popup aggregates the **word family** per surface token
  (`WordFamily::resolve`, ADR 0045): the linked headword's group plus every
  base headword's group from `forms`. Kaikki's "form-of" entries are stored
  as ordinary words whose definitions relay to the base word ("simple past
  and past participle of melt", sometimes merged into a real entry —
  "saw/verb" carries real senses *and* "simple past of see"); at popup time
  such **relay glosses** are recognized by an anchored pattern list in
  `WordFamily` and hidden/filtered whenever a base group carries the real
  content. Escape hatch if patterns misfire: an `is_form_of` column set at
  import + backfill.
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
* **Auto-fetched translations** (2026-09-29): when a popup word family has no
  translation link at all, `WordController::show` quietly queues
  `FetchWordTranslations` through the
  `WordTranslationFetchService::dispatchIfEligible` gate; the job walks the
  `WordTranslationResolver` chain — Yandex Cloud Dictionary Lookup first
  (dictionary-grade candidates with parts of speech), Google Translate v2 as
  a single-candidate fallback (`config/services.php`
  `yandex_translate`/`google_translate`, both optional) — then creates the
  target-language `words` rows (class from the candidate pos slug, else the
  source word's class slug mapped into the target language, else the target's
  first class; ru stress marks stripped; sentence-punctuation candidates
  dropped) and the `word_translations` links. `failed` lookups retry up to
  `MAX_ATTEMPTS` (6) behind a 24h cooldown; `empty` never re-checks. The
  `EntityWordAdoption` pass (the 5-minute word-list refresh, or
  `words:adopt-from-entities`) feeds the same pipeline with entity tokens —
  see [Interactive words](/domains/interactive-words.md) and
  [Crossword](/domains/crossword.md).
* The legacy 2025 vocabulary domain (`words` in the old shape, `books`,
  `book_word`, `saved_phrases`) was deleted with the 2026-09 rework.
* `words.frequency` is populated by `words:import-frequency {source}`
  (local `rank,word` CSV or named download — OpenSubtitles 2018 for en,
  RNC lemmas for ru) and then nudged per entity by
  `WordFrequencyAccrual` — semantics, sources and the correction math in
  ADR [0041](../../docs/adr/0041-frequency-rank-semantics-and-entity-correction.md)
  and [Crossword](/domains/crossword.md).
