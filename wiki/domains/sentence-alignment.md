---
type: Pipeline
title: Sentence Alignment Pipeline
description: Embedding-based pipeline that aligns EN and RU texts into sentence-level meaning matches, plus the manual editor.
tags: [alignment, embeddings, pipeline, jobs, filament]
status: stable
stale_after: 2026-10-26
generated: { by: agent/opencode, at: 2026-08-16T14:00:00Z }
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
   seamless: incomplete trailing UTF-8 bytes are held back
   (`carryIncompleteTrailingBytes`) and re-prefixed to the next chunk) and
   writes `EnEntitySentence` / `RuEntitySentence` rows
   (`SplitEntityFileSentences` job; `ProcessEntityFile` orchestrates file
   ingestion). Splitting itself (pysbd + title heuristics ported from the old
   PHP splitter) lives in python `ai/splitting/`. The python splitter hands
   pysbd buffered prose with **newlines selectively flattened**:
   `TypedSentenceSplitter::flush_buffer` joins buffered lines with `\n`, then
   `selective_flatten` collapses every newline that does not follow
   sentence-ending punctuation (`. ! ? … » " ' ) ” ’` — the curly closers
   U+201D/U+2019 included) to a space, keeping only
   real paragraph breaks. This is the synthesis of two competing constraints:
   flattening *all* newlines to spaces lets pysbd's quote-region heuristic
   merge long dialogue spans into a single "sentence" (a lone `"` left over at
   a line end swallows everything up to the next closing quote — the EN Book
   Thief split into 547 sentences incl. a 1761-char monster; preserving the
   boundaries after punctuation fixes it to ~1036 clean sentences), while
   keeping *every* newline makes pysbd treat hard-wrapped prose lines
   (each paragraph wrapped at ~70 cols with a single newline) as separate
   sentences (the RU "Книжный вор 2" split into 9926 fragments; selective
   flatten collapses them to ~7148 real sentences, max 277 chars). The curly
   closers were the missing piece for Book Thief dialogue: lines close with
   `”`, so without them the newline after each dialogue line (and its blank
   line's `\n\n`) flattened to one/two spaces — two spaces break pysbd's
   `(?<=[!?.-]["'“”])\s{1}(?=[A-Z])` quote-end re-split and the whole dialogue
   block merged into a single 464-char "sentence". Regression
   test: `docker-compose/python/ai/splitting/test_splitter.py` (runs the
   splitter over the exact production entity fixtures for both languages plus
   a curly-quote dialogue block; fails under either extreme behavior).
2. **Sign** — `TextSignatureService` builds an embedding-based signature per
   entity (`GenerateEntitySignature` job) via `/embed` (**BGE-M3, 1024-dim**).
   `verifyEntityPair()` rejects pairs whose cosine similarity < **0.70** before
   alignment is attempted.
3. **Align** — `AlignEntitySentences::beginFromScratch($entityMatchId)` (the
    fresh-entry-point shared static) verifies the
    pair, snapshots counts, resets the cursor
   (`last_en_sentence_offset`/`last_ru_sentence_offset` on
   `EnRuEntityMatch`), transitions `pending → aligning`, and dispatches the
   first `AlignEntitySentences` job. Each `handle()` invocation reads the
   cursor from the model, slices one chunk of EN and RU sentences
   **sequentially** (RU offset = EN offset, no overlap), POSTs them to
   `/align` via `SentenceAlignmentService::alignChunkRemote()`
   (`services.python.align_timeout`, default 600), and writes the result via
   `storeAlignmentSegmentFromMatches()` (one `EnRuMeaningMatch` per DP step +
   `EnSentenceMeaningMatch`/`RuSentenceMeaningMatch` junction rows). The job
   commits only matches up to and including the **last confident anchor**
   (score ≥ `ANCHOR_SCORE_THRESHOLD` = 0.40): the DP force-aligns every
   sentence in the window, so the tail near the chunk seam can be garbage
   (5:1 then 1:5 at the seam) — dropping it and advancing the cursor to the
   anchor's `en_end`/`ru_end` (not the full chunk size) lets the next
   invocation re-align that tail with the correct RU context now in-window
   (a port of `BilingualAligner._trim_to_last_anchor`). With no anchor the
    whole chunk is committed to guarantee forward progress; on the final
    chunk (both windows reach their totals) everything is committed and the
    match flips to `completed`. Because the strict 1:1 window gives the DP no
    backward reach, the seam garbage re-appears at the *head* of the next
    chunk (a 1:5 span that scores as an anchor and survives trim). So before
    aligning, the job **rolls back** the last `ROLLBACK_MATCHES` (2) committed
    meaning matches: their rows are deleted (junction rows cascade via FK),
    the cursor rewinds to the first sentence those matches covered, the limits
    grow by the rolled-back spans, and the DP re-aligns that region with fresh
    forward context (see ADR 0004). Only matches with junction rows on both
    sides are candidates; skip steps and human-edit rows
    (`alignment_chunk = -1`) are never rolled back. A monotone-cursor safety
    net force-advances EN past the stored offset if a rolled-back commit would
    otherwise not move forward. `alignment_chunk` on meaning matches is a
    monotonic per-run id (`MAX + 1`, never the human-edit `-1` sentinel), so
    a re-run cannot wipe a previously committed chunk. If sentences remain
    the job `self::dispatch()`es the next invocation, otherwise the entity
    match flips to `completed`. Job timeout 600s, `tries=5` with backoff
    `[30, 60, 120, 300]` to heal transient python failures; `failed()` is
    terminal and leaves the cursor untouched so a future resume can continue.
    The standalone `AlignEntitySentenceChunk` job and the `Bus::chain()` fan-out
    were replaced by this single self-restarting job — see ADR 0003 / 0004.
    Small entities (`max(en_total, ru_total) ≤ 75`) are raised to a single
    chunk in `beginFromScratch()`, so the seam rollback/trim machinery is
    skipped for them entirely.
    Completion funnels through a single gate (`AlignEntitySentences::finalize()`,
    Aug 2026): before the match flips to `completed`, every sentence on the
    **original side** (`en_ru_entity_matches.is_original_en` — the language the
    text was authored in) that is still junction-less — dropped by an
    empty-commit seam, left over when the translation side was exhausted, or
    skipped during a re-align — is junctioned into a **single-sided meaning
    match** (`similarity 0.0`, next machine `alignment_chunk` id), ordered
    positionally (`SparseOrderService::spreadOrders` between the neighbouring
    junctioned anchors) so the reader's meaning-match sequence preserves the
    original document order. The repair is best-effort: if it fails, a warning
    is logged and completion proceeds regardless. `storeSkipSentences()` on
    `SentenceAlignmentService` persists single-sided rows, and the crawl's
    empty-commit seams (`alignWholePool`, `alignPoolChunk`) use it to junction
    the first uncommitted original sentence instead of silently advancing past
    it. "RU sentences exhausted before EN" is a normal completion now, not an
    error: the remaining original tail is drained as single-sided rows. This is
    the **original completeness** invariant — original sentences are never
    unmatched; only translation-side sentences may be junction-less (the
    editor's unmatched section).
3. **Align (precision knobs, Aug 2026)** — the python DP no longer
    force-aligns every sentence. Two live knobs
    (`docker-compose/python/env/.env`, apply on the next request):
    - **Skip branch** — `ALIGN_SKIP_PENALTY` (per-sentence cost of consuming
      a sentence without emitting a match; default `-0.5`). Edges that consume
      only EN or only RU sentences are now legal DP transitions, so a sentence
      with no counterpart is **skipped** (reported in `unmatched_en` /
      `unmatched_ru`) instead of being force-matched into a <0.6 garbage
      meaning match. The php gap-filling in `SentenceAlignmentService`
      converts those gaps to the existing `skip_en` / `skip_ru` steps, so no
      new persistence path was needed.
    - **Span cap** — `ALIGN_MAX_TOTAL_SPAN` (default `6`) rejects match edges
      whose `en_step + ru_step` exceeds the cap, dropping 1:5 / 5:1 spans.
    - `ALIGN_DEFAULT_THRESHOLD` raised `0.4 → 0.55` (MiniLM calibration; a
      live histogram of `meaning_match.similarity` showed a long garbage tail
      below ~0.55). Sub-threshold windows are now skipped rather than matched.
      With the **LaBSE** aligner (below) bitext cosine scores run hotter, so
      `ALIGN_DEFAULT_THRESHOLD` was provisionally set to `0.75` and
      `ALIGN_ANCHOR_THRESHOLD` to `0.8` (sanity-checked on the test pair).
      **Calibration (Aug 2026):** real LaBSE scores on adapted learning texts
      sit well below those provisional values — the The Gamblers titles
      (THE GAMBLERS ↔ ИГРОКИ) score 0.59, the full parenthetical titles
      0.67 (measured via `/align` on the running service). `0.75` silently
      skipped such genuine matches, so `ALIGN_DEFAULT_THRESHOLD` is back to
      `0.55` (the established MiniLM garbage-floor; LaBSE runs hotter than
      MiniLM, so 0.55 stays conservative) and `ALIGN_ANCHOR_THRESHOLD` to
      `0.6`. Still to be refined from a real `meaning_match.similarity`
      distribution once a live corpus is aligned.
    - **Embedding cache** — `EmbeddingCache` (LRU 10k) caches embeddings per
      process, keyed by model id + **normalized** text. With the default
      `window_embed=aggregate` (plan 05) the keys are the **single sentences**,
      so the prepass, window scoring, and re-alignment of chunk seams share one
      set of single embeddings; in `joined` mode the keys are the normalized
      joined window texts (the original behavior). Either way, chunk-seam
      re-alignment and entities that share source text no longer re-encode the
      same texts. Regression tests:
      `docker-compose/python/ai/alignment/test_aligner.py` (stub model).
3. **Align (algorithm, Aug 2026)** — the DP is now one of two aligners in
    `bilingual_aligner.py`, chosen by a live knob `ALIGN_ALGORITHM`
    (`greedy` default | `dp`):
    - **`dp`** — the original full-window DP. With a big aligner model (e.g.
      BGE-M3) it embeds every window, `(n + m) * max_window` encodes per
      `/align` call: ~4 min for a 100-sentence entity at `max_window=6`.
    - **`greedy`** — anchor-first. Embeds each single sentence once per side,
      builds the n×m sentence matrix, locks confident non-crossing 1:1 anchors
      (mutual-best within `max_window` each way, above `ALIGN_ANCHOR_THRESHOLD`,
      default 0.6 — 0.8 with the LaBSE aligner, provisional), then greedily
      walks the gaps between anchors via a **window ladder**: steps `1..primary`
      (`ALIGN_PRIMARY_WINDOW`, default 3) are compared as one set and the
      highest-scoring combo above threshold wins — a 1:1 is **no longer
      auto-committed the moment it clears the bar**, it must beat the other
      window combos up to `primary` — and if nothing in the primary set clears,
      the search widens one step per side up to `max_window`
      (`ALIGN_MAX_TOTAL_SPAN` still bounds `en_step + ru_step`). Otherwise skip
      the side whose best 1:1 partner within lookahead is weaker (the DP's skip
      semantics, localized). **Orphan-merge post-pass (Aug 2026)** — a locked
      anchor can be the head of a genuine multi-sentence window: the pooled
      window scores higher than the 1:1 but is never evaluated because the
      anchor pre-commits and the cursor jumps past it, leaving the tail as
      orphans (EN0↔RU0 1:1 at 0.701 pre-commits while the pooled EN0:2↔RU0
      scores 0.743 — exactly the pattern fusions produce, a partial 1:1 anchor
      + adjacent orphan). After the anchor/gap pass, `_merge_orphans` walks the
      matches and, for any match whose following gap has orphans on exactly one
      side (the other already consumed), extends the match's window over the
      orphan run — every extension length up to the bound (`max_window`,
      `ALIGN_MAX_TOTAL_SPAN`) is embedded lazily through the shared cache and
      scored; the best pooled window replaces the match only if it beats its
      score by `ALIGN_MERGE_MARGIN` (default 0.02). Embed count ≈ `n + m`
      singles plus a few window expansions per messy cursor — ~6× fewer on a
      clean text, so a 100-sentence entity drops from ~4 min to ~30–60s. The
      `matches` payload shape is unchanged, so `SentenceAlignmentService` and
      the job's anchor-trim/rollback need no changes. `/align` accepts optional
      per-request `algorithm` and `anchor_threshold` overrides. Regression
      tests assert greedy/DP equivalence on a clean list, 1:2 lazy expansion,
      skip behavior, anchor+gap resolution, the widening ladder (a 1:4 match
      that only clears past `primary`), normalized-window scoring, and the
      orphan-merge (2:1 beats a bar-clearing 1:1 → merged, no unmatched; the
      margin guard keeps a below-margin pooled window from over-merging), and
      count encoded texts (stub model).
3. **Align (reserved knobs, Plan 02, Aug 2026)** — four new `/align` request
    fields (all optional) and three new live config accessors were plumbed
    through end-to-end. `high_confidence` is **consumed by plan 03's prepass**
    (below); `band_width` and `window_embed` by plans 04 and 05 (below); and
    `landmarks` by plan 06 (below). The API passes them to
    `BilingualAligner(...)`, which resolves `None` from config and stores them
    as `self.high_confidence` / `self.band_width` / `self.window_embed`.
    - `high_confidence` (`ALIGN_HIGH_CONFIDENCE`, default `0.9`) — 1:1 prepass
      anchor bar (plan 03): mutually-best cells at/above this cosine get locked
      as committed matches that split the chunk into sub-pools.
    - `band_width` (`ALIGN_BAND_WIDTH`, default unset → derived per sub-pool
      as `max(2, max_window)`) — diagonal-band half-width (plan 04): match
      edges restricted to a band around the expected length-ratio diagonal.
      **Consumed by plan 04 (below).**
    - `window_embed` (`ALIGN_WINDOW_EMBED`, default `aggregate`, coerced from
      anything not `aggregate|joined`) — multi-sentence window embedding mode
      (plan 05): `aggregate` (per-sentence vectors combined length-weighted +
      L2-normalized) vs `joined` (the legacy join-then-embed).
      **Consumed by plan 05 (below).**
    - `landmarks` (`list[AlignLandmark]`, default `[]`) — hard landmark pins
      (plan 06): human-made committed matches with `score 1.0` that split
      sub-pools and can never be crossed by machine output. Pins carry only
      the four index spans (`en_start`/`en_end`/`ru_start`/`ru_end`); invalid
      pins (crossing/out-of-range/zero-length) → 422.
    `window_embed` rejects invalid values with 422; `high_confidence` is bounded
    `[0,1]`, `band_width` `[1,50]`.
3. **Align (high-confidence prepass, Plan 03, Aug 2026)** — before either
    algorithm runs, `BilingualAligner._align_pair` embeds the chunk's single
    sentences once and locks **prepass anchors**: non-crossing, mutually-best
    1:1 cells at/above `high_confidence` (`ALIGN_HIGH_CONFIDENCE`, default
    `0.9`) — the same scan/monotonicity rules as the greedy `anchor_threshold`
    anchors, but a higher bar (shared core `_find_anchors(sim, n, m, threshold)`;
    `_prepass_anchors` wraps it with `high_confidence`). `_align_with_anchors`
    then splits the chunk into **sub-pools** at the anchors (anchors are part of
    no pool) and aligns each pool **in isolation** with the chosen algorithm —
    greedy runs its gap/anchor/orphan logic on the slice (its internal
    `anchor_threshold` anchors still apply inside the pool), dp runs its DP on
    the pool's in-band windows (reused through the cache; plan 04) — emitting
    the anchors themselves as committed 1:1 matches with
    their cell cosine scores, concatenated in strict document order (pool
    matches, then anchor, then next pool). No match can consume sentences on
    both sides of a locked pair. The DP path embeds the chunk's
    singles once for the prepass matrix (it no longer precomputes the full
    chunk's windows — plan 04); the greedy path embeds the chunk's
    singles once and reuses them per pool. Regression tests
    (`test_aligner.py`) assert the identical anchor set for greedy and dp on
    known ≥0.9 pairs, that pools stay between anchors in document order, and
    that lowering `high_confidence` locks more anchors (raising locks fewer).
    `high_confidence` remains a live knob: `/align` per-request override or
    `ALIGN_HIGH_CONFIDENCE` in `docker-compose/python/env/.env`, applied on the
    next request.
    - **Text normalization (Aug 2026)** — every sentence is normalized once at
      alignment entry: `BilingualAligner._align_pair` runs
      `_normalize_sentences` (casefold + keep only alphanumerics/whitespace,
      unicode-aware + collapse whitespace) on both lists, then hands the
      normalized lists to the algorithm dispatch. `align_lists()` and the
      demo/`process()` path both route through this single normalization point,
      so windows, the anchor matrix, gap ladder, orphan-merge and the embedding
      cache all operate on the identical normalized text — the internal window
      code never re-normalizes (joining pre-normalized sentences *is* the
      normalized window text, and normalization is idempotent, so raw and
      pre-normalized inputs align identically). LaBSE/MiniLM score raw
      punctuation/case-heavy pairs far below their normalized form — the
      "The whistler" ↔ "«СВИСТУН»" pair scores ~0.32 raw but ~0.685 normalized
      (above `ALIGN_DEFAULT_THRESHOLD`), so normalization fixes those false
      negatives. The embedding cache is keyed on the **normalized** joined text
      (DP and greedy share the same keys). Stored DB text is untouched — the
      PHP side persists the raw sentences; only the embedded/scored form is
      normalized (and the `_match` diagnostic text).
3. **Align (diagonal banding, Plan 04, Aug 2026)** — per-sub-pool **match
    edges** are restricted to a diagonal band around the expected length-ratio
    line. Inside each sub-pool the expected ratio is `k = len(sub_en) /
    len(sub_ru)` and a cell `(i, j)` is in-band when
    `abs(j * k - i) <= band` (`_band_allowed`); the half-width comes from the
    live `band_width` knob (`ALIGN_BAND_WIDTH`, default unset → derived per
    sub-pool as `max(2, max_window)`). Effects:
    - **DP** (`_align_chunk`): match edges are gated by their **start cell** —
      an edge is only considered when `(i, j)` is in-band. Skip edges stay
      unbounded by design (skipping is always legal; only pairing is confined
      to the diagonal). Windows whose start position appears in no in-band cell
      are never embedded at all (`_in_band_starts` filters the starts passed to
      `_generate_window_embeddings`), so the plan cuts embedding cost too, not
      just candidate edges.
    - **Greedy** (`_align_chunk_greedy`): the internal `anchor_threshold`
      anchors (`_find_anchors`), the gap window ladder (`_best_window_pair`,
      gated on the window **centers**
      `abs((j + ru_step/2)*k - (i + en_step/2)) <= band`, and steps that can
      pair into no in-band combo are never embedded), and the skip decision
      (`_should_skip_en`, whose lookahead slices are clamped to in-band cells)
      are all banded. When the cursor is out of the band on both axes, the
      walk skips toward the expected diagonal (`return j * k > i`) so it
      re-enters the band instead of drifting.
    - **Prepass anchors are deliberately unbanded** — the high-confidence
      prepass can lock a pair anywhere on the full singles matrix; only
      per-pool match edges are confined to the band.
    - **DP cost drop** — the DP path no longer precomputes the full chunk's
      `(n + m) * max_window` windows: it embeds only the chunk's singles for
      the prepass matrix, and each pool embeds only its own in-band windows
      through the cache. On a clean list (all pools 1×1) the DP now encodes
      exactly the `n + m` singles, matching greedy.
    Regression tests (`test_aligner.py`, stub model) assert: an out-of-band
    pair rejected by a tight band but accepted by a wide one; an in-band pair
    accepted at the band edge; recovery to the far diagonal pair across a
    divergent middle (default derived band); the `band_width` knob controlling
    match density (1 vs 2 matches, dp == greedy); and the encode-count
    reduction (11 vs 16 in `joined` mode with the band on a 6×1 chunk — in
    `aggregate` mode the band has nothing left to suppress: 7 encodes either
    way).
3. **Align (aggregate window embedding, Plan 05, Aug 2026)** — multi-sentence
    window vectors are built in one of two modes, per the live `window_embed`
    knob (`ALIGN_WINDOW_EMBED`, default `aggregate`, coerced from anything not
    `aggregate|joined`):
    - **`aggregate`** (default) — each single sentence is embedded **once**
      through the shared cache (`_sentence_vectors`); every window vector is a
      **length-weighted, L2-normalized mean** of its sentence vectors
      (`_aggregate_window`: weights are the window sentences' character counts,
      so a long sentence dominates the average; a step-1 window reduces to the
      already-normalized single). The cache key is `(model id, normalized
      single text)` — the same key the prepass's step-1 windows use — so the
      prepass singles, the anchor matrix, the gap ladder, and the orphan-merge
      all share one embedding set and **no joined window text is ever
      embedded**: the encode count per `/align` is ≈ `n + m`, independent of
      banding or window expansions.
    - **`joined`** (legacy) — today's join-then-embed of the normalized window
      text (cached by joined text), one encode per window text on cache miss.
      Banding still suppresses out-of-band window *texts* in this mode (11 vs
      16 encodes on the 6×1 stub); the lazy per-cursor ladder steps hold in
      both modes.
    `_window_vector` is the single routing point: `_generate_window_embeddings`
    and `_embed_windows` both hand every window to it, so all paths (DP in-band
    windows, greedy ladder/orphan expansions) honor the knob. Equal-length
    window sentences reproduce the old pooled-mean scores exactly (the stub
    tests assert the same rankings as before); differing lengths shift scores
    toward the longer sentence — the point of the mode. LaBSE smoke
    (`testen.txt`/`testru.txt`, greedy, first 20 lines each): aggregate 9
    matches mean 0.624, joined 10 matches mean 0.571. Regression tests
    (`test_aligner.py`, stub model) assert: aggregate ranks the correct 1:2
    fusion window highest; weights follow sentence lengths (a long sentence
    dominates the pooled average — unit test on `_aggregate_window` plus an
    end-to-end 2:1); per-sentence vectors are cached (counting stub: each
    unique single encoded once, the pooled window adds zero encodes); and a
    joined-mode smoke keeps the join-then-embed path (4 texts, no single
    sharing).
3. **Align (landmark pins, Plan 06, Aug 2026)** — `/align` accepts hard
    **landmark pins** (`landmarks: list[AlignLandmark]`): human-made committed
    matches given as index spans into the submitted lists
    (`{en_start, en_end, ru_start, ru_end}`, no `score` — pins are always
    emitted with score 1.0). On re-align the PHP side (plan 07+) passes the
    human-edited rows as pins so the machine can never produce output that
    crosses or overlaps them. Semantics in `BilingualAligner`:
    - **Validation** (`_validate_pins`, called from `_align_pair` against the
      submitted list lengths) rejects — with `ValueError`, translated to 422
      by `api/align.py` — pins that are zero-length (`en_end <= en_start` or
      `ru_end <= ru_start`), out of range, or that **cross/overlap** another
      pin (sorted by `en_start`, any pin whose EN or RU span intersects the
      previous pin's is rejected; a pin that merely shares a sentence is
      contradictory and cannot both be honored). Pins are pairwise disjoint by
      construction, so the boundary union stays sorted and non-crossing.
    - **Prepass skip** — `_prepass_anchors` drops any high-confidence anchor
      cell inside a pin's rectangle (`range(en_start,en_end) ×
      range(ru_start,ru_end)`): a pin owns its cells, the prepass never
      proposes an anchor inside one.
    - **Sub-pool boundaries** — `_align_with_anchors` builds the union of pins
      + prepass anchors as the sub-pool boundaries: pins delimit the top-level
      gaps and only the prepass anchors that lie entirely inside one gap split
      it further (an anchor touching or crossing a pin is dropped), so machine
      output can never overlap a pin **by construction** — pools sit strictly
      between boundaries. Pins are emitted verbatim with `score 1.0`, in strict
      document order alongside the pool matches and anchor matches. Unmatched
      lists exclude pinned indices automatically (pins are matches).
    - Honored in **both** `greedy` and `dp` modes (the boundary logic runs
      before either algorithm dispatches on the pools).
    Regression tests (`test_aligner.py`, stub model) assert: the pinned pair is
    emitted verbatim with score 1.0 (both algorithms); the prepass skips pinned
    cells and **no machine match overlaps a pin** (a strong 1:2 pin that would
    otherwise lure the machine into its rectangle); pinned indices are excluded
    from `unmatched_en`/`unmatched_ru`; and crossing / out-of-range /
    zero-length pins are rejected (`ValueError` → 422 via the API).
3. **Align (landmark passthrough, Plan 07, Aug 2026)** — the PHP alignment
    client now accepts the Python knobs:
    `SentenceAlignmentService::alignChunkRemote($en, $ru, $maxN = 3,
    array $landmarks = [], ?float $highConfidence = null)` passes them to
    `/align` as `landmarks` (list of `{en_start, en_end, ru_start, ru_end}`
    ints) and `high_confidence`. When neither is given the payload is
    **byte-identical to the previous shape** (no `landmarks`/`high_confidence`
    keys are sent), so existing callers and the request format are untouched.
    `AlignEntitySentences` reserves `LANDMARK_THRESHOLD = 0.90` (inert until
    plan 08 turns human-edited rows into pins on re-align). Feature tests
    assert the keys appear in the payload when passed and are omitted when not.
3. **Align (landmark-aware re-align, Plan 08, Aug 2026)** — the entry-point
    split. Fresh alignments use `beginFromScratch()` (the original `begin()`:
    verify, wipe **all** meaning matches, snapshot totals, raise small
    entities to a single chunk, dispatch). Re-aligning a finished match uses
    the new `begin()`: it no longer wipes every row — it deletes only machine
    rows below `LANDMARK_THRESHOLD` (0.90), leaving human-made rows
    (`alignment_chunk = -1`) and high-confidence auto-landmarks
    (`similarity >= 0.90`) pinned in place, resets the cursor, and
    re-dispatches. Matches that never went through the fresh setup
    (`en_total_sentences` is null) delegate to `beginFromScratch()`. `handle()`
    is now **pool-aware**: `landmarkRows()` collects every landmark (human +
    auto), `landmarkBounds()` turns them into non-overlapping pools (a 1:N
    landmark span merges into one boundary; bounds clamp to the snapshot
    totals; landmarks with no junction rows are ignored), and each pool is
    aligned independently so a landmark is never crossed or re-aligned.
    Inside a pool the seam machinery still applies: the whole-pool fast path
    (single `/align` call) is taken only when the pool fits one window **and**
    no rollback candidates remain inside it; otherwise `alignPoolChunk()`
    rolls back the last machine matches (`rollbackCandidates()`:
    `alignment_chunk != -1`, similarity < 0.90, junctioned on both sides
    within the pool, last `ROLLBACK_MATCHES` = 2), re-aligns, and
    self-dispatches. The whole-pool fast path also self-dispatches: `handle()`
    persists the cursor via `persistOffsets()` and returns after **each pool**,
    so a re-align of many small landmark-delimited pools runs as one queued
    job per pool instead of one long job draining every pool in a single
    `handle()` (a match with 141 pools previously ran one ~2m16s job). Pools
    larger than `chunk_size` on either side skip the fast path and are still
    drained chunk-by-chunk across jobs by `alignPoolChunk()`. Feature tests
    (`ReAlignPreservesLandmarksTest`) assert
    human rows and auto-landmarks survive a re-run, `beginFromScratch()`
    wipes everything, and 1:N human landmark spans never overlap a machine
    window.
3. **Align (landmark tiers)** — re-align treats two classes of meaning-match
    rows as **landmarks** (pins that survive a re-run and partition the match
    into non-overlapping pools, see Plan 08):
    - **Hard (human-made)** — rows created or edited by a human via the
      alignment editor (`AlignmentEditorController` /
      `AlignmentEditorPersister`) carry `alignment_chunk = -1` and
      `similarity = 1.0`. They are never deleted, never rolled back, and never
      re-aligned — the machine cannot cross them.
    - **Auto** — machine rows whose `similarity >= LANDMARK_THRESHOLD`
      (`AlignEntitySentences::LANDMARK_THRESHOLD = 0.90`) are promoted to
      landmarks on re-align: they survive the delete-only-sub-threshold wipe
      and become pool boundaries exactly like human rows.
    Both tiers feed `landmarkRows()` → `landmarkBounds()`, so both are pool
    boundaries; the only difference is what Re-align deletes (machine rows
    below the bar) and what it keeps (both tiers). "Run from scratch"
    deletes both tiers.
3. **Align (split Filament actions, Plan 09, Aug 2026)** — the single
    destructive **Re-run** table action on the `EnRuEntityMatch` resource is
    replaced by two explicit confirmation actions, both visible only for
    `status ∈ {completed, failed}`:
    - **Re-align** (`realign`, warning) — calls `begin()`: preserves
      human-made rows (`alignment_chunk = -1`) and confident landmarks
      (`similarity >= LANDMARK_THRESHOLD`, 0.90) and re-aligns only the
      low-confidence gaps. Its confirmation modal reports the live counts,
      e.g. `"{N} human-made + {M} confident row(s) preserved; only
      low-confidence rows will be re-aligned."`.
    - **Run from scratch** (`rerunScratch`, danger, `heroicon-o-trash`) —
      calls `beginFromScratch()`: deletes **all** meaning matches including
      human-made ones and re-runs the whole pipeline. Its modal always states
      that everything is deleted, and adds the human-made count when > 0.
    Filament tests (`FilamentReAlignActionTest`) assert both actions'
    visibility per status, their confirmation copy, and the entry-point
    dispatch each triggers.
4. **Schedule** — `Schedule::command('alignments:resume')->everyFiveMinutes()
   ->withoutOverlapping()` picks up to 10 `status='pending'` matches per tick
   and runs them through `AlignEntitySentences::begin()`. Without-overlap
   prevents concurrent ticks colliding with each other. Set
   `DB_QUEUE_RETRY_AFTER=900` so the database queue does not re-lease a
   long-running chunk to a second worker mid-flight.
5. **Order** — sentences and matches carry sparse order values managed by
   `SparseOrderService`; `entity-orders:rebalance` runs **daily** (see
   `routes/console.php`).
6. **Review** — humans fix machine output in the Filament
    `EnRuEntityMatch` resource's custom `EditEntityAlignment` page (kept as-is),
    or in the new Inertia/React **Alignments editor**: `/alignments` (pair list)
    → `/alignments/{id}` (pair editor), linked from the NavBar. The editor is a
    parallel entry point backed by the surgical `AlignmentEditorController`
    endpoints — create/delete pair, approve pair (set `similarity = 1.0` +
    `alignment_chunk = -1`, promoting a row to a hard landmark), add/edit/
    unlink/hard-delete sentence, and
    `sentences/move` (within-row reorder / cross-row relink / to-or-from the
    unmatched pool) — with immediate persistence, sparse orders via
    `SparseOrderService`, and JSON payloads shaped by `AlignmentEditorApiPresenter`
    (`rows` + `unmatched` pagination, `last_page` included; the rows table's
    `Pagination` component shows Prev/Next + numbered page buttons with ellipsis
    and a custom per-page dropdown). Below the unmatched pool, the editor shows a
    collapsible **Needs review** section (collapsed by default) listing meaning
    matches a human should inspect: rows whose `similarity < 0.55`
    (`AlignmentEditorApiPresenter::LOW_SIMILARITY_THRESHOLD`) or that are
    **one-sided** (junctions on exactly one side, any similarity — see the
    original-completeness repair above). Each row shows its `#order`,
    `similarity`, EN/RU parts, a `1-sided` badge, and a `→ p. N` marker; clicking
    it jumps the editor's rows table to the exact page (`ceil(rank / per_page)`,
    the server returns page-independent per-row `rank`) and briefly highlights
    the row (client-side scroll, no URL change). Paginated 25/page via
    `GET /alignments/{entityMatch}/needs-review`
    (`AlignmentEditorController::needsReview`, `NeedsReviewRequest`); the section
    refetches its current page after every editor mutation. The editor never
    rewrites an existing
    sentence's document order: dragging edits the row's **junction** orders
    only, so `en_entity_sentences.order` keeps reflecting the original text and
    a later Re-align places sentences correctly. Each row still shows the raw
    `order` of its sentences. Once a run lands,
    the Filament list's **Re-align** / **Run from scratch** actions (Plan 09)
    restart it — preserving or wiping the human work respectively. Re-align
    deletes non-landmark machine rows and re-creates them in document order, so
    a within-row switch on a non-approved row is reset by it.
7. **Sentence editing** — individual entity sentences can be created, edited,
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
  - **Aligner model** `ALIGN_MODEL_PATH` (code default
    `paraphrase-multilingual-MiniLM-L12-v2`, 384-dim; the repo `.env` currently
    points it at **LaBSE**, `sentence-transformers/LaBSE` → `/app/models/labse`,
    768-dim) — used by `/align`. MiniLM is ~2.5–3× faster on CPU than BGE-M3;
    LaBSE (like MiniLM vs BGE-M3) was thought to run bitext cosine scores hot
    (the `testen.txt`/`testru.txt` smoke test scored 0.77–0.97, mean 0.83), so
    thresholds were moved up with it (`ALIGN_DEFAULT_THRESHOLD = 0.75`,
    `ALIGN_ANCHOR_THRESHOLD = 0.8`). A subsequent check on adapted learning
    texts (The Gamblers) showed genuine meaning matches scoring only
    0.59–0.67, so both were **re-lowered to 0.55 / 0.6** (Aug 2026) — LaBSE's
    "hot" distribution does not hold across all texts, and 0.55 is the
    established MiniLM garbage-floor. A big aligner is only affordable
    because the default `ALIGN_ALGORITHM=greedy` embeds each sentence once
    rather than every window. **LaBSE cap:** its tokenizer caps at 256 tokens
    (`max_seq_length=256`); a window built by joining long sentences can exceed
    that (a 3-sentence join in `testen.txt` hit 270 tokens) and is silently
    truncated to the head 256 tokens, dropping the tail — a known quality note,
    not a crash. Windows are single/joined sentences up to `max_window=3`, so
    over-cap joins are rare on clean prose but plausible on long messy sentences.
* **Model weights** live in a named Docker volume `ai_models` mounted at
  `/app/models/<subdir>` (BGE-M3 at `/app/models/bge_m3`, MiniLM at
  `/app/models/minilm`, LaBSE at `/app/models/labse`). The image carries no
  models; the source tree carries none (the old
  `docker-compose/python/ai/bge_m3_local/` is gitignored/removed).
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
   `align_default_window()`, `align_primary_window()`, `align_max_total_span()`,
   `align_skip_penalty()`, `align_algorithm()`, `align_anchor_threshold()`,
   `align_merge_margin()`, `align_high_confidence()`, `align_band_width()`,
   `align_window_embed()`, `model_path()`, `align_model_path()`) re-read
   `/app/env/.env` on every call —
   edits apply on the next request with **no recreate or restart**.
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
