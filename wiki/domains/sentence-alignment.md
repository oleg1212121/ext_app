---
type: Pipeline
title: Sentence Alignment Pipeline
description: Embedding-based pipeline that aligns EN and RU texts into sentence-level meaning matches, plus the manual editor.
tags: [alignment, embeddings, pipeline, jobs, filament]
status: stable
stale_after: 2026-10-26
generated: { by: agent/kimi-k3, at: 2026-07-28T15:00:00Z }
sources:
  - id: align-service
    resource: laravel/app/Classes/SentenceAlignmentService.php
    title: /align HTTP client + meaning-match storage
  - id: signature-service
    resource: laravel/app/Classes/TextSignatureService.php
    title: Text signatures / duplicate detection (/embed + /cosine/batch client)
  - id: splitter
    resource: laravel/app/Classes/SentenceSplitter.php
    title: Sentence splitting (/split streaming client)
  - id: sparse
    resource: laravel/app/Classes/SparseOrderService.php
    title: Sparse ordering
  - id: python-api
    resource: docker-compose/python/ai/main.py
    title: FastAPI python service (BGE-M3)
  - id: aligner
    resource: docker-compose/python/ai/alignment/bilingual_aligner.py
    title: DP alignment algorithm (python)
  - id: py-splitter
    resource: docker-compose/python/ai/splitting/typed_splitter.py
    title: Typed sentence splitting (python)
---

# Purpose

Given an `EnEntity` and a `RuEntity` (the same text in two languages), produce
sentence-level correspondences: which EN sentence(s) express which RU
sentence(s). The output powers the
[Bilinguals Simulator](/domains/bilinguals-simulator.md) and
[Reader](/domains/reader.md). Tables involved:
[Entities & Alignment](/database/entities-alignment.md).

# Stages

1. **Split** — `SentenceSplitter` streams entity files in
   ~`services.python.sentence_split_chunk_bytes` chunks to the python `/split`
   endpoint (UTF-8-safe cut + raw-remainder stitching, so chunk seams are
   seamless) and writes `EnEntitySentence` / `RuEntitySentence` rows
   (`SplitEntityFileSentences` job; `ProcessEntityFile` orchestrates file
   ingestion). Splitting itself (pysbd + title heuristics ported from the old
   PHP splitter) lives in python `ai/splitting/`.
2. **Sign** — `TextSignatureService` builds an embedding-based signature per
   entity (`GenerateEntitySignature` job) via `/embed` (BGE-M3, 1024-dim).
   `verifyEntityPair()` rejects pairs whose cosine similarity < **0.70** before
   alignment is attempted.
3. **Align** — `AlignEntitySentences` dispatches `AlignEntitySentenceChunk`
   jobs (job timeout 600s). Each chunk POSTs its EN/RU sentence lists to
   `/align` via `SentenceAlignmentService::alignChunkRemote()`
   (`services.python.align_timeout`, default 600); python runs DP over window
   groupings up to `max_window` (gain = score² when cosine ≥ **0.4**, else −2.0
   penalty; the DP force-covers every sentence — no in-window skips), and PHP
   `adaptMatches()` writes the result to `EnRuMeaningMatch`,
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
6. **Sentence editing** — individual entity sentences can be created, edited,
   deleted, and reordered from the *Sentences* tab on each `EnEntity` /
   `RuEntity` edit page. The relation manager uses `SparseOrderService` to keep
   insertions efficient; deleting a sentence cleans up any now-empty meaning
   matches.

# Python microservice

* Container `ext_python` (host port 8001), FastAPI, model **BGE-M3**
  (1024-dim, bind-mounted read-only from `docker-compose/python/ai/bge_m3_local`;
  `HF_HUB_OFFLINE=1` so it never downloads at runtime).
* Endpoints: `/health`, `/embed`, `/embed/batch`, `/cosine/batch`, `/split`,
  `/align` (see `docker-compose/python/ai/main.py` + `ai/api/`). Heavy
  endpoints are sync (`def`) so a long `/align` does not starve `/health`.
* Python writes **nothing** to Postgres — Laravel owns all DB writes.
* Laravel talks to it via `services.python.url` (default
  `http://ext_python:8000`) with retries at 500/1500/3000 ms; keys:
  `timeout`, `align_timeout`, `has_similar_batch_size`,
  `sentence_split_chunk_bytes`.
* `TextSignatureService` also exposes `hasSimilar()` / `findCrossLanguage()`
  for duplicate detection (`services.python.has_similar_batch_size`, 200).
* Signatures from the old e5-small service are 384-dim and incompatible —
  regenerate: `UPDATE en_entities SET signature = NULL;` (and `ru_entities`),
  then `php artisan entity:generate-signatures`.

# Operator workflow

See [Running an Alignment](/playbooks/run-alignment.md).
