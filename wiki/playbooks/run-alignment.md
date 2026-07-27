---
type: Playbook
title: Running an Alignment
description: End-to-end workflow for aligning an EN/RU text pair into sentence meaning matches.
tags: [alignment, embeddings, jobs, howto]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-27T17:30:00Z }
sources:
  - id: import-sim
    resource: laravel/app/Console/Commands/ImportSimulatorEntitiesCommand.php
    title: entities:import-simulator
  - id: import-sent
    resource: laravel/app/Console/Commands/ImportEntitySentencesCommand.php
    title: entities:import-sentences
  - id: signatures
    resource: laravel/app/Console/Commands/GenerateEntitySignaturesCommand.php
    title: entity:generate-signatures
  - id: jobs
    resource: laravel/app/Jobs/AlignEntitySentences.php
    title: Alignment job chain entry point
  - id: console-routes
    resource: laravel/routes/console.php
    title: Scheduler (daily rebalance)
---

# Prerequisites

* The python service is up: `ext_python` on `http://ext_python:8000`
  (Laravel uses `services.python.url`; default already points there). Check
  `curl localhost:8001/health` from the host → `{"status":"ok","dim":1024}`.
* A queue worker is running (`composer run dev` includes
  `queue:listen --tries=1`).
* Source text files are in place — simulator texts live under
  `laravel/public/texts/simulator/`.

# Workflow

1. **Import entities** — create/update `EnEntity`/`RuEntity` records and their
   sentences from text files:

   ```bash
   docker exec ext_app_laravel php artisan entities:import-simulator --help
   docker exec ext_app_laravel php artisan entities:import-sentences --help
   ```

   (`entities:import-sentences` also creates initial meaning matches; check
   `--help` for required arguments — entity/file options change as the import
   formats evolve.)
2. **Generate signatures** for entities that have files but no signature:

   ```bash
   docker exec ext_app_laravel php artisan entity:generate-signatures
   ```

   Dispatches `GenerateEntitySignature` jobs. Signatures are BGE-M3
   (1024-dim) text fingerprints used to verify that an EN/RU pair is actually
   the same text (threshold 0.70, see
   [Sentence Alignment](/domains/sentence-alignment.md)). Old 384-dim
   e5-small signatures are incompatible — null them out first
   (`UPDATE en_entities SET signature = NULL;` / `ru_entities`), the command
   only processes entities with NULL signatures.
3. **Align** — create an `EnRuEntityMatch` (via Filament admin or import) and
   dispatch `AlignEntitySentences`. It fans out into `AlignEntitySentenceChunk`
   jobs (timeout 600s) that POST each chunk to `/align` and write the returned
   matches as `EnRuMeaningMatch` + sentence-level meaning matches.
4. **Review manually** in the Filament admin: `EnRuEntityMatch` resource →
   custom `EditEntityAlignment` page (draft store → persister → presenter
   classes in `app/Classes/AlignmentEditor*`). Web view: `/alignments` and
   `/alignments/{entityMatch}`.
5. **Rebalance** sparse ordering — runs automatically:
   `entity-orders:rebalance` is scheduled **daily** in `routes/console.php`
   (`SparseOrderService`). Run it manually after large bulk edits.

# Failure handling

* The python HTTP client retries with backoff
  (`RETRY_DELAYS_MS = [500, 1500, 3000]` in `SentenceAlignmentService`).
  Persistent failures usually mean `ext_python` is down or still loading the
  model — check `docker logs ext_python`. `/align` calls use the longer
  `services.python.align_timeout` (default 600s); a 75-sentence chunk takes
  roughly a minute on CPU.
