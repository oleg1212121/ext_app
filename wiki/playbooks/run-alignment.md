---
type: Playbook
title: Running an Alignment
description: End-to-end workflow for aligning two same-work entities (any language pair) into sentence meaning matches.
tags: [alignment, embeddings, jobs, howto]
status: stable
stale_after: 2026-12-10
generated: { by: agent:zcode, at: 2026-09-20T23:59:00Z }
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
    title: Self-restarting alignment job + begin()/beginFromScratch() entry points
  - id: resume-cmd
    resource: laravel/app/Console/Commands/AlignmentsResumeCommand.php
    title: alignments:resume (5-minute scheduled picker)
  - id: console-routes
    resource: laravel/routes/console.php
    title: Scheduler (daily rebalance + 5-minute alignments:resume + crossword:refresh)
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

1. **Import entities** — entities live in the unified `entities` table
   (`work_id` + `language_id`; every entity belongs to a work — see ADR
   [0018](../../docs/adr/0018-works-and-unified-language-keyed-tables.md)).
   Create them via the `/entities` UI (pick or create a work, upload a file),
   the Filament `EntityResource`, or from text files:

   ```bash
   docker exec ext_app_laravel php artisan entities:import-sentences <file> <first_entity_id> <second_entity_id>
   docker exec ext_app_laravel php artisan entities:import-simulator --all
   ```

   `entities:import-sentences` reads a bilingual pair file into the two named
   entities and creates the initial meaning matches (and the entity match).
   `entities:import-simulator` seeds via `SimulatorEntitySeeder` (one work
   per simulator file pair, original language `en` by default) and imports
   each pair; `--file=<basename>` for one, `--skip-existing` to skip
   completed pairs.
2. **Generate signatures** for entities that have files but no signature:

   ```bash
   docker exec ext_app_laravel php artisan entity:generate-signatures
   ```

   Dispatches `GenerateEntitySignature` jobs (entity id + file path; the job
   reads the language from the entity). Signatures are BGE-M3
   (1024-dim) text fingerprints used to verify that a pair is actually
   the same text (threshold 0.70, see
   [Sentence Alignment](/domains/sentence-alignment.md)). Old 384-dim
   e5-small signatures are incompatible — null them out first
   (`UPDATE entities SET signature = NULL;`), the command
   only processes entities with NULL signatures.
3. **Align** — create an `EntityMatch` (`status='pending'`): via the
   `/alignments` "+ Create new" React form (`alignments.create/store`) —
   pick a **work**, then `first_entity_id` + `second_entity_id` (there is no
   original-side choice; the original language lives on the work); the store
   validates **same work** (same-language pairs such as exercises and
   answers are valid — ADR 0019) and canonicalizes the pair
   (lower entity id = a side) — or via the Filament `EntityMatchResource` /
   `EntityResource` "Find Match" action (same-work entities, any languages), an import command, or directly. Every creation entry
   point first tries `AlignmentCopyService::copyFor()` (ADR 0033): a
   completed match between **exact-copy** entities (equal `text_hash` +
   language, either orientation) is cloned wholesale and the new match is
   `completed` at creation — no pipeline run, no Python. Only when no
   eligible source exists do the fresh entry
   points (Filament "new alignment" / "Find Match", the web create form, and
   the `alignments:resume` command) call
   `AlignEntitySentences::beginFromScratch($id)` — a shared
   static that verifies the pair, wipes any prior meaning matches, snapshots
    totals, resets the cursor, transitions to `aligning`, and dispatches the
    first chunk. (Approved entities: `alignments:resume` skips their matches
    — an approved entity freezes every alignment it takes part in, ADR 0034.)
    The Filament **Re-align** action instead calls the
    landmark-aware `begin($id)`: it preserves human-made rows
    (`alignment_chunk = -1`) and high-confidence auto-landmarks
    (similarity ≥ 0.90) and only deletes lower-confidence machine rows, then
    re-aligns the gaps between landmarks as independent pools (see the
    pipeline doc for pool semantics). Each pool that fits one window is
    aligned by a single job invocation: `handle()` persists the cursor and
    re-dispatches after every pool, so a re-align of many small landmark
    pools runs as one queued job per pool (a match with 141 pools previously
    ran one long ~2m16s job). Pools larger than `chunk_size` on either side
    fall through to the chunk machinery below and are drained chunk-by-chunk
    across jobs. The chunk job
    (`AlignEntitySentences::handle()`) processes one chunk of
   `chunk_size` sentences (default 75) per invocation. Each chunk reads its
   slice from `a_last_sentence_offset` / `b_last_sentence_offset` (b
   offset = a offset, no overlap — see ADR 0004) and commits only matches
   up to and including the **last confident anchor** (score ≥ 0.40). That
   trims the force-aligned garbage the DP produces near the chunk seam
   (e.g. 5:1 / 1:5 mis-pairs); the dropped tail is re-aligned with fresh
   context by the next invocation, which resumes from the anchor's
   `a_end`/`b_end`, not the end of the window. Because the strict 1:1
   window gives the DP no backward reach, the seam garbage would re-appear
   at the *head* of the next chunk — so each invocation first **rolls back**
   the last 2 committed meaning matches (rows + junction rows deleted,
   cursor rewound to their first sentences, window widened by their spans)
   and re-aligns that region with fresh forward context. Skip steps and
   human-edit rows (`alignment_chunk = -1`) are never rolled back, and a
   monotone-cursor safety net force-advances the a side if a rolled-back
   commit would otherwise stall. The job
    `self::dispatch()`es the next invocation until the cursor reaches
    `a_total_sentences`, at which point the entity match flips to
    `completed`. Meaning matches carry a monotonic `alignment_chunk` per run.
    Completion goes through a single gate (`AlignEntitySentences::finalize()`):
    any still-junction-less sentence on the **covered sides** — the work's
    original side (`EntityMatch::originalSide()`, derived from
    `works.original_language_id`; **both** sides for translation↔translation
    pairs where neither entity is the original language) — is drained as a
    **single-sided meaning match** (`similarity 0.0`) ordered to preserve
    document order, so covered sides are never unmatched. "One side's
    sentences exhausted before the other's" is a normal completion (the
    remaining covered-side tail is drained), not an error — see
    [Sentence Alignment](/domains/sentence-alignment.md).
    Small entities (`max(a_total, b_total) ≤ 75`) are raised to a single
    chunk in `beginFromScratch()`, skipping the seam rollback/trim machinery
    entirely.
    The python DP also has a skip branch (sentences with no counterpart land
    in `unmatched_a`/`unmatched_b` instead of a <0.6 garbage match) and a
    span cap (`ALIGN_MAX_TOTAL_SPAN`) — see
    [Sentence Alignment](/domains/sentence-alignment.md).
4. **Let the scheduler pick up pending pairs automatically** —
   `Schedule::command('alignments:resume')->everyFiveMinutes()
   ->withoutOverlapping()` picks up to **10** `status='pending'` entity
    matches per tick and runs each through `beginFromScratch()`. Run it
    manually for
    testing: `docker exec ext_app_laravel php artisan alignments:resume`
    (`--limit=N` to override the batch size, `--dry-run` to report without
    dispatching).
 5. **Review manually** in the Filament admin: `EntityMatchResource` →
    custom `EditEntityAlignment` page (draft store → persister → presenter
    classes in `app/Classes/AlignmentEditor*`). Web view: `/alignments` and
    `/alignments/{entityMatch}`. The Filament table offers two explicit
    restart actions (visible only on `status ∈ {completed, failed}`):
    **Re-align** calls the landmark-aware `begin()` — preserving human
    `alignment_chunk=-1` rows and high-confidence landmarks, deleting only
    lower-confidence machine rows, and restarting the cursor from 0; **Run
    from scratch** calls `beginFromScratch()` and deletes **all** meaning
    matches including human-made ones. Both confirmation modals state exactly
    what will be kept or wiped.
6. **Rebalance** sparse ordering — runs automatically:
   `entity-orders:rebalance` is scheduled **daily** in `routes/console.php`
   (`SparseOrderService`; language-agnostic — it scopes `entity_sentences`
   and `meaning_matches`, no `--lang`). Run it manually after large bulk
   edits.

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
  re-pick it; only a human clicking the Filament **Re-align** / **Run from
  scratch** actions can recover it). The cursor is
  **not** reset on failure, so even after `failed` a restart that preserved
   prior chunks' work would be possible — and with the landmark-aware
   `begin()` (ADR 0003 trade-off) a Re-align preserves human rows today.
* Queue capacity: set `DB_QUEUE_RETRY_AFTER=900` (≥ `AlignEntitySentences`'
  600s timeout) so the `database` queue does not re-lease a long-running
  chunk to a second worker. The `.env.example` ships with this default.
