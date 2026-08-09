---
type: Pipeline
title: Sentence Alignment Pipeline
description: Embedding-based pipeline that aligns EN and RU texts into sentence-level meaning matches, plus the manual editor.
tags: [alignment, embeddings, pipeline, jobs, filament]
status: stable
stale_after: 2026-10-26
generated: { by: agent/opencode-go, at: 2026-08-09T18:45:00Z }
verified: { by: human:alex, at: 2026-08-03T19:30:00Z }
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
   entity (`GenerateEntitySignature` job) via `/embed` (**BGE-M3, 1024-dim**).
   `verifyEntityPair()` rejects pairs whose cosine similarity < **0.70** before
   alignment is attempted.
3. **Align** — `AlignEntitySentences` dispatches `AlignEntitySentenceChunk`
   jobs (job timeout 600s). Each chunk POSTs its EN/RU sentence lists to
   `/align` via `SentenceAlignmentService::alignChunkRemote()`
   (`services.python.align_timeout`, default 600); python runs DP over window
   groupings up to `max_window` (gain = score² when cosine ≥ **`ALIGN_DEFAULT_THRESHOLD`** (default 0.4, editable live in `docker-compose/python/env/.env`; MiniLM-L12 typically needs ~0.55), else −2.0
   penalty; the DP force-covers every sentence — no in-window skips), and PHP
   `adaptMatches()` writes the result to `EnRuMeaningMatch`,
   `EnSentenceMeaningMatch` / `RuSentenceMeaningMatch`. The aligner uses a
   separate smaller model (`ALIGN_MODEL_PATH`, default
   `paraphrase-multilingual-MiniLM-L12-v2`, 384-dim) — see [Python microservice](#python-microservice)
4. **Order** — sentences and matches carry sparse order values managed by
   `SparseOrderService`; `entity-orders:rebalance` runs **daily** (see
   `routes/console.php`).
5. **Review** — humans fix machine output in the Filament
   `EnRuEntityMatch` resource's custom `EditEntityAlignment` page (kept as-is),
   or in the new Inertia/React **Alignments editor**: `/alignments` (pair list)
   → `/alignments/{id}` (pair editor), linked from the NavBar. The editor is a
   parallel entry point backed by the surgical `AlignmentEditorController`
   endpoints — create/delete pair, add/edit/unlink/hard-delete sentence, and
   `sentences/move` (within-row reorder / cross-row relink / to-or-from the
   unmatched pool) — with immediate persistence, sparse orders via
   `SparseOrderService`, and JSON payloads shaped by `AlignmentEditorApiPresenter`
   (`rows` + `unmatched` pagination, `last_page` included).
6. **Sentence editing** — individual entity sentences can be created, edited,
   deleted, and reordered from the *Sentences* tab on each `EnEntity` /
   `RuEntity` edit page. The relation manager uses `SparseOrderService` to keep
   insertions efficient; deleting a sentence cleans up any now-empty meaning
   matches.

# Python microservice

* Container `ext_python` (host port 8001), FastAPI.
* **Two models, loaded lazily on first use** (not in the FastAPI lifespan, so
  `uvicorn --reload` can restart the app in ~1–2s after a source edit without
  re-loading the multi-GB model files; `ai/models_cache.py` is the lazy loader):
  - **Signature model** `MODEL_PATH` (default BGE-M3, 1024-dim) — used by
    `/embed`, `/embed/batch`, `/cosine/batch` (signature generation only; the
    cosine compare itself is pure numpy). Backs `TextSignatureService`.
  - **Aligner model** `ALIGN_MODEL_PATH` (default
    `paraphrase-multilingual-MiniLM-L12-v2`, 384-dim) — used by `/align`. ~2.5–3×
    faster on CPU than BGE-M3; cosine scores run hotter (threshold likely needs
    ~0.55 — recalibrate per pair).
* **Model weights** live in a named Docker volume `ai_models` mounted at
  `/app/models/<subdir>` (BGE-M3 at `/app/models/bge_m3`, MiniLM at
  `/app/models/minilm`). The image carries no models; the source tree carries
  none (the old `docker-compose/python/ai/bge_m3_local/` is gitignored/removed).
  Download a new model with:
  `docker exec -e HF_HUB_OFFLINE=0 ext_python python /app/scripts/download_model.py <hf_repo_id> /app/models/<name>`
  then activate by editing `ALIGN_MODEL_PATH` in `docker-compose/python/env/.env`
  — the next `/align` request lazy-loads it, no rebuild or restart.
  `HF_HUB_OFFLINE=1` by default at runtime.
* **Live config**: `docker-compose/python/env/.env` is bind-mounted at
  `/app/env/.env` (directory mount, not single-file, so `sed -i`/editor inode
  swaps survive). `docker-compose.yml` also sets `env_file:` so import-time
  constants (validation limits) see env at container start. The live per-request
  accessors in `ai/config.py` (`align_default_threshold()`,
  `align_default_window()`, `model_path()`, `align_model_path()`) re-read
  `/app/env/.env` on every call — edits apply on the next request with **no
  recreate or restart**.
* **Code iteration** (`docker-compose.yml` dev `command:` = `uvicorn --reload
  --reload-dir /app/ai`): save a `.py` under `docker-compose/python/ai/` →
  uvicorn reloads in ~1–2s (models stay cached across reloads via the empty
  lifespan + `ModelCache`); the first request after a save lazy-loads its model
  (~5s for the MiniLM aligner, ~15s for BGE-M3 signature). Rebuilding the image
  is only needed for new `requirements.txt` packages.
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
  then `php artisan entity:generate-signatures`. (Signature dimension is still
  1024 — BGE-M3 — and is independent of the aligner model.)

# Operator workflow

See [Running an Alignment](/playbooks/run-alignment.md).
