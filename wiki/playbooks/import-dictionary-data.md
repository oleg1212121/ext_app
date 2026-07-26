---
type: Playbook
title: Importing Dictionary Data
description: How to import Kaikki/Wiktionary dumps into the EN/RU dictionary tables and link translations.
tags: [dictionary, import, wiktionary, kaikki]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
sources:
  - id: import-cmd
    resource: laravel/app/Console/Commands/ImportWiktionaryCommand.php
    title: wiktionary:import command
  - id: link-cmd
    resource: laravel/app/Console/Commands/LinkTranslationsCommand.php
    title: wiktionary:link-translations command
  - id: kaikki
    resource: laravel/app/Classes/KaikkiParser.php
    title: Kaikki JSONL parser
  - id: wiktionary
    resource: laravel/app/Classes/WiktionaryParser.php
    title: Wiktionary parser
---

# Overview

Dictionary content comes from [kaikki.org](https://kaikki.org) machine-readable
Wiktionary dumps (JSONL, one JSON object per line). Import is a two-phase
process: parse dumps into the mirrored EN/RU tables, then link translations
between them. Target tables are described in
[EN/RU Dictionary](/database/en-ru-dictionary.md).

# Steps

1. Download a Kaikki JSONL dump for the language (e.g. `en-wiktionary` or
   `ru-wiktionary` extract) onto the host.
2. Make it visible in the container (anything under `laravel/` is mounted;
   e.g. put it in `laravel/storage/app/`).
3. Run the import:

   ```bash
   docker exec ext_app_laravel php artisan wiktionary:import storage/app/<file>.jsonl
   ```

   Translations found in the dump are **stored for later linking**, not
   resolved during import (per the command description).
4. Link EN↔RU words through the stored translations:

   ```bash
   docker exec ext_app_laravel php artisan wiktionary:link-translations
   ```

   Matching strips Russian stress marks (e.g. `приве́т` → `привет`) when
   pairing words.
5. Verify in the Filament admin (`/admin`): `EnWord` / `RuWord` resources with
   relation managers for definitions, pronunciations, translations,
   etymologies, examples, transcriptions.

# Notes

* Parsers: `App\Classes\KaikkiParser` (primary) and `WiktionaryParser`, behind
  `App\Classes\Parser`. Read `ImportWiktionaryCommand` for the exact CLI
  signature/options before running large files.
* Dumps are large; imports are long-running — prefer running via
  `docker exec -d` or in a separate shell, and watch memory.
