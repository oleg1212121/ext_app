# ADR 0065: EntitySentenceStore — one sentence-mutation flow

Date: 2026-10-05
Status: Accepted (owns the mutation-flow concerns ADR 0064 left at call sites; corrects ADR 0062's totals bullet)

## Context

A sentence-set change is not just the write. The domain expects a flow:
mutate the sentence → flip every match involving the entity to `stale`
(ADR 0055) → resync the image-less totals (ADR 0062) → the text-hash
staleness signal (ADR 0033, via the model events and
`SentenceOrderService`). ADR 0064 explicitly left the stale flip and the
totals resync at the call sites ("they are mutation-flow concerns, not
ordering ones"). That left the flow a convention, hand-composed per door,
and the doors had drifted:

- **`EntityController`** (entities frontend): the four sentence endpoints
  each ran the mutation in a transaction and then composed
  `markMatchesStale()` + `EntityMatch::syncTotalsForEntity()` *after* the
  transaction — a crash between mutation and propagation left a match
  half-updated. `markMatchesStale` was a private controller method,
  structurally invisible to other doors. A content-only sentence update
  ran with no transaction at all.
- **The Filament `SentencesRelationManager`**: totals-only, via four
  `->after()` hooks. It **never flipped matches stale** — while ADR 0062's
  decision section claimed "the entity CRUD paths (`EntityController`,
  `SentencesRelationManager`) already flipped matches stale", the wiki's
  sentence-alignment page repeated the claim, and the
  `syncTotalsForEntity` docblock asserted it too. A completed match edited
  through the relation manager stayed `completed` with no re-align
  signal: ADR 0055's scheduler-safety invariant did not hold on that
  door, and its test suite had no stale assertions to notice.
- **`AlignmentEditorService`**: exempt by design — ADR 0062's no-stale
  rule ("editor edits leave a stale match stale") — but the rule lived in
  ADR prose and a controller docblock, pinned by no test, and the
  editor's sentence content-edit was a bare model write sitting in the
  controller rather than in the mutation service.
- **`EntitySentenceImporter`**: wipes and rebuilds inside its own
  transaction and marks the match `completed` itself — legitimately
  outside the flow.

## Decision

**1. One store owns the flow.** `App\Classes\EntitySentenceStore`
(named after `MeaningMatchStore` — the established name for a write-path
owner) exposes `insert`, `update`, `delete`, `deleteMany`, `reorder`.
Each method is one transaction wrapping the whole flow: placement (via
`SentenceOrderService`), the model write (the sentence model events bump
`sentences_updated_at` and run the junction cascade), the stale flip, and
the totals resync. Authorization and the anchor wire conventions
(`after_sentence_id` at the entities frontend, the `insert_after` select
in Filament) stay at the doors. The illustration rules (stray file on a
non-illustration ignored; replaced image released after commit) move into
the store with the writes they belong to.

**2. The relation manager now flips matches stale.** ADR 0055's rule was
always stated generically about sentence mutations; the Filament gap was
drift, not design. This is a visible behavior change: the stale badge
now appears after relation-manager edits. It makes ADR 0062's totals
bullet, the wiki, and the docblock true instead of aspirational.

**3. The editor keeps its own write path — encoded, not remembered.**
`AlignmentEditorService::updateSentenceContent()` (moved out of the
controller) is the editor's one content write, with the ADR 0062 no-stale
rule in its docblock; tests pin both directions (a stale match stays
stale, a pending match stays pending) and the `sentences_updated_at`
bump. The editor deliberately does not route through the store: its
invariants are different by decision, not by omission.

**4. The importer stays exempt**, recorded here so the exemption is a
decision rather than an accident.

## Consequences

- The stale flip and totals resync are atomic with the mutation on every
  door that uses the store; a new sentence-mutation surface calls it and
  inherits the flow, or must justify its own the way the editor and the
  importer do.
- `markMatchesStale` has exactly one home (private in the store); the
  pending-stays-pending rule is verbatim unchanged (ADR 0055).
- Content-only sentence edits on the entities frontend now run inside a
  transaction (previously a bare model write).
- Documentation corrected rather than re-argued: ADR 0062's totals bullet
  carries an amendment note, the `EntityMatch::syncTotalsForEntity`
  docblock no longer claims the relation manager raised stale, and the
  wiki's sentence-alignment page describes the one flow.
- Tests: `EntitySentenceStoreTest` pins the flow (stale flip across
  `completed`/`aligning`/`failed`, pending preserved, totals resync,
  `sentences_updated_at` bump, placement) and its atomicity (a failed
  anchor or a failing write rolls the propagation back); the relation
  manager suite pins stale on create/edit/delete/bulk-delete; the editor
  API suite pins the no-stale rule.
