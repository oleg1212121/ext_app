---
type: Database Schema
title: Schema Overview
description: The three table domains — legacy vocabulary, EN/RU dictionary, entities & alignment — and how they relate.
tags: [database, schema, postgres]
status: stable
stale_after: 2026-10-26
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
sources:
  - id: migrations
    resource: laravel/database/migrations
    title: Migration files (chronological source of truth)
  - id: models
    resource: laravel/app/Models
    title: Eloquent models
---

# Engine & access

PostgreSQL (`ext_pgdb`, host port 54321). Dev DB `ext_app`, test DB
`ext_app_test`. Migrations in `laravel/database/migrations/` (46 files) are the
chronological source of truth; there is also a large legacy dump
(`backup11.sql`, ~545 MB) at the repo root.

# The three domains

| Domain | Era | Tables | Detail |
|--------|-----|--------|--------|
| [Legacy vocabulary](legacy-vocabulary.md) | 2025_07–09 | `words`, `books`, `book_word`, `definitions`, `etymologies`, `transcriptions`, `translations`, `forms`, `saved_phrases` | Original generic dictionary; still referenced by legacy UI and `Word` model/Filament resource |
| [EN/RU dictionary](en-ru-dictionary.md) | 2026_04 | mirrored `en_words`/`ru_words` + satellites, `tags` | Current dictionary, filled by [Dictionary Import](/domains/dictionary-import.md) |
| [Entities & alignment](entities-alignment.md) | 2026_04–06 | `*_entities`, `*_entity_sentences`, `*_meaning_matches`, `en_ru_translations`/`ru_en_translations` | Texts and their alignment; filled by the [alignment pipeline](/domains/sentence-alignment.md) |

Plus Laravel framework tables: `users`, `cache`, `jobs` (0001_01_01_*).

# How they relate

* Entities/sentences reference the languages' dictionary words at the UI layer
  (dictionary lookups while reading), not via hard FK everywhere — check models
  before assuming joins.
* `en_ru_translations` / `ru_en_translations` bridge the dictionary and
  alignment domains (word-level links vs sentence-level matches).
* The legacy domain coexists with the EN/RU dictionary; when adding features,
  prefer the EN/RU tables and confirm which domain a given controller/model
  actually uses.
