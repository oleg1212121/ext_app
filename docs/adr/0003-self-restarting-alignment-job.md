# Self-restarting alignment job replaces `Bus::chain()` fan-out

Status: accepted

The alignment pipeline was reworked so a single `AlignEntitySentences` job
processes one chunk per invocation and re-dispatches itself to process the
next chunk, replacing the previous fan-out design in which
`AlignEntitySentences::handle()` built a `Bus::chain([...all chunks...])->dispatch()`
list up front plus a separate `AlignEntitySentenceChunk` job class for every
chunk.

The chained design had two problems. First, it had no resume: if any chunk
failed, `failed()` flipped the entity match to `failed` and the only recovery
was the Filament Re-run action, which wiped **all** meaning matches including
successful chunks and started from chunk 0. Second, it required the
coordinator to compute every chunk's `(enOffset, ruOffset, chunkSize,
ruWindowSize, isLastChunk)` up front, even though RU windows were already a
pure function of EN offsets — extra stored state that could drift.

The rework keeps `chunk_size` and `max_n` on `EnRuEntityMatch` but moves the
per-chunk resume cursor onto the model itself (`last_en_sentence_offset`,
`last_ru_sentence_offset`). `handle()` reads the cursor from the model,
slices and aligns one chunk, advances the cursor, and either marks
`completed` or `self::dispatch()`s the next invocation. The cursor is **not**
reset on failure, so a future resume can continue from where the pipeline
stopped. The `failed()` hook is terminal (status `failed`), but in-process
retries (`tries=5` with `backoff=[30,60,120,300]`) heal transient python
failures before the hook fires.

A new `alignments:resume` console command runs every five minutes
(`Schedule::command('alignments:resume')->everyFiveMinutes()->withoutOverlapping()`),
picks up to 10 entity matches with `status='pending'`, verifies each via
`SentenceAlignmentService::verifyEntityPair()`, transitions them to
`aligning`, snapshots totals, resets the cursor, and dispatches the first
chunk. The Filament Re-run action and all the "new alignment" Filament dispatch
sites share a single `AlignEntitySentences::begin($entityMatchId)` static
that wraps verify + snapshot + transition + dispatch.

Trade-off: the rework introduces N+1 queue roundtrips (one dispatch per
chunk) versus the previous single dispatch that fanned everything out at
once. With `chunk_size=75` and `timeout=600` per chunk on the `database`
queue, the cost is negligible relative to the python `/align` call each
chunk makes, and the resumability and simpler failure surface were judged
worth it. `DB_QUEUE_RETRY_AFTER` was bumped to `900` in `.env.example` so
the database queue does not re-lease a long-running chunk to a second
worker while the first is still busy.