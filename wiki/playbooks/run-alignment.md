---
type: Playbook
title: Running an Alignment
description: End-to-end workflow for aligning an EN/RU text pair into sentence meaning matches.
tags: [alignment, embeddings, jobs, howto]
status: stable
generated: { by: agent/opencode-go, at: 2026-08-11T18:45:00Z }
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
    title: Self-restarting alignment job + begin() entry point
  - id: resume-cmd
    resource: laravel/app/Console/Commands/AlignmentsResumeCommand.php
    title: alignments:resume (5-minute scheduled picker)
  - id: console-routes
    resource: laravel/routes/console.php
    title: Scheduler (daily rebalance + 5-minute alignments:resume)
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
3. **Align** — create an `EnRuEntityMatch` (`status='pending'`; either via
   Filament, an import command, or directly). Every entry point
   (Filament "new alignment", "align with Russian/English", "Re-run", and
   the `alignments:resume` command) calls `AlignEntitySentences::begin($id)`
   — a shared static that verifies the pair, snapshots totals, resets the
   cursor, transitions to `aligning`, and dispatches the first chunk. The
   chunk job (`AlignEntitySentences::handle()`) processes one chunk of
   `chunk_size` sentences (default 75) per invocation. Each chunk reads its
   slice from `last_en_sentence_offset` / `last_ru_sentence_offset` (RU
   offset = EN offset, no overlap — see ADR 0004) and commits only matches
   up to and including the **last confident anchor** (score ≥ 0.40). That
   trims the force-aligned garbage the DP produces near the chunk seam
   (e.g. 5:1 / 1:5 mis-pairs); the dropped tail is re-aligned with fresh
   context by the next invocation, which resumes from the anchor's
   `en_end`/`ru_end`, not the end of the window. Because the strict 1:1
   window gives the DP no backward reach, the seam garbage would re-appear
   at the *head* of the next chunk — so each invocation first **rolls back**
   the last 2 committed meaning matches (rows + junction rows deleted,
   cursor rewound to their first sentences, window widened by their spans)
   and re-aligns that region with fresh forward context. Skip steps and
   human-edit rows (`alignment_chunk = -1`) are never rolled back, and a
   monotone-cursor safety net force-advances EN if a rolled-back commit
    would otherwise stall. The job
    `self::dispatch()`es the next invocation until the cursor reaches
    `en_total_sentences`, at which point the entity match flips to
    `completed`. Meaning matches carry a monotonic `alignment_chunk` per run.
    Small entities (`max(en_total, ru_total) ≤ 75`) are raised to a single
    chunk in `begin()`, skipping the seam rollback/trim machinery entirely.
    The python DP also has a skip branch (sentences with no counterpart land
    in `unmatched_en`/`unmatched_ru` instead of a <0.6 garbage match) and a
    span cap (`ALIGN_MAX_TOTAL_SPAN`) — see
    [Sentence Alignment](/domains/sentence-alignment.md).
4. **Let the scheduler pick up pending pairs automatically** —
   `Schedule::command('alignments:resume')->everyFiveMinutes()
   ->withoutOverlapping()` picks up to **10** `status='pending'` entity
   matches per tick and runs each through `begin()`. Run it manually for
   testing: `docker exec ext_app_laravel php artisan alignments:resume`
   (`--limit=N` to override the batch size, `--dry-run` to report without
   dispatching).
5. **Review manually** in the Filament admin: `EnRuEntityMatch` resource →
   custom `EditEntityAlignment` page (draft store → persister → presenter
   classes in `app/Classes/AlignmentEditor*`). Web view: `/alignments` and
   `/alignments/{entityMatch}`. The Filament table's "Re-run" action calls
   `begin()` again — wiping all meaning matches (including human
   `alignment_chunk=-1` rows) and restarting the cursor from 0; visible
   only on `status ∈ {completed, failed}`.
6. **Rebalance** sparse ordering — runs automatically:
   `entity-orders:rebalance` is scheduled **daily** in `routes/console.php`
   (`SparseOrderService`). Run it manually after large bulk edits.

# Failure handling

* The python HTTP client retries with backoff
  (`RETRY_DELAYS_MS = [500, 1500, 3000]` in `SentenceAlignmentService`).
  Persistent failures usually mean `ext_python` is down or still loading the
  model — check `docker logs ext_python`. `/align` calls use the longer
  `services.python.align_timeout` (default 600s); a 75-sentence chunk takes
  roughly a minute on CPU.
* The self-restarting `AlignEntitySentences` job also has `tries=5` with
  backoff `[30, 60, 120, 300]` so transient chunk failures heal in-process
  without surfacing to the user. If all retries are exhausted, `failed()`
  sets the entity match `failed` (terminal — the 5-minute command will not
  re-pick it; only a human clicking "Re-run" can recover it). The cursor is
  **not** reset on failure, so even after `failed` a Re-run that preserved
  prior chunks' work would be possible — though `begin()` wipes meaning
  matches today; see ADR 0003 for the trade-off.
* Queue capacity: set `DB_QUEUE_RETRY_AFTER=900` (≥ `AlignEntitySentences`'
  600s timeout) so the `database` queue does not re-lease a long-running
  chunk to a second worker. The `.env.example` ships with this default.
