---
type: Pipeline
title: Dictionary Import
description: Parsing Kaikki/Wiktionary dumps into the unified language-keyed dictionary tables and linking translations across every language pair.
tags: [dictionary, import, wiktionary, kaikki]
status: stable
stale_after: 2026-12-16
generated: { by: agent:zcode, at: 2026-09-16T12:00:00Z }
sources:
  - id: wiktionary
    resource: laravel/app/Classes/WiktionaryParser.php
    title: Kaikki/Wiktionary JSONL parser
  - id: extractor
    resource: laravel/app/Classes/RawWiktextractExtractor.php
    title: Raw dump per-language extractor
  - id: import-cmd
    resource: laravel/app/Console/Commands/ImportWiktionaryCommand.php
    title: wiktionary:import
  - id: import-raw-cmd
    resource: laravel/app/Console/Commands/ImportRawWiktextractCommand.php
    title: wiktionary:import-raw
  - id: link-cmd
    resource: laravel/app/Console/Commands/LinkTranslationsCommand.php
    title: wiktionary:link-translations
---

# What it is

The import pipeline that fills the [unified dictionary](
/database/dictionary.md) tables from machine-readable Wiktionary data
(kaikki.org JSONL dumps). Since ADR
[0018](../../docs/adr/0018-works-and-unified-language-keyed-tables.md) there
is one language-keyed `words` table (+ satellites) per language instead of
mirrored per-language tables, and a single `word_translations` pivot —
**one symmetric row per word pair** since ADR
[0020](../../docs/adr/0020-symmetric-word-translations.md).

# Components

* `App\Classes\WiktionaryParser` — parses kaikki.org JSONL dumps into the
  unified tables (the former separate `KaikkiParser` was folded into it).
  Reads plain `.jsonl` and, transparently, gzip `.jsonl.gz` via the
  `compress.zlib://` wrapper. Staging targets can be a single target code or
  a list (`new WiktionaryParser($lang, ['ru', 'de'])`) — see
  [ADR 0029](../../docs/adr/0029-import-raw-dump-via-per-language-extraction.md).
* `App\Classes\RawWiktextractExtractor` — streams a **Raw dump** (the
  monolithic kaikki `raw-wiktextract-data` file, every Wiktionary language
  interleaved) once and writes one **Language extract** per wanted code: raw
  lines kept verbatim, next to the dump (`<base>.<code>.jsonl`). A substring
  pre-filter skips `json_decode` for lines that cannot match; the decoded
  top-level `lang_code` decides (nested `lang_code` occurrences inside
  translation/template data are false positives). Memory stays flat — one
  line at a time — and every 100k lines a heartbeat (lines, kept counts, RSS,
  elapsed) goes to the console and the log channel for detached runs.
* `php artisan wiktionary:import {file} --lang= --target-lang=` — parses a
  dump into the unified `words` table (+ definitions, forms, transcriptions
  (+types), etymologies, pronunciations, examples, word classes, tags) keyed
  by `language_id`. `--lang` accepts any registry code; `--target-lang`
  accepts **comma-separated** registry codes. `is_enabled` is irrelevant —
  it gates user-facing surfaces, not CLI data steps; unknown codes fail with
  the available registry codes listed. Translations are **stored, not
  linked**, during import.
* `php artisan wiktionary:import-raw {file} --langs=en,ru` — the raw-dump
  orchestrator ([ADR 0029](../../docs/adr/0029-import-raw-dump-via-per-language-extraction.md)):
  extract → import each language (symmetric staging: every imported language
  stages every other; override with `--target-langs`) → link translations
  (`--no-link` to skip). Also: `--fresh` (wipe the selected languages'
  dictionary data first — cascades user familiarity rows and resets
  `entity_words` links; requires `--force` when STDIN is not a terminal),
  `--extract-only`, `--skip-extract`, `--batch-size=`, `--max-lines=` (smoke
  runs). Extract files live next to the dump and are overwritten per run;
  re-imports upsert and text-dedupe, never delete — hence `--fresh` for a
  clean rebuild from a newer dump.
* `php artisan wiktionary:link-translations` — links words across languages
  through the stored translations into `word_translations` rows, covering
  **every language pair** among languages that have imported words (one
  canonical row per pair, `word_a_id < word_b_id`; stress-mark stripping
  applied when pairing into Russian). Re-runs are idempotent next to
  manually created links. `wiktionary:import-raw` runs it automatically at
  the end (unless `--no-link`).

# Performance notes

* `definitions`/`etymologies` carry non-unique indexes on `word_id`
  (`idx_definitions_word_id`, `idx_etymologies_word_id`): the parser's
  `insertNewOnly()` runs `whereIn('word_id', …)` per batch and would
  full-scan once these tables hold millions of rows.
* The parser batch-flushes (default 500, `--batch-size`) inside a
  transaction and force-flushes when RSS exceeds 100MB; `import-raw` raises
  the CLI `memory_limit` to 1G because single decoded lines (e.g. the entry
  for "the", hundreds of KB of JSON) can spike past the 128M CLI default.
* On a machine with ordinary NVMe the full raw dump extracts in tens of
  minutes; the en import is the long pole (tens of millions of lines) — run
  detached and watch the heartbeats in `storage/logs/laravel.log`.

# Lookup auto-creation

The parser never dead-ends on missing per-language lookups and never drops
data because of them: a dump `pos` with no matching **word class** (or a
sound type with no matching **transcription type**) is auto-created with the
slug as a placeholder `title`, and the word imports under it (the former
`words_skipped_pos` skip is gone). A fresh language therefore needs no
seeders — `INSERT` the language row, run the import, then curate the
placeholder titles in `/admin`. Import stats report the created lookups
(`lookups_created`).

# Where it surfaces

* Filament admin `/admin` (group "Words"): the per-language `WordResource`
  with relation managers for definitions, forms, translations, etymologies,
  examples, transcriptions, pronunciations, plus **Word class** and
  **Transcription type** resources for CRUD over the lookup tables. The
  **Translations** tab is the manual linking surface (attach / create word
  & link / delete, different languages only).

# Operating it

Step-by-step: [Importing Dictionary Data](/playbooks/import-dictionary-data.md).
