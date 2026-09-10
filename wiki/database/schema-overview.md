---
type: Database Schema
title: Schema Overview
description: The table domains — works/entities/alignment, unified dictionary, AI catalog, user settings — and how they relate.
tags: [database, schema, postgres, users, settings]
status: stable
stale_after: 2026-12-10
generated: { by: agent:zcode, at: 2026-09-10T00:00:00Z }
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
`ext_app_test`. The migrations in `laravel/database/migrations/` are a
**squashed fresh baseline** (2026-09: the 21 historical migrations were
consolidated into 6; the pre-squash history lives in git). Adding a language
is an `INSERT` into `languages` — never DDL (ADR
[0018](../../docs/adr/0018-works-and-unified-language-keyed-tables.md)).

# The domains

| Domain | Detail |
|--------|--------|
| [Entities & alignment](entities-alignment.md) | `works`, `entities` (+ `language_id`), `entity_sentences`, `entity_matches` (a/b sides), `meaning_matches`, `sentence_meaning_matches` (side column), `entity_user` grants. Filled by the [alignment pipeline](/domains/sentence-alignment.md) |
| [Dictionary](dictionary.md) | Unified `words` (+ `language_id`) with satellites, `word_classes`/`transcription_types` per language, one directed `word_translations` pivot. Filled by [Dictionary Import](/domains/dictionary-import.md) |
| AI catalog | `ai_providers`, `ai_models`, `user_api_keys` (2026_09_10_000002) |
| Users & settings | `users` (role/approval inline), `user_settings` (native language) |

Plus Laravel framework tables: `cache`, `jobs` (0001_01_01_*).

The legacy vocabulary domain (`words`/`books`/`book_word`/`saved_phrases` +
satellites, the 2025 crossword/word-interaction era) was **deleted** in the
same rework — its routes, controllers, Filament resources and React pages are
gone.

# User settings

`user_settings` (one row per user) holds per-user preferences, currently a single
**native language** (`native_language_id` → `languages.id`, nullable, English by
default). Created at registration; changeable from the profile page (Inertia
`Profile/Edit`) and admin-managed via the Filament `UserSettingsResource`, plus a
native-language select on the `UserResource` create/edit forms. See the
[Access Control domain](../domains/access-control.md) for the user, and the
**User settings** / **Native language** glossary entries in
[CONTEXT.md](../../CONTEXT.md#language-catalog-context).

# How they relate

* `works` group the per-language `entities`; `entity_matches` pair two
  same-work entities in different languages (canonical `a_entity_id <
  b_entity_id`). The work's `original_language_id` decides which side of a
  match is the original — there is no per-match original flag.
* `word_translations` links dictionary words across languages at the word
  level, independent of the sentence-level alignment domain.
* All previously mirrored tables (`en_*`/`ru_*`) are unified with a
  `language_id` column; cross-language joins are ordinary FKs.
