---
type: Database Schema
title: Legacy Vocabulary Tables
description: Original 2025 generic dictionary tables (words/books/definitions) kept for the legacy UI.
tags: [database, schema, legacy]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
sources:
  - id: migrations
    resource: laravel/database/migrations
    title: 2025_07–09 vocabulary migrations
---

# Tables

`words` (model `Word`), `books` (`Book`), `book_word` (pivot, `BookWord`),
`book_text_files` (`BookTextFile`), `definitions`, `etymologies`,
`transcriptions`, `translations`, `forms`, `saved_phrases` (`SavedPhrase`).

# Status

Legacy — superseded by the [EN/RU dictionary](en-ru-dictionary.md) for new
work, but still live:

* Filament resources exist for `Book`, `BookTextFile`, `Definition`, `Word`,
  `SavedPhrase`.
* `Info` model exists for miscellaneous app info.
* The legacy crossword/word endpoints and old Blade UI reference these tables.

# Guidance

When a task touches "words", determine first which domain the relevant code
path uses (check the model's `getTable()` in
[Models reference](/reference/models.md)). Prefer the EN/RU dictionary tables
for anything new.
