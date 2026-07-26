---
type: Pipeline
title: Sentence Alignment Pipeline
description: Embedding-based pipeline that aligns EN and RU texts into sentence-level meaning matches, plus the manual editor.
tags: [alignment, embeddings, pipeline, jobs, filament]
status: stable
stale_after: 2026-10-26
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
sources:
  - id: align-service
    resource: laravel/app/Classes/SentenceAlignmentService.php
    title: Core alignment math + embedding client
  - id: signature-service
    resource: laravel/app/Classes/TextSignatureService.php
    title: Text signatures / duplicate detection
  - id: splitter
    resource: laravel/app/Classes/SentenceSplitter.php
    title: Sentence splitting
  - id: sparse
    resource: laravel/app/Classes/SparseOrderService.php
    title: Sparse ordering
  - id: embedding-api
    resource: docker-compose/embedding/main.py
    title: FastAPI embedding service
---

# Purpose

Given an `EnEntity` and a `RuEntity` (the same text in two languages), produce
sentence-level correspondences: which EN sentence(s) express which RU
sentence(s). The output powers the
[Bilinguals Simulator](/domains/bilinguals-simulator.md) and
[Reader](/domains/reader.md). Tables involved:
[Entities & Alignment](/database/entities-alignment.md).

# Stages

1. **Split** — `SentenceSplitter` breaks entity files into
   `EnEntitySentence` / `RuEntitySentence` rows (`SplitEntityFileSentences`
   job; `ProcessEntityFile` orchestrates file ingestion).
2. **Sign** — `TextSignatureService` builds an embedding-based signature per
   entity (`GenerateEntitySignature` job). `verifyEntityPair()` rejects pairs
   whose cosine similarity < **0.70** before alignment is attempted.
3. **Align** — `AlignEntitySentences` dispatches `AlignEntitySentenceChunk`
   jobs. Each chunk calls the embedding API in batches
   (`services.embedding.alignment_batch_size`, default 25;
   `alignment_sentence_max_chars`, default 4000), builds a similarity matrix,
   and scores groupings with penalties: gap 0.08, match 0.35, extra sentence
   0.02, imbalance 0.45; low-confidence (< 0.55) matches get an extra 1.2
   penalty. Results land in `EnRuMeaningMatch`,
   `EnSentenceMeaningMatch` / `RuSentenceMeaningMatch`.
4. **Order** — sentences and matches carry sparse order values managed by
   `SparseOrderService`; `entity-orders:rebalance` runs **daily** (see
   `routes/console.php`).
5. **Review** — humans fix machine output in the Filament
   `EnRuEntityMatch` resource's custom `EditEntityAlignment` page, backed by
   `AlignmentEditorDraftStore` → `AlignmentEditorPersister` →
   `AlignmentEditorPresenter`; `MeaningMatchPresenter` shapes matches for the
   simulator UI. Read-only web views: `/alignments`, `/alignments/{id}`
   (`AlignmentController`).

# Embedding microservice

* Container `ext_embedding` (host port 8001), FastAPI, model
  **`intfloat/multilingual-e5-small`** via sentence-transformers.
* Endpoints for single/batch embedding and batch cosine similarity (see
  `docker-compose/embedding/main.py`).
* Laravel talks to it via `SentenceAlignmentService::create()` using
  `services.embedding.url` (default `http://ext_embedding:8000`) with retries
  at 500/1500/3000 ms.
* `TextSignatureService` also exposes `hasSimilar()` / `findCrossLanguage()`
  for duplicate detection (`services.embedding.has_similar_batch_size`, 200).

# Operator workflow

See [Running an Alignment](/playbooks/run-alignment.md).
