# ADR 0063: MeaningMatchStore — the pipeline's write path, split from the adapter

Date: 2026-10-04
Status: Accepted

## Context

`SentenceAlignmentService` had grown to 971 lines doing five jobs: the remote
`/align` call, python-match→links/dpPath adaptation, meaning-match
persistence (`persistSegment`), document-position resequencing with its
junction-dedupe keeper election, and junction-less repair. The 2026-10-03
architecture review flagged the file as the pipeline's shallow spot; two of
the five jobs have since been peeled off by their own decisions — transport
lives in `PythonClient` (ADR 0061) and the human-editing domain lives in
`AlignmentEditorService` (ADR 0062, whose docblock already called the
aligner pipeline a write path of its own). What remained was a class whose
half is a stateless python-call adapter and whose other half owns every DB
write of the pipeline, with no dependency between the halves except three
shared pieces:

- the `links` / `dpPath` array shapes — the de-facto serializer of the
  chunk contract, built by `buildCommittedPath`/`buildSkipOnlyPath` and
  consumed by both adaptation and persistence;
- `LANDMARK_THRESHOLD`, mirrored on the job (`AlignEntitySentences::
  LANDMARK_THRESHOLD`) and reaching Filament through that mirror — a third
  home for a domain constant that ADR 0062 had already started moving onto
  the models (`HUMAN_CHUNK`, `syncLinkedCount`);
- two dead public methods (`storeLinks`, `storeAlignmentSegment`) with zero
  callers in app code or tests.

## Decision

- **Two classes.** `SentenceAlignmentService` becomes the python-match
  adapter: `verifyEntityPair` (the signature gate), `alignChunkRemote` (the
  `/align` call + payload assembly), and the path builders
  (`buildCommittedPath`, `buildSkipOnlyPath`) — now **public**, because they
  are the seam the write path consumes. Their docblocks state the
  `links` (`{a_sentence_id, b_sentence_id, a_order, b_order, link_group,
  similarity, alignment_order}`) and `dpPath` step shapes once, as the
  contract between the halves. Plain arrays with docblock shapes stay the
  interface: `persistSegment` immediately regroups and re-sorts them, so a
  value object would add unwrapping ceremony for an internal seam.
- **`App\Classes\MeaningMatchStore`** is the pipeline's meaning-match write
  path: `storeAlignmentSegmentFromMatches`, `storeSkipSentences`,
  `resequenceMatchesByDocumentPosition`, `junctionlessSentencesFor`,
  `repairJunctionlessSentences` (signatures unchanged), with `persistSegment`,
  the junction-dedupe election, and `claimOrder` private. Named after the
  domain concept it writes (the glossary's meaning match), as the
  pipeline-side sibling of `AlignmentEditorService`. It injects the adapter
  for path composition and keeps the `create()` factory idiom. Each method
  owns its `DB::transaction` exactly as before — the job's `persistOffsets`
  nesting is untouched — and the deliberate mixed write idioms are documented
  on the class (Eloquent `create` where the junction `creating` hook must
  fire, quiet builder deletes, base-builder batched inserts).
- **Callers rewire directly; no facade.** The job, the two artisan commands
  (`alignments:resequence`, `alignments:repair`), and `AlignmentCopyService`
  call the store for write methods and keep the service for
  `verifyEntityPair`/`alignChunkRemote`. The dead `storeLinks` /
  `storeAlignmentSegment` surface is deleted, not moved.
- **`LANDMARK_THRESHOLD` homes on `MeaningMatch`** (sibling of
  `HUMAN_CHUNK`): the service's const and the job's mirror are gone; the
  job internals and the Filament Re-align modal read the model const.

## Consequences

- Adding a pipeline write concern means editing `MeaningMatchStore`; a
  change to how python output becomes links/dpPath means editing the
  adapter. The 971-line file is now ~270 (adapter) + ~600 (store), each
  with one reason to change.
- Behavior-preserving: method bodies moved verbatim (const references
  renamed), the existing suites are the acceptance bar —
  `ResequenceEntityMatchesTest` (pure-PHP resequence/dedupe),
  `ChunkedEntityAlignmentTest` (the job end-to-end), and
  `SentenceAlignmentServiceTest` (adapter payloads, retries) pass with only
  construction-site rewires; `PythonClientTest` is untouched.
- The threshold constant now has one home and one name in code and docs; a
  new consumer imports the model const instead of copying a value.
- The stale `wiki/playbooks/run-alignment.md` retry-delay attribution
  (pointing at `SentenceAlignmentService` after ADR 0061) is corrected in
  the same pass.
