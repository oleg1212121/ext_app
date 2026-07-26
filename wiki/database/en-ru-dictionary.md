---
type: Database Schema
title: EN/RU Dictionary Tables
description: Mirrored English/Russian dictionary tables filled from Wiktionary dumps (2026_04 migrations).
tags: [database, schema, dictionary]
status: stable
stale_after: 2026-10-26
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
sources:
  - id: migrations
    resource: laravel/database/migrations
    title: 2026_04_* dictionary migrations
  - id: import
    resource: laravel/app/Classes/KaikkiParser.php
    title: Primary writer of these tables
---

# Mirrored structure

Every English table has a Russian twin with the same shape:

| English | Russian | Contents |
|---------|---------|----------|
| `en_words` | `ru_words` | Headwords (models `EnWord` / `RuWord`) |
| `en_forms` | `ru_forms` | Inflected forms |
| `en_definitions` | `ru_definitions` | Sense definitions |
| `en_transcriptions` + `en_transcription_types` | `ru_transcriptions` + `ru_transcription_types` | IPA etc., typed |
| `en_etymologies` | `ru_etymologies` | Etymology text |
| `en_pronunciations` | `ru_pronunciations` | Audio/pronunciation entries |
| `en_examples` | `ru_examples` | Usage examples |
| `en_word_classes` | `ru_word_classes` | Part-of-speech classes |

Cross-cutting:

* `tags`, `en_word_tags`, `ru_word_tags` — shared tagging.
* `en_ru_translations`, `ru_en_translations` — directed translation links
  (models `EnRuTranslation` / `RuEnTranslation`), built by
  `wiktionary:link-translations` with stress-mark stripping.

# Access patterns

* Filament: `EnWordResource` / `RuWordResource` with relation managers per
  satellite table.
* Import: [Dictionary Import](/domains/dictionary-import.md) writes these
  tables; almost nothing else does.
* Runtime: dictionary lookups from the
  [Bilinguals Simulator](/domains/bilinguals-simulator.md) and
  [Words Search](/domains/words-search.md).
