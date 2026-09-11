---
type: Pipeline
title: Dictionary Import
description: Parsing Kaikki/Wiktionary dumps into the unified language-keyed dictionary tables and linking translations across every language pair.
tags: [dictionary, import, wiktionary, kaikki]
status: stable
stale_after: 2026-12-10
generated: { by: agent:zcode, at: 2026-09-11T00:00:00Z }
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
  by `language_id`. `--lang`/`--target-lang` accept **any language code
  present in the languages registry** (`is_enabled` is irrelevant — it gates
  user-facing surfaces, not CLI data steps); unknown codes fail with the
  available registry codes listed. Translations are **stored, not linked**,
  during import.
* `php artisan wiktionary:link-translations` — links words across languages
  through the stored translations into `word_translations` rows, covering
  **every ordered language pair** among languages that have imported words
  (stress-mark stripping applied when pairing into Russian).

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
  with relation managers for definitions, pronunciations, translations,
  etymologies, examples, transcriptions, plus **Word class** and
  **Transcription type** resources for CRUD over the lookup tables.

# Operating it

Step-by-step: [Importing Dictionary Data](/playbooks/import-dictionary-data.md).
