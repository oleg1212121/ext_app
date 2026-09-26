# ADR 0043: Bounded job runs with durable progress markers

Date: 2026-09-26
Status: Accepted

## Context

An audit of the queued jobs found that a single `handle()` could process an
unbounded number of rows: the sentence splitter inserted one row per sentence
of a user-uploaded file inside one 180 s job (re-splitting the whole file on
every retry), the text-hash step loaded an entity's entire sentence set into
memory, the word linker ran two unbounded dictionary queries per unlinked
word, and the alignment pipeline resequenced the full match after every
75-sentence chunk (quadratic reads across a run). Total upload size was also
uncapped on the Filament path. The harms to prevent are worker memory blowups
and DB/external-API load — not raw worker time — and the natural unit
"affected rows" splits into one run vs a whole pipeline.

## Decisions

### 1. Every queued run has a run budget; unbounded pipelines self-re-dispatch

One `handle()` invocation processes at most a fixed budget (byte chunks,
word rows) and then either finishes or re-dispatches itself. Long work is
spread over many small runs — the pattern `AlignEntitySentences` already
used — never one big job. Total input is additionally capped at the source:
uploads are limited to 10 MB everywhere (Filament `FileUpload` now matches
the `max:10240` form requests), which bounds every downstream pipeline.

### 2. Progress markers are DB columns committed atomically with the work

The splitter's resume point (`entities.split_offset` + `split_remainder`) is
written in the same transaction as the sentences it produced. A cache key
was rejected: a cache flush would silently restart a split from byte 0 and
re-delete sentences. The marker records the bytes fully fed to python plus
the splitter's unsplittable remainder — a byte offset alone cannot resume
mid-sentence. `ProcessEntityFile` zeroes the markers, so a re-uploaded file
always splits from the start.

### 3. Uniqueness-locked jobs continue via the scheduler, not self-dispatch

`RefreshEntityWords` is `ShouldBeUnique`: dispatching itself from inside
`handle()` would be swallowed by the still-held unique lock, silently
stalling the pipeline. Continuation rides the existing `crossword:refresh`
sweep, which re-picks entities with unlinked words every five minutes. For
that sweep to terminate, tokens with no dictionary entry are stamped
(`entity_words.unmatchable_at`) and skipped thereafter; dictionary imports
clear the stamps (`clearUnmatchedForLanguage`) so a grown dictionary can
link them, and `crossword:link --retry-unmatched` does the same manually.
The indexer stays a single-transaction full rebuild — budgeting it would
either break its atomicity or redo work forever.

### 4. Alignment cleanup happens once at finalize, not per chunk

`resequenceMatchesByDocumentPosition()` (order normalization + subsumed
duplicate deletion) ran after every chunk persist — re-reading both
entities' full sentence lists each time, quadratic across a pipeline. It
now runs only in `finalize()` (which already existed as the completion
gate). Accepted consequence: mid-run, `order` stays append-after-max and a
subsumed duplicate lingers until completion — nothing consumes mid-run
order (pool partitioning sorts by document position, not `order`), and
`alignments:resequence` remains the manual repair.

### 5. Full-pass reads iterate in document order, or are capped

The text hash streams sentences with `orderBy('order')->cursor()` +
incremental `hash_update`, applying the same per-sentence normalization as
before so existing digests don't churn. It must never switch to
`chunkById`: id order ≠ document `order` after a rebalance or human edit,
and the digest would change. Sweep-style dispatches get `--limit` caps
(`entity:generate-signatures` gained one defaulting to 100; the scheduled
`entity-orders:rebalance` runs with `--limit=500`).

## Consequences

Per-run alignment reads (sentence indexes, landmark rows) stay O(n) per
run — accepted, bounded by the ≤10 MB upload cap and inherent to the
resumable-chunk design. `split_remainder` can hold a chunk-sized text tail
for boundary-free texts. Job timeouts are sized to a run budget
(`SplitEntityFileSentences`: 8 × 256 KB chunks under a 600 s timeout, inside
`DB_QUEUE_RETRY_AFTER=660`), not to the whole file.
