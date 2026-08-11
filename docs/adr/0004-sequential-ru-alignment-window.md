# Sequential RU alignment window replaces drift-aware overlap

Status: accepted

The alignment pipeline used to give each EN chunk a wider RU window via
`AlignEntitySentences::RU_WINDOW_OVERLAP = 25`: RU offset was proportional
to EN progress, padded by ±25 sentences on each side. This forgave positional
drift between EN and RU sentence orders — a paragraph that translated to 12
EN sentences might land as 14 RU sentences, so chunk `i` on each side could
be off by a handful of sentences. The DP aligned within the windowed RU
range, and the overlap was how it reached back when the EN side had moved
ahead of the RU side.

The rework drops the overlap. The RU window now tracks the EN window
strictly: same `offset`, same `limit` (clamped to `ru_total_sentences`). One
EN offset maps to one RU offset; one EN chunk maps to one RU chunk of the
same size. This is the assumption: the importer (`ImportSimulatorEntitiesCommand`
and friends) splits EN and RU text with `pysbd` and the resulting
`{en,ru}_entity_sentences.order` is treated as a 1:1 positional
correspondence through the text. EN sentence at order *k* is expected to mean
roughly the same thing as RU sentence at order *k*.

Trade-off: any drift in the importer's EN/RU ordering now degrades
alignment quality silently, since python's DP `max_window` is 6 and cannot
"reach back" across the chunk boundary the way the ±25 overlap allowed.
The wins were simplicity (no `ruWindowForEnRange()` helper, no derived
state to keep in sync with the resume cursor) and a stored cursor
(`last_ru_sentence_offset`) that advances exactly with
`last_en_sentence_offset`, which is what makes the self-restarting job
work — `handle()` resumes both sides at the same position without
recomputing a window.

### Drift is handled by trim-to-last-anchor, not by overlap

The strict window makes the chunk seam the one place the DP *cannot* reach
across, so `AlignEntitySentences` ports `BilingualAligner._trim_to_last_anchor`
instead: each invocation commits only matches up to and including the last
match whose score is `>= ANCHOR_SCORE_THRESHOLD` (0.40, mirroring python's
`similarity_threshold`), and advances the cursor to that anchor's
`en_end`/`ru_end` — not to `offset + chunk_size`. The dropped tail is
force-aligned by the DP, so committing it would produce the 5:1 / 1:5
garbage observed at chunk seams; leaving it out lets the next invocation
re-align it with the correct RU context now in-window. Two guarantees keep
the pipeline total:

- **Forward progress**: if no match reaches the threshold, the whole chunk
  is committed anyway (the DP always emits ≥ 1 match, and each match
  consumes ≥ 1 sentence per side).
- **Last chunk**: when both windows reach their totals
  (`enOffset + enLimit >= enTotal && ruOffset + ruLimit >= ruTotal`) there
  is no next iteration to absorb a seam, so everything is committed and the
  match flips to `completed`.

Because the cursor no longer advances by a fixed chunk size, `alignment_chunk`
on meaning matches is a monotonic per-run counter (`MAX(alignment_chunk) + 1`,
never the human-edit `-1` sentinel) instead of `intdiv(enOffset, chunkSize)`,
so the per-chunk idempotent delete in `storeAlignmentSegment` can never wipe
a previously committed chunk.

### Backward context via commit-rollback, not read-window overlap

Trim alone fixes the seam on only one side: it drops the *tail* of the
current chunk, but the garbage re-appears at the *head* of the next chunk.
The strict 1:1 window gives the DP no backward reach, so the first sentence
of the new window is force-aligned into the same 1:5 / 5:1 span — and this
time it is an "anchor" (score >= threshold) that trim cannot drop because it
sits at the start, not the end.

So each invocation also rolls the last `ROLLBACK_MATCHES` (2) committed
meaning matches back into the window before aligning: their rows are
deleted (junction rows cascade via FK), the cursor is rewound to the first
sentence those matches covered, the limits grow by the rolled-back spans
(forward reach unchanged), and the DP re-aligns that region against fresh
forward context. Only real matches (junction rows on both sides) are
candidates; skip steps and human-edit rows (`alignment_chunk = -1`) are
never rolled back.

Two guarantees keep the pipeline total:

- **Monotone cursor**: the commit cursor is never rewound behind the stored
  offset. If a rolled-back commit ends before the pre-rollback offset (a
  degenerate DP result), the job force-advances EN by one past the stored
  offset so it cannot pinwheel.
- **Progress despite rollback**: rollback only widens the window; the
  no-anchor and last-chunk rules still commit everything, so each
  invocation still moves at least one sentence per side.

Every invocation re-evaluates the previous 2 matches, so a match is
rewritten at most twice (once as its own chunk's tail, once as the next
chunk's rollback) before it stabilizes — bounded churn, no schema change.

Reversal path: re-introduce `RU_WINDOW_OVERLAP`, resume computing the RU
window from `(enOffset, enLimit, enTotal, ruTotal)`, and add a separate
`last_ru_sentence_offset` advance rule that accounts for the overlap
padding. Do this only if drift is observed in production; the simulator
importer is the only known producer and its sentence positions are pinned
by `pysbd` per side. The anchor-trim and commit-rollback steps stay
regardless — read overlap does not remove force-aligned seams, it only
widens the context available to the DP.