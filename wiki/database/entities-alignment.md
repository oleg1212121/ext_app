---
type: Database Schema
title: Entities & Alignment Tables
description: Bilingual texts, their sentences, and the machine/human alignment between them (2026_04–06 migrations).
tags: [database, schema, alignment, entities]
status: stable
stale_after: 2026-10-26
generated: { by: human:alex, at: 2026-08-25T00:00:00Z }
sources:
   - id: migrations
     resource: laravel/database/migrations
     title: 2026_04–08 entity/alignment migrations (incl. add_is_original_en_to_en_ru_entity_matches)
   - id: access-migration
     resource: laravel/database/migrations/2026_08_25_070508_add_entity_access_control.php
     title: is_restricted column + en_entity_user / ru_entity_user pivots
   - id: align-service
     resource: laravel/app/Classes/SentenceAlignmentService.php
     title: Writer of meaning matches
---

# Tables

| Table | Model | Role |
|-------|-------|------|
| `en_entities` / `ru_entities` | `EnEntity` / `RuEntity` | A text (book/story/file) in one language; carries a BGE-M3 embedding `signature` (1024-dim) and an `is_restricted` boolean (default false) gating read access |
| `sentence_types` | `SentenceType` | Classification for sentences |
| `en_entity_sentences` / `ru_entity_sentences` | `EnEntitySentence` / `RuEntitySentence` | Split sentences with **sparse order** values |
| `en_ru_entity_matches` | `EnRuEntityMatch` | Pairing of one EN entity with one RU entity ("same text, two languages") |
| `en_ru_meaning_matches` | `EnRuMeaningMatch` | Sentence-group level alignment result within a match |
| `en_sentence_meaning_matches` / `ru_sentence_meaning_matches` | `EnSentenceMeaningMatch` / `RuSentenceMeaningMatch` | Per-sentence membership in a meaning match, per side |
| `en_ru_translations` / `ru_en_translations` | `EnRuTranslation` / `RuEnTranslation` | Word-level translation links (see [EN/RU Dictionary](en-ru-dictionary.md)) |
| `en_entity_user` / `ru_entity_user` | (pivot) | Access grants: which users may read a Restricted entity, with a nullable `similarity` (null = creator grant, non-null = Signature match grant) |

# Invariants & notes

* **Sparse ordering**: sentence and match order columns hold sparse values
  maintained by `SparseOrderService`; columns were widened in the
  2026_06 `widen_sparse_order_columns` migration. Rebalance daily via
  `entity-orders:rebalance`.
* **Document order is the single source of truth**: `en_entity_sentences.order` is
  the sentence's **document order** — its position in the original text. It is
  immutable in the alignment editor (only the *Sentences* tab, import, and
  `entity-orders:rebalance` change it). The junction tables
  (`en_sentence_meaning_matches` / `ru_sentence_meaning_matches`) are pure
  association tables with no `order` column. Within-row display order is
  determined by each sentence's document order.
* **Landmarks**: `en_ru_meaning_matches.alignment_chunk = -1` marks
  human-made rows (always `similarity = 1.0`); machine rows carry a monotonic
  per-run chunk id (never `-1`). Machine rows with
  `similarity >= LANDMARK_THRESHOLD` (0.90) are promoted to auto-landmarks on
  re-align. Both tiers survive Re-align and act as pool boundaries; "Run from
  scratch" deletes both (see [Sentence Alignment](/domains/sentence-alignment.md)).
* **Admin editing**: both `EnEntityResource` and `RuEntityResource` expose a
  *Sentences* relation manager on the entity edit page. It supports creating,
  editing, deleting, and reordering sentences while preserving sparse order.
* **Deletion cleanup**: deleting a sentence cascades to its per-side meaning
  matches; if a meaning match is left without sentences on either side, the
  match is deleted and the parent `EnRuEntityMatch.linked_count` is updated.
* **Single-sided meaning matches**: the aligner keeps unmatched *original-text*
  sentences visible by junctioning them into a meaning match with only one
  side junctioned (`similarity 0.0`, next machine `alignment_chunk` id). The
  completion gate `AlignEntitySentences::finalize()` enforces the
  original-completeness invariant — original sentences are never unmatched —
  and creates these rows positionally via `SparseOrderService`
  (see [Sentence Alignment](/domains/sentence-alignment.md)). The editor's
  **Needs review** section surfaces these (one-sided, any similarity) plus
  two-sided rows with `similarity < 0.55`.
* **Original text**: `EnRuEntityMatch.is_original_en` (boolean, default `true`)
  records which side is the original text — the language the text was authored
  in, the other side being a translation of it. Metadata only; set on the
  admin create form and read-only in the table. See `CONTEXT.md` "Original text".
* `EnRuEntityMatch` is what the simulator's text dropdown lists — joining
  `enEntity` / `ruEntity` for display names.
* **Read access is Restricted by default.** Every new Entity is `is_restricted =
  true`; admin publishes it (`is_restricted = false`) to make it readable by all
  approved users. Restricted entities are readable only by admin and users with a
  row in `en_entity_user` / `ru_entity_user` (see the [Entity Access](
  ../../CONTEXT.md#entity-access-context) context and ADR 0013). A Signature match at
  upload time links the uploader to the existing Entity instead of creating a
  duplicate. Reading an `EnRuEntityMatch` requires grants on **both** sides (ADR
  0014).
