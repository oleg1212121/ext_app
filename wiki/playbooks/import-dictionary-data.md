---
type: Playbook
title: Importing Dictionary Data
description: How to import Kaikki/Wiktionary dumps into the unified language-keyed dictionary tables and link translations.
tags: [dictionary, import, wiktionary, kaikki]
status: stable
stale_after: 2026-12-16
generated: { by: agent:zcode, at: 2026-09-16T12:00:00Z }
sources:
  - id: import-cmd
    resource: laravel/app/Console/Commands/ImportWiktionaryCommand.php
    title: wiktionary:import command
  - id: import-raw-cmd
    resource: laravel/app/Console/Commands/ImportRawWiktextractCommand.php
    title: wiktionary:import-raw command
  - id: link-cmd
    resource: laravel/app/Console/Commands/LinkTranslationsCommand.php
    title: wiktionary:link-translations command
  - id: kaikki
    resource: laravel/app/Classes/WiktionaryParser.php
    title: Kaikki/Wiktionary JSONL parser
  - id: extractor
    resource: laravel/app/Classes/RawWiktextractExtractor.php
    title: Raw dump per-language extractor
---

# Overview

Dictionary content comes from [kaikki.org](https://kaikki.org) machine-readable
Wiktionary dumps (JSONL, one JSON object per line). Import is a two-phase
process: parse dumps into the unified language-keyed tables, then link
translations between them. Target tables are described in
[Unified Dictionary](/database/dictionary.md) — one `words` table keyed by
`language_id` (ADR
[0018](../../docs/adr/0018-works-and-unified-language-keyed-tables.md))
replaced the old mirrored per-language tables.

Two source shapes exist:

* **Raw dump** — `raw-wiktextract-data.jsonl.gz`, the whole English
  Wiktionary with every language interleaved (~2.7GB compressed, tens of
  millions of lines). Use `wiktionary:import-raw` (below).
* **Per-language download** — kaikki.org's pre-split
  `kaikki.org-dictionary-<Language>.jsonl` files. Use
  `wiktionary:import` directly (bottom of this page).

# Steps — raw dump (wiktionary:import-raw)

1. Download the dump onto the host and put it under `laravel/` (e.g.
   `laravel/kaikki/raw-wiktextract-data.jsonl.gz`) so the container sees it.
2. Ensure the languages exist in the languages registry (seeded en/ru, or
   Filament `/admin` → Languages). `--langs` accepts any registry code.
3. **Smoke-run first** — a capped extraction over the real gz validates
   everything end to end in minutes:

   ```bash
   docker exec ext_app_laravel sh -c 'cd /var/www && DB_CONNECTION=testing php artisan migrate:fresh --seed'
   docker exec ext_app_laravel sh -c 'cd /var/www && DB_CONNECTION=testing php artisan wiktionary:import-raw kaikki/raw-wiktextract-data.jsonl.gz --max-lines=50000'
   ```

4. Run the real import detached, redirecting output (multi-hour run):

   ```bash
   docker exec -d ext_app_laravel sh -c 'cd /var/www && php artisan wiktionary:import-raw kaikki/raw-wiktextract-data.jsonl.gz >> storage/logs/import-raw.log 2>&1'
   ```

   Phases: extract (writes `kaikki/raw-wiktextract-data.en.jsonl` and
   `.ru.jsonl` next to the dump, overwriting) → import each `--langs`
   language (symmetric translation staging by default) → link translations.
   Heartbeats every 100k lines (lines, kept counts, RSS, elapsed) land in
   `storage/logs/laravel.log`, so progress is observable even detached.
5. Useful flags: `--skip-extract` reuses existing extract files (e.g. rerun
   a failed import phase without a second decompression pass);
   `--extract-only` stops after the extract files; `--no-link` skips the
   linking pass; `--target-langs=ru` restricts staging to one direction;
   `--batch-size=500` tunes DB flush size.
6. **Clean rebuild** from a newer dump: add `--fresh --force` (non-interactive
   runs require `--force`). `--fresh` wipes the selected languages' words —
   FK cascades take definitions, forms, etymologies, transcriptions,
   translation links and **user familiarity rows** with them, and
   `entity_words.word_id` links reset (re-run `crossword:link` afterwards).
   Without `--fresh`, re-imports upsert and text-dedupe but never delete:
   glosses that changed between dump versions linger as extra definitions.
7. Verify in the Filament admin (`/admin`, group "Words") and hand-link the
   leftovers via a word's **Translations** tab (see the per-language flow
   below for the exact surfaces).

# Steps — per-language download (wiktionary:import)

1. Download the Kaikki JSONL dump for the language (e.g. `en-wiktionary` or
   `ru-wiktionary` extract) onto the host, anywhere under `laravel/`.
2. Ensure the language exists in the languages registry. `--lang` and
   `--target-lang` (comma-separated) accept any registry code;
   `is_enabled` is not required for import.
3. Run the import:

   ```bash
   docker exec ext_app_laravel php artisan wiktionary:import storage/app/<file>.jsonl --lang=en --target-lang=ru
   ```

   Missing per-language lookups are **auto-created** (unseen dump `pos` →
   word class, unseen sound type → transcription type, slug as placeholder
   title) — nothing is skipped, and a brand-new language needs no seeders;
   curate the placeholder titles in `/admin` afterwards.
4. Link words across languages through the staged translations:

   ```bash
   docker exec ext_app_laravel php artisan wiktionary:link-translations
   ```

   The command links **every language pair** among languages that have
   imported words, writing one canonical `word_translations` row per pair
   (`word_a_id < word_b_id`, ADR 0020). Matching strips Russian stress
   marks (e.g. `приве́т` → `привет`) when pairing into Russian. Re-runs are
   idempotent and never duplicate links created by hand in the admin.
5. Verify in the Filament admin (`/admin`, group "Words"): the `Word`
   resource with relation managers for definitions, forms, translations,
   etymologies, examples, transcriptions, pronunciations; the **Word
   class** and **Transcription type** resources show (and let you edit)
   the auto-created lookups.
6. Link what the command could not match by hand: on a word's edit page,
   the **Translations** tab offers **Attach translation** (pick an existing
   word in another language) and **Create word & link** (create the missing
   target word and link it in one step). Links work from either word.

# Notes

* Parser: `App\Classes\WiktionaryParser` (reads the kaikki.org JSONL format
  directly — the former separate `KaikkiParser` was folded into it; reads
  `.jsonl` and `.jsonl.gz`). Extractor:
  `App\Classes\RawWiktextractExtractor`. Read the commands for the exact
  signatures before running large files.
* Dumps are large; imports are long-running — prefer running via
  `docker exec -d` or in a separate shell, and watch the log heartbeats.
* Never start test-suite runs while an import writes to the same database.
