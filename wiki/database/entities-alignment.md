---
type: Database Schema
title: Works, Entities & Alignment Tables
description: Works grouping per-language entities, their sentences, and the machine/human alignment between them (unified a/b schema, 2026_09_10 migrations; creator/flags/hashes 2026_09_20).
tags: [database, schema, alignment, entities, works, hash]
status: stable
stale_after: 2026-12-20
generated: { by: agent:zcode, at: 2026-09-22T16:00:00Z }
sources:
   - id: migrations
     resource: laravel/database/migrations/2026_09_10_000003_create_works_and_entities_tables.php
     title: works + unified entities/sentences/grants creation
   - id: entity-columns
     resource: laravel/database/migrations/2026_09_20_000001_add_entity_creator_flags_and_hashes.php
     title: created_by / is_approved / file_hash / text_hash / staleness timestamps
   - id: alignment-migration
     resource: laravel/database/migrations/2026_09_10_000004_create_alignment_tables.php
     title: entity_matches + meaning_matches + sentence_meaning_matches (side column)
   - id: align-service
     resource: laravel/app/Classes/SentenceAlignmentService.php
     title: Writer of meaning matches
---

# Tables

| Table | Model | Role |
|-------|-------|------|
| `works` | `Work` | The abstract book: title, author, description, `original_language_id` → languages. Groups every language version of one text |
| `entities` | `Entity` | A text (book/story/file) in one language — the original or a translation of its work. Carries `work_id`, `language_id`, `created_by` (nullable uploader), an optional translator/edition `label`, a BGE-M3 embedding `signature`, `is_restricted` gating read access, `is_approved` (edit lock), `file_hash` (raw upload bytes) and `text_hash`/`text_hashed_at`/`sentences_updated_at` (exact-copy detection, ADR 0033) |
| `sentence_types` | `SentenceType` | Classification for sentences |
| `entity_sentences` | `EntitySentence` | Split sentences with **sparse order** values; unique `(entity_id, order)` |
| `entity_matches` | `EntityMatch` | Pairing of two distinct same-work entities ("same text, two versions"; same-language companions like exercises + answers included), stored canonically `a_entity_id < b_entity_id` |
| `meaning_matches` | `MeaningMatch` | Sentence-group level alignment result within a match |
| `sentence_meaning_matches` | `SentenceMeaningMatch` | Per-sentence membership in a meaning match, with a `side` char(1) (`'a'`/`'b'`) naming which entity of the match the sentence belongs to |
| `entity_user` | (pivot) | Access grants: which users may read a Restricted entity, with a nullable `similarity` (null = creator grant; non-null = legacy Signature match grant — no longer produced, ADR 0033) |

# Invariants & notes

* **Language-neutral by construction.** No `en_`/`ru_` tables remain; the
  original side of a match is *derived* (`EntityMatch::originalSide()`) by
  comparing each side's `language_id` to the work's `original_language_id` —
  `'a'`, `'b'`, or `null` when both sides are translations. Match creation
  (Inertia + Filament) validates same work (distinct entities, any
  languages) and canonicalizes the pair order (ADR
  [0018](../../docs/adr/0018-works-and-unified-language-keyed-tables.md),
  [0019](../../docs/adr/0019-same-language-entity-matches.md)).
* **Every entity belongs to a work** (`work_id` NOT NULL). A work may hold
  several entities in the same language (competing translations) told apart
  by `entities.label`.
* **Cover both sides**: when neither side of a match is the work's original
  language, the aligner's skip rows and finalize repairs cover BOTH sides —
  the original-completeness invariant generalizes to translation↔translation
  pairs.
* **Sparse ordering**: sentence and match order columns hold sparse values
  (stride 1024) maintained by `SparseOrderService`; every creation path emits
  sparse values from birth (the split pipeline, the console importer, the
  entity *Sentences* tab, the Filament relation managers). Dense lists are
  repaired by `entity-orders:rebalance`. Both `EntityController` and
  `AlignmentEditorController` shift the whole sparse result up whenever a
  rebalance would push the minimum order negative.
* **Sentence orders are unique per entity**: `(entity_id, order)` carries a
  unique index; every sentence-order write is two-phase (changed rows parked
  at unique negatives `-(id + 1e9)` before finals) —
  `SparseOrderService::orderForInsertAfter`,
  `AlignmentEditorPersister::syncSentences`,
  `EntityController::persistSentenceOrders`,
  `AlignmentEditorController::placeSideSentence`.
* **Document order is the single source of truth**: `entity_sentences.order`
  is the sentence's position in its text. The junction table is a pure
  association table (no `order` column); within-row display order is each
  sentence's document order. Drag-to-reorder renumbers document order so the
  sentence sorts exactly where it was dropped (see ADR
  [0016](../../docs/adr/0016-drop-position-wins-alignment-editor.md)).
* **The alignment pipeline is order-preserving by contract**: sentences keep
  the original order of the uploaded source text file — alignment assigns
  sentence orders once at split and never permutes them; it writes
  matches/junctions only. `meaning_matches.order` must equal the
  document-position sequence of each side's junctioned sentences, enforced by
  `resequenceMatchesByDocumentPosition()` after every chunk persist, at the
  `finalize()` completion gate, and in the alignment-copy transaction
  (`AlignmentCopyService`). Details: the order-preservation invariant in
  [Sentence Alignment Pipeline](/domains/sentence-alignment.md).
* **Landmarks**: `meaning_matches.alignment_chunk = -1` marks human-made rows
  (always `similarity = 1.0`); machine rows carry a monotonic per-run chunk
  id. Machine rows with `similarity >= 0.90` are auto-landmarks. Both tiers
  survive Re-align and act as pool boundaries; "Run from scratch" deletes
  both. An **Alignment copy** (ADR 0033) clones rows with their landmark
  markers verbatim.
* **Exact-copy hashes** (ADR 0033): `text_hash` (indexed, not unique — copies
  share it by design) is sha256 over whitespace-normalized sentence contents
  in document order; `sentences_updated_at` is bumped by every sentence
  mutation (Eloquent events + explicit touches at the bulk writers) and
  `text_hashed_at < sentences_updated_at` means stale — the
  `entities:refresh-text-hashes` scheduler rehashes. `file_hash` (raw upload
  bytes) enables the no-split clone path at upload. Hashes never couple
  entities: deleting an entity never touches its copies (no provenance FK).
* **Uploader & edit lock** (ADR 0034): `created_by` nullable FK → users
  (`nullOnDelete`; null = system import); `is_approved` freezes content edits
  (metadata, sentences, matches involving the entity, deletion) for everyone
  except admins; `alignments:resume` skips matches with an approved side.
* **Admin editing**: `EntityResource` (one resource, language select +
  work select with inline create) exposes a *Sentences* relation manager
  supporting create/edit/delete/reorder with sparse order preservation.
  `WorkResource` manages works.
* **Deletion cleanup**: deleting a sentence cascades to its junctions; a
  meaning match left with no junctions is deleted and the parent
  `EntityMatch.linked_count` is updated (`EntitySentence::booted()`).
* **Single-sided meaning matches**: the aligner keeps unmatched
  original-side sentences visible via one-sided junctions (`similarity 0.0`,
  next machine chunk id); the completion gate
  `AlignEntitySentences::finalize()` enforces original completeness (both
  sides when neither is the original). The editor's **Needs review** section
  surfaces one-sided rows (any similarity) plus two-sided rows below 0.55.
* `EntityMatch` is what an alignment card and the pinned simulator route
  label — joining `aEntity` / `bEntity` for display names.
* **Read access is Restricted by default** (ADR
  [0013](../../docs/adr/0013-default-restricted-uploads-and-per-entity-grants.md)):
  every new Entity is `is_restricted = true`; admin publishes to make it
  readable by all approved users. Grants are **per entity** (`entity_user`)
  and deliberately do NOT cascade to the entity's other translations.
  Reading an entity match requires grants on **both** sides (ADR
  [0014](../../docs/adr/0014-per-entity-grants-require-both-sides-for-simulator.md)).
