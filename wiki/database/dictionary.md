---
type: Database Schema
title: Unified Dictionary Tables
description: One words table (+ satellites) keyed by language, per-language word classes and transcription types, and a single directed word_translations pivot.
tags: [database, schema, dictionary, words]
status: stable
stale_after: 2026-12-10
generated: { by: agent:zcode, at: 2026-09-11T00:00:00Z }
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
| `words` | A base-form word in one language: `language_id`, `word`, `l_word` (lowercase), `frequency`, `word_class_id`, raw Wiktionary `translations` JSON. Unique `(word, language_id, word_class_id)`; index `(l_word, word_class_id)` |
| `forms` / `definitions` / `etymologies` / `examples` | Per-word satellites (unique `(form, word_id)` / `(example, word_id)`) |
| `transcriptions` / `pronunciations` | Per-word + `transcription_type_id` (unique triples) |
| `tags` / `word_tags` | Word tags (e.g. most-used) and the pivot |
| `word_translations` | **One directed pivot for all language pairs**: `from_word_id`, `to_word_id`, unique `(from, to)`, index on `to_word_id`. Replaces the mirrored `en_ru_translations` / `ru_en_translations` |

# Notes

* Nothing at runtime reads these tables yet — only Filament admin
  (`WordResource` + relation managers, `WordClassResource`,
  `TranscriptionTypeResource`) and the import/link commands.
* `wiktionary:import {file} --lang= --target-lang=` fills `words` and
  satellites for one language (any code in the languages registry),
  storing raw translations as JSON; `wiktionary:link-translations` then
  resolves them into `word_translations` rows for **every ordered language
  pair** (stress-mark stripping applied when the target is Russian).
* The import auto-creates missing per-language lookups: an unseen dump
  `pos` becomes a `word_classes` row and an unseen sound type a
  `transcription_types` row, both with the slug as placeholder `title` —
  nothing is skipped for a missing lookup, so a new language needs no
  seeders. The seeders remain the source of curated en/ru titles.
* The legacy 2025 vocabulary domain (`words` in the old shape, `books`,
  `book_word`, `saved_phrases`) was deleted with the 2026-09 rework.
