# ADR 0064: SentenceOrderService — one placement pipeline, one cosine

Date: 2026-10-04
Status: Accepted

## Context

Entity sentences carry a sparse document `order` (stride 1024, unique per
entity). `SparseOrderService` owns the placement *math* — `between()`,
`orderForInsertAfter()` with its windowed-then-full rebalance escalation,
`spreadOrders()`, the two-phase `rebalanceAll()` — but every interactive
caller re-implemented the pipeline around it: load the entity's sentences,
resolve the insertion anchor, call `orderForInsertAfter`, shift the result
non-negative, persist every changed order two-phase (park at unique
negatives so the `(entity_id, order)` unique index never sees a transient
collision). Three sites, three drifting copies:

- **`EntityController`** (entities frontend insert/reorder) had the full
  dance as private helpers (`resolveAfterOrder`, `shiftOrdersNonNegative`,
  `persistSentenceOrders`), anchoring through the wire convention
  (`after_sentence_id` `0` = beginning, `null` = end).
- **`AlignmentEditorService::placeSideSentence`** (the ADR 0062 editing
  domain) carried verbatim inline copies of the shift and the two-phase
  persist — and, unlike the ADR 0033 writer list ("the alignment-editor
  persister, reorder"), never bumped `entities.sentences_updated_at` after
  the persister rewrite, so an editor drag that changed document order left
  the text-hash staleness signal untouched.
- **The Filament `SentencesRelationManager`** had a structurally different
  third dance: raw `between()` with a straight-to-`rebalanceAll` escalation
  (a full-scope DB rewrite for one crowded insert), **single-phase writes**,
  and no negative-order guard — Filament-created sentences could carry
  negative orders, surfacing as negative display numbers, and a mid-write
  failure could trip the unique index.

The signature-similarity side had the same drift: two implementations
comparing the same data (json-decoded `entities.signature` LaBSE unit
vectors) — `TextSignatureService::cosineSimilarity()` (true cosine,
zero-norm guard, untested) and a private `cosineSimilarity()`/`dotProduct()`
pair in `SentenceAlignmentService` (normalization by assumption). The python
service also still carried `/cosine/batch` — built for the duplicate-
detection path ADR 0033 deleted — and `/embed/batch`, neither called by
Laravel (ADR 0061's `PythonClient` has only `split`/`align`/`enrich`/
`embed`).

## Decision

**1. Two layers, by model coupling.** `SparseOrderService` stays the pure,
model-agnostic primitive layer (it also serves meaning-match rows via
`AlignmentEditorService`/`MeaningMatchStore`, the importer, the splitter and
the rebalance command). Everything entity-sentence specific moves into
`SentenceOrderService`: loading the entity's sentence orders, resolving the
anchor, the `orderForInsertAfter` escalation, the non-negative shift, the
two-phase persist, and the `sentences_updated_at` bump (bulk writes bypass
model events — the service owns the ADR 0033 contract: every order change
feeds the text hash, whose recompute sweep keys on
`sentences_updated_at > text_hashed_at`).

**2. `SentenceAnchor` — the explicit "where".** A sealed value object
(`App\Enums\SentenceAnchor`) with named constructors `beginning()`,
`end()`, `after(int $sentenceId)` — a plain enum cannot carry the After
case's payload. `SentenceOrderService::place(entityId, anchor,
movingSentenceId)` is the id-level interface; the wire conventions
translate at the edge. `placeAfterOrder(entityId, afterOrder,
movingSentenceId)` stays available for the alignment editor's placement
engine, which anchors on clamped raw order values (row boundaries), not
sentence ids. Anchor ids are resolved against the entity's own loaded
orders — a foreign sentence id throws `ModelNotFoundException` (404); the
HTTP requests validate that a non-zero `after_sentence_id` exists at all
(422).

**3. One caller, one call.** `EntityController` and
`AlignmentEditorService::placeSideSentence` collapse to single service
calls; `markMatchesStale`/`syncTotalsForEntity` stay at the call sites —
they are mutation-flow concerns, not ordering ones. The Filament relation
manager loses `computeOrder` entirely: Create calls `place()`; Edit calls
it only when the chosen insert position differs from the record's current
predecessor (a content-only edit no longer renumbers the sentence —
previously it snapped to the midpoint between its neighbours), inside a
transaction, gaining the two-phase write and the non-negative guard.
Meaning-match row orders are untouched (ADR 0062/0063 just settled those
write paths).

**4. One cosine.** `TextSignatureService::cosineSimilarity()` — hardened
with `min(count($a), count($b))` defensive truncation alongside the
zero-norm guard — is the single implementation, now unit-tested.
`SentenceAlignmentService` injects the service and calls it from
`verifyEntityPair()`; the 0.70 verify threshold stays in the alignment
domain. Identical scores for current unit-vector data.

**5. Dead python endpoints removed.** `/cosine/batch`
(`ai/api/cosine.py`, `ai/similarity/cosine.py`, `COSINE_MAX_CANDIDATES`)
and `/embed/batch` (`EmbedBatch*` schemas, `TextSignature.generate_batch`,
`EMBED_BATCH_MAX_TEXTS`) are deleted; the service exposes `/split`,
`/align`, `/enrich`, `/embed`, `/health`. Code-only change — bind-mounted
source, no image rebuild or deploy stamp.

## Consequences

- Adding a sentence-mutation surface means one service call; the unique-
  index safety, non-negative display orders, rebalance escalation and the
  text-hash staleness contract cannot be forgotten per site.
- Deliberate behavior changes on the Filament path: no more negative orders
  from the relation manager, no full-scope rebalance for a single crowded
  insert (windowed instead), and no renumbering on content-only edits.
- Editor drags now bump `sentences_updated_at`, feeding the existing
  text-hash recompute sweep — restoring ADR 0033's writer list after ADR
  0062's rewrite dropped it.
- `entity-orders:rebalance` (ADR 0055's audited-writers list) is
  unaffected: `SparseOrderService::rebalanceAll` and the command are
  unchanged, still position-preserving.
- Python loses ~80 lines of dead surface; `TextSignature.compare` remains
  (used by the local demo/scratch scripts).
- Tests: `SentenceOrderServiceTest` pins beginning/end/after placement, the
  non-negative shift, exhausted-gap rebalance under the unique index, and
  the `sentences_updated_at` bump/no-op behavior; an editor-API test pins
  the drag bump; cosine math tests cover identical/orthogonal/zero/
  mismatched-length vectors.

> **Amendment (2026-10-05): one two-phase persist primitive.**
> The 2026-10-05 architecture-review pass found the two-phase parking write —
> the part of the ordering invariant with the subtlest failure mode — still
> spelled out at four sites in two drifting formulas
> (`-($id + 1_000_000_000)` vs the editor's bare `-$id`). This lifts decision
> 3's "meaning-match row orders are untouched" for the shared *mechanics*
> only: the editor's and the pipeline's row-write domains stay separate, but
> the write idiom gets one home.
>
> - **`SparseOrderService::persistOrdersTwoPhase(modelClass, updates)`** owns
>   the park-then-final write inside one transaction (a savepoint under a
>   caller's transaction), chunked at 1000. Rewired onto it:
>   `SentenceOrderService::persistChanged` (which keeps the
>   `sentences_updated_at` bump), `SparseOrderService::rebalanceAll`,
>   `MeaningMatchStore::resequenceMatchesByDocumentPosition` (whose deletes
>   share its own transaction), and `AlignmentEditorService::
>   persistRowOrderChanges` — whose bare-`-$id` formula retires with the
>   drift.
> - **`rebalanceAll` becomes transactional** — the consequence above ("the
>   command are unchanged") narrows: `entity-orders:rebalance` still parks
>   position-preserving, but its parks are no longer visible to concurrent
>   readers (the command is scheduled daily).
> - `SparseOrderServiceTwoPhaseTest` pins the past-each-other swap, savepoint
>   nesting, rollback-with-caller, and the empty no-op.
