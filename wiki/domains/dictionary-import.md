---
type: Pipeline
title: Dictionary Import
description: Parsing Kaikki/Wiktionary dumps into the mirrored EN/RU dictionary tables and linking translations.
tags: [dictionary, import, wiktionary, kaikki]
status: stable
generated: { by: agent/kimi-k3, at: 2026-08-16T15:35:00Z }
sources:
  - id: kaikki
    resource: laravel/app/Classes/KaikkiParser.php
    title: Kaikki JSONL parser
  - id: wiktionary
    resource: laravel/app/Classes/WiktionaryParser.php
    title: Wiktionary parser
  - id: import-cmd
    resource: laravel/app/Console/Commands/ImportWiktionaryCommand.php
    title: wiktionary:import
  - id: link-cmd
    resource: laravel/app/Console/Commands/LinkTranslationsCommand.php
    title: wiktionary:link-translations
---

# What it is

The import pipeline that fills the [EN/RU dictionary](/database/en-ru-dictionary.md)
tables from machine-readable Wiktionary data (kaikki.org JSONL dumps).

# Components

* `App\Classes\KaikkiParser` — primary parser for kaikki.org JSONL dumps.
* `App\Classes\WiktionaryParser` — Wiktionary-format parser; shared base
  `App\Classes\Parser`.
* `php artisan wiktionary:import {file}` — parses a dump into `EnWord` /
  `RuWord` + definitions, forms, transcriptions (+types), etymologies,
  pronunciations, examples, word classes, tags. Translations are **stored,
  not linked**, during import.
* `php artisan wiktionary:link-translations` — links EN↔RU words through the
  stored translations, stripping Russian stress marks for matching.

# Where it surfaces

* Filament admin `/admin`: `EnWordResource`, `RuWordResource` with relation
  managers for definitions, pronunciations, translations, etymologies,
  examples, transcriptions.
* Runtime lookup features: the [Bilinguals Simulator](bilinguals-simulator.md)
  dictionary selection/interactions endpoints and
  [Words Search](words-search.md).

# Operating it

Step-by-step: [Importing Dictionary Data](/playbooks/import-dictionary-data.md).
