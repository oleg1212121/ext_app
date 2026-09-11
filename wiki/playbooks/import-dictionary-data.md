---
type: Playbook
title: Importing Dictionary Data
description: How to import Kaikki/Wiktionary dumps into the unified language-keyed dictionary tables and link translations.
tags: [dictionary, import, wiktionary, kaikki]
status: stable
stale_after: 2026-12-10
generated: { by: agent:zcode, at: 2026-09-11T00:00:00Z }
sources:
  - id: import-cmd
    resource: laravel/app/Console/Commands/ImportWiktionaryCommand.php
    title: wiktionary:import command
  - id: link-cmd
    resource: laravel/app/Console/Commands/LinkTranslationsCommand.php
    title: wiktionary:link-translations command
  - id: kaikki
    resource: laravel/app/Classes/WiktionaryParser.php
    title: Kaikki/Wiktionary JSONL parser
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

# Steps

1. Download a Kaikki JSONL dump for the language (e.g. `en-wiktionary` or
   `ru-wiktionary` extract) onto the host.
2. Make it visible in the container (anything under `laravel/` is mounted;
   e.g. put it in `laravel/storage/app/`).
3. Ensure the language exists in the languages registry (Filament
   `/admin` → Languages, or the seeder). `--lang`/`--target-lang` accept
   any registry code; `is_enabled` is not required for import.
4. Run the import:

   ```bash
   docker exec ext_app_laravel php artisan wiktionary:import storage/app/<file>.jsonl --lang=en --target-lang=ru
   ```

   `--lang` (source language) fills the unified `words` table and its
   satellites for that language. Translations found in the dump are
   **stored for later linking**, not resolved during import (per the
   command description). Missing per-language lookups are **auto-created**
   (unseen dump `pos` → word class, unseen sound type → transcription
   type, slug as placeholder title) — nothing is skipped, and a brand-new
   language needs no seeders; curate the placeholder titles in `/admin`
   afterwards.
5. Link words across languages through the stored translations:

   ```bash
   docker exec ext_app_laravel php artisan wiktionary:link-translations
   ```

   The command links **every ordered language pair** among languages that
   have imported words, writing directed `word_translations` rows. Matching
   strips Russian stress marks (e.g. `приве́т` → `привет`) when pairing into
   Russian.
6. Verify in the Filament admin (`/admin`, group "Words"): the `Word`
   resource with relation managers for definitions, pronunciations,
   translations, etymologies, examples, transcriptions; the **Word class**
   and **Transcription type** resources show (and let you edit) the
   auto-created lookups.

# Notes

* Parser: `App\Classes\WiktionaryParser` (reads the kaikki.org JSONL format
  directly — the former separate `KaikkiParser` was folded into it). Read
  `ImportWiktionaryCommand` for the exact CLI
  signature/options before running large files.
* Dumps are large; imports are long-running — prefer running via
  `docker exec -d` or in a separate shell, and watch memory.
