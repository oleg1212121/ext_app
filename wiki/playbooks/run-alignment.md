---
type: Playbook
title: Running an Alignment
description: End-to-end workflow for aligning an EN/RU text pair into sentence meaning matches.
tags: [alignment, embeddings, jobs, howto]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
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

* The embedding service is up: `ext_embedding` on `http://ext_embedding:8000`
  (Laravel uses `services.embedding.url`; default already points there).
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

   Dispatches `GenerateEntitySignature` jobs. Signatures are embedding-based
   text fingerprints used to verify that an EN/RU pair is actually the same
   text (threshold 0.70, see
   [Sentence Alignment](/domains/sentence-alignment.md)).
3. **Align** — create an `EnRuEntityMatch` (via Filament admin or import) and
   dispatch `AlignEntitySentences`. It fans out into `AlignEntitySentenceChunk`
   jobs that compute embedding similarity matrices and write
   `EnRuMeaningMatch` + sentence-level meaning matches.
4. **Review manually** in the Filament admin: `EnRuEntityMatch` resource →
   custom `EditEntityAlignment` page (draft store → persister → presenter
   classes in `app/Classes/AlignmentEditor*`). Web view: `/alignments` and
   `/alignments/{entityMatch}`.
5. **Rebalance** sparse ordering — runs automatically:
   `entity-orders:rebalance` is scheduled **daily** in `routes/console.php`
   (`SparseOrderService`). Run it manually after large bulk edits.

# Failure handling

* The embedding HTTP client retries with backoff
  (`RETRY_DELAYS_MS = [500, 1500, 3000]` in `SentenceAlignmentService`).
  Persistent failures usually mean `ext_embedding` is down or the model cache
  volume is empty — check `docker logs ext_embedding`.
