---
type: Pipeline
title: Dictionary Import
description: Parsing Kaikki/Wiktionary dumps into the unified language-keyed dictionary tables and linking translations across every language pair.
tags: [dictionary, import, wiktionary, kaikki]
status: stable
stale_after: 2026-12-10
generated: { by: agent:zcode, at: 2026-09-10T00:00:00Z }
sources:
  - id: wiktionary
    resource: laravel/app/Classes/WiktionaryParser.php
    title: Kaikki/Wiktionary JSONL parser
  - id: import-cmd
    resource: laravel/app/Console/Commands/ImportWiktionaryCommand.php
    title: wiktionary:import
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
mirrored per-language tables, and a single directed `word_translations`
pivot.

# Components

* `App\Classes\WiktionaryParser` — parses kaikki.org JSONL dumps into the
  unified tables (the former separate `KaikkiParser` was folded into it).
* `php artisan wiktionary:import {file} --lang= --target-lang=` — parses a
  dump into the unified `words` table (+ definitions, forms, transcriptions
  (+types), etymologies, pronunciations, examples, word classes, tags) keyed
  by `language_id` (`--lang`, currently en/ru; `--target-lang` names the
  translation target recorded in the raw translations JSON). Translations
  are **stored, not linked**, during import.
* `php artisan wiktionary:link-translations` — links words across languages
  through the stored translations into `word_translations` rows, covering
  **every ordered language pair** among languages that have imported words
  (stress-mark stripping applied when pairing into Russian).

# Where it surfaces

* Filament admin `/admin`: the single per-language `WordResource` with
  relation managers for definitions, pronunciations, translations,
  etymologies, examples, transcriptions.

# Operating it

Step-by-step: [Importing Dictionary Data](/playbooks/import-dictionary-data.md).
