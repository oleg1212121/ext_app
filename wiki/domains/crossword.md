---
type: Feature
title: Crossword
description: Deterministic crossword puzzles generated from an entity's word list, with frequency-band levels, dictionary-backed definitions/translations, and per-user word progress.
tags: [crossword, puzzles, inertia, react, dictionary, queue]
status: stable
stale_after: 2026-12-13
generated: { by: agent:zcode, at: 2026-09-13T17:30:00Z }
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
  - id: linker
    resource: laravel/app/Classes/EntityWordLinker.php
    title: EntityWordLinker
  - id: refresh-job
    resource: laravel/app/Jobs/RefreshEntityWords.php
    title: RefreshEntityWords job
  - id: refresh-command
    resource: laravel/app/Console/Commands/RefreshEntityWordsCommand.php
    title: crossword:refresh sweep
  - id: levels
    resource: laravel/app/Classes/CrosswordLevel.php
    title: CrosswordLevel bands
  - id: routes
    resource: laravel/routes/web.php
    title: Routes
  - id: schedule
    resource: laravel/routes/console.php
    title: Scheduled commands
---

# What it is

A puzzle surface restored on the new works/entities schema (the 2025
crossword died with the legacy vocabulary domain — see ADR
[0018](../../docs/adr/0018-works-and-unified-language-keyed-tables.md) and
[0025](../../docs/adr/0025-crossword-word-index-and-progress.md)). The user
picks a readable [Entity](/database/entities-alignment.md) — grouped under
its Work in the header select, with a language filter across works — and a
word Level; the app builds the entity's word list, selects up to 30
dictionary words from the level band not yet solved/known by the user, and
lays them out with the deterministic placement algorithm. A right panel
shows definitions and translations (native language first) for the selected
word.

# Routes

| Route | Handler | Purpose |
|-------|---------|---------|
| `GET /crossword` | `CrosswordController::index` | Inertia page (readable entities grouped by work + languages + levels), named `crossword` |
| `POST /crossword/generate` | `CrosswordController::generate` | Build a puzzle for `entity_id` + `level`; 403 when the entity is not readable, 422 `crossword.still_building` when the word list is stale (built in the background), 422 `crossword.not_enough_words` when fewer than 3 band words exist |
| `POST /crossword/complete` | `CrosswordController::complete` | Mark the puzzle's `learning` words `solved` |

# Background word-list refresh

Scheduled every five minutes (`routes/console.php`, `withoutOverlapping`),
`crossword:refresh` picks entities that have sentences and either a stale
index (never built, or a sentence `updated_at` after
`entities.words_indexed_at`) or any unlinked `entity_words` row, and
dispatches one `RefreshEntityWords` queue job per entity
(`ShouldBeUnique` keyed by entity id; see ADR
[0026](../../docs/adr/0026-background-word-list-refresh.md)). The job
re-indexes when stale and always runs the link pass, then logs per-entity
stats. Dictionary and frequency imports therefore reach existing entities
without manual runs. Dev has no `schedule:work` — run
`php artisan crossword:refresh` by hand there.

# Pipeline

1. **Index** — `EntityWordIndexer` tokenizes `entity_sentences` (PHP
   `WordTokenizer`, Unicode regex) into `entity_words` (token, lowercase
   key, count) with `entities.words_indexed_at` staleness tracking.
   Rebuilt by the background refresh (or manually via `crossword:index`);
   generate no longer rebuilds inline.
2. **Link** — `EntityWordLinker` (shared by the background refresh and
   the `crossword:link` command) back-fills `entity_words.word_id` by
   matching `(language_id, l_word)` on dictionary words (noun-first class
   priority). Idempotent: only touches `word_id IS NULL` rows.
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
navigation, word checking, right-panel width, language filter), and
`Components/` (grid, cells, header with language-filter / work-grouped
entity / level selects, right panel with Definitions and Translations tabs
plus the unsolved-words modal). The work select renders one `<optgroup>` per
work with its readable entities as options (label appended when set); the
language select filters those options across all works and falls back to
"All languages". All strings come from the `crossword.*` UI strings. No
runtime AI. Its grid/cell styling lives in `resources/css/crossword.css`,
imported through the app stylesheet entry (`resources/css/app.css`) — the
page has no stylesheet of its own.
