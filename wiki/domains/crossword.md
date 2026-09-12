---
type: Feature
title: Crossword
description: Deterministic crossword puzzles generated from an entity's word list, with frequency-band levels, dictionary-backed definitions/translations, and per-user word progress.
tags: [crossword, puzzles, inertia, react, dictionary]
status: stable
stale_after: 2026-12-12
generated: { by: agent:zcode, at: 2026-09-12T18:45:09Z }
sources:
  - id: controller
    resource: laravel/app/Http/Controllers/CrosswordController.php
    title: CrosswordController
  - id: generator
    resource: laravel/app/Classes/Crossword.php
    title: Grid generator (placement algorithm)
  - id: indexer
    resource: laravel/app/Classes/EntityWordIndexer.php
    title: EntityWordIndexer
  - id: levels
    resource: laravel/app/Classes/CrosswordLevel.php
    title: CrosswordLevel bands
  - id: routes
    resource: laravel/routes/web.php
    title: Routes
---

# What it is

A puzzle surface restored on the new works/entities schema (the 2025
crossword died with the legacy vocabulary domain — see ADR
[0018](../../docs/adr/0018-works-and-unified-language-keyed-tables.md) and
[0025](../../docs/adr/0025-crossword-word-index-and-progress.md)). The user
picks a readable [Entity](/database/entities-alignment.md) and a word
Level; the app builds the entity's word list, selects up to 30 dictionary
words from the level band not yet solved/known by the user, and lays them
out with the deterministic placement algorithm. A right panel shows
definitions, obsolete senses, translations (native language first), and
forms for the selected word.

# Routes

| Route | Handler | Purpose |
|-------|---------|---------|
| `GET /crossword` | `CrosswordController::index` | Inertia page (readable entities + levels), named `crossword` |
| `POST /crossword/generate` | `CrosswordController::generate` | Build a puzzle for `entity_id` + `level`; 403 when the entity is not readable, 422 when fewer than 3 band words exist |
| `POST /crossword/complete` | `CrosswordController::complete` | Mark the puzzle's `learning` words `solved` |
| `POST /crossword/word/know` | `CrosswordController::know` | Mark one word `known` |

# Pipeline

1. **Index** — `EntityWordIndexer` tokenizes `entity_sentences` (PHP
   `WordTokenizer`, Unicode regex) into `entity_words` (token, lowercase
   key, count) with `entities.words_indexed_at` staleness tracking.
   Rebuilt lazily on generate or by `crossword:index`.
2. **Link** — `crossword:link` back-fills `entity_words.word_id` by
   matching `(language_id, l_word)` on dictionary words (noun-first class
   priority). Run after `wiktionary:import`.
3. **Frequency** — `words:import-frequency {file} --lang=` upserts rank
   numbers onto `words.frequency` from `rank,word` CSVs
   (`database/frequency/`). Sample list committed for tests.
4. **Select + lay out** — deterministic `ORDER BY frequency, id LIMIT 30`
   excluding the user's solved/known words; `App\Classes\Crossword` places
   words on a virtual grid (same algorithm as the 2025 feature) and emits
   the typed-cell `newGrid` + `dictionary` JSON the React page consumes.

# Frontend

Inertia pages under `resources/js/Pages/Crossword/` — `Crossword` (wrapper,
`Main` layout), `CrosswordApp`, `useCrossword` (state: cell values, arrow
navigation, word checking, right-panel width), and `Components/` (grid,
cells, header with entity/level selects, right panel tabs, unsolved-words
modal). All strings come from the `crossword.*` UI strings. No runtime AI. Its grid/cell styling lives in `resources/css/crossword.css`, imported through the app stylesheet entry (`resources/css/app.css`) — the page has no stylesheet of its own.
