---
type: Database Schema
title: Entities & Alignment Tables
description: Bilingual texts, their sentences, and the machine/human alignment between them (2026_04–06 migrations).
tags: [database, schema, alignment, entities]
status: stable
stale_after: 2026-10-26
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
sources:
  - id: migrations
    resource: laravel/database/migrations
    title: 2026_04–06 entity/alignment migrations (incl. widen_sparse_order_columns)
  - id: align-service
    resource: laravel/app/Classes/SentenceAlignmentService.php
    title: Writer of meaning matches
---

# Tables

| Table | Model | Role |
|-------|-------|------|
| `en_entities` / `ru_entities` | `EnEntity` / `RuEntity` | A text (book/story/file) in one language; carries an embedding `signature` |
| `sentence_types` | `SentenceType` | Classification for sentences |
| `en_entity_sentences` / `ru_entity_sentences` | `EnEntitySentence` / `RuEntitySentence` | Split sentences with **sparse order** values |
| `en_ru_entity_matches` | `EnRuEntityMatch` | Pairing of one EN entity with one RU entity ("same text, two languages") |
| `en_ru_meaning_matches` | `EnRuMeaningMatch` | Sentence-group level alignment result within a match |
| `en_sentence_meaning_matches` / `ru_sentence_meaning_matches` | `EnSentenceMeaningMatch` / `RuSentenceMeaningMatch` | Per-sentence membership in a meaning match, per side |
| `en_ru_translations` / `ru_en_translations` | `EnRuTranslation` / `RuEnTranslation` | Word-level translation links (see [EN/RU Dictionary](en-ru-dictionary.md)) |

# Invariants & notes

* **Sparse ordering**: sentence and match order columns hold sparse values
  maintained by `SparseOrderService`; columns were widened in the
  2026_06 `widen_sparse_order_columns` migration. Rebalance daily via
  `entity-orders:rebalance`.
* Alignment rows are written by the
  [alignment pipeline](/domains/sentence-alignment.md) and edited by humans via
  the Filament `EditEntityAlignment` page (draft → persist flow).
* `EnRuEntityMatch` is what the simulator's text dropdown lists — joining
  `enEntity` / `ruEntity` for display names.
