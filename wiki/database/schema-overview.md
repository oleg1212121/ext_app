---
type: Database Schema
title: Schema Overview
description: The table domains — works/entities/alignment, unified dictionary, AI catalog, user settings — and how they relate.
tags: [database, schema, postgres, users, settings]
status: stable
stale_after: 2026-12-14
generated: { by: agent:zcode, at: 2026-09-14T12:00:00Z }
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
| [Crossword](../domains/crossword.md) | `entity_words` (token-first per-entity word list, nullable `word_id` link), `user_word` (per-user `familiarity` 0–100 exposure score), `user_word_event` (read/lookup idempotency ledger: user × word × row_key × kind unique; ADR [0028](../../docs/adr/0028-numeric-word-familiarity.md)), `entities.words_indexed_at` staleness marker, `words.frequency` ranks. See ADR [0025](../../docs/adr/0025-crossword-word-index-and-progress.md). The same tables power [Interactive words](../domains/interactive-words.md) — no position/occurrence tables exist (ADR [0027](../../docs/adr/0027-render-time-word-segmentation.md)); `forms` carries the runtime-read `l_word` index |
| AI catalog | `ai_providers`, `ai_models`, `user_api_keys` (2026_09_10_000002) |
| Prompt templates | `prompt_templates` (2026_09_24_000001): seeded admin-editable AI prompt texts keyed `simulator.question.format` / `simulator.question.tasks` (`:base`/`:learning` — assessment question) and `word.explanation` (`:word`/`:native` — Context explanation); placeholders substituted server-side (ADR [0040](../../docs/adr/0040-server-assembled-assessment-question.md)) |
| Users & settings | `users` (role/approval inline), `user_settings` (native + interface language) |
| [Localization](../domains/localization.md) | `ui_string_keys` (dotted key, group), `ui_strings` (one text per interface-enabled language); `languages.is_interface_enabled` gates pickers |

Plus Laravel framework tables: `cache`, `jobs` (0001_01_01_*).

The legacy vocabulary domain (`words`/`books`/`book_word`/`saved_phrases` +
satellites, the 2025 crossword/word-interaction era) was **deleted** in the
same rework — its routes, controllers, Filament resources and React pages are
gone.

# User settings

`user_settings` (one row per user) holds per-user preferences: the **native
language** (`native_language_id` → `languages.id`, nullable, English by
default), the **interface language** (`interface_language_id`, nullable —
null follows the native language; only `languages.is_interface_enabled`
languages are valid), and **UI settings** (`ui_settings`, nullable JSONB —
per-section blobs keyed `simulator` / `reader`: font sizes, panel visibility,
selected AI model, customized assessment task list, panel drag sizes). Created
at registration; the language fields are changeable from the profile page
(Inertia `Profile/Edit`) and admin-managed via the language selects on the
`UserResource` create/edit forms (the former standalone `UserSettingsResource`
was removed 2026-09-12). The UI locale resolves interface → native → `en`
(ADR 0023); UI settings are seeded into Inertia props by
`SimulatorController`/`ReaderController` and written back by a debounced PATCH
to `/ui-settings` (ADR 0024). See the
[Access Control domain](../domains/access-control.md) for the user, and the
**User settings** / **Native language** glossary entries in
[CONTEXT.md](../../CONTEXT.md#language-catalog-context).

# UI strings

`ui_string_keys` (`key` unique dotted identifier, `group` = first segment,
derived on save) + `ui_strings` (`ui_string_key_id`, `language_id`, `text`,
unique pair) store interface text per interface-enabled language (ADR 0022).
Filament `UiStringKeyResource` edits them side by side; `UiStringLoader`
serves them to the translator and `UiStrings::mapFor()` to Inertia, both
cache-backed and flushed by model observers. Seed content lives in
`database/seeders/ui-strings/*.php`, upserted by `UiStringSeeder`.

# How they relate

* `works` group the per-language `entities`; `entity_matches` pair two
  same-work entities (any languages — same-language companions like
  exercises + answers included; canonical `a_entity_id <
  b_entity_id`). The work's `original_language_id` decides which side of a
  match is the original — there is no per-match original flag.
* `word_translations` links dictionary words across languages at the word
  level, independent of the sentence-level alignment domain.
* All previously mirrored tables (`en_*`/`ru_*`) are unified with a
  `language_id` column; cross-language joins are ordinary FKs.
