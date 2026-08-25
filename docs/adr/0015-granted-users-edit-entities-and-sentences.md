# Granted users may edit entities and sentences in the entities frontend

The entities frontend (`/entities`) was previously a read-after-create surface:
edit and delete were admin-only (Filament). Granted users (creators and
signature-match grantees) had a stake in entities they held a grant on but no
way to refine them post-upload without admin intervention, and Public entities
had no non-admin editor at all.

We extend **Access grant** to mean read **and** edit (no schema change — the
existing pivot row records "this user has a stake in this entity"). The edit
rule mirrors read: a Restricted entity is editable by admin and grantees; a
Public entity is editable by any approved user. This adds `entities.edit` /
`entities.update` Inertia routes plus four JSON sentence endpoints (insert,
update content+type, cascade delete, drag reorder) on a combined edit page at
`/entities/{lang}/{entity}/edit`.

## Consequences

- **Boundary shift.** The entities frontend is now a full editing surface, not
  read-after-create-only. `wiki/domains/entities.md`'s "Edit/delete remain
  admin-only" line is superseded by this ADR.
- **Original-completeness invariant relaxed for this surface.** The alignment
  editor forces unlink-before-delete (422 if linked) to preserve
  original-completeness. The entities frontend instead **cascades** — deleting a
  junctioned sentence removes its junctions, deletes any meaning match left
  empty, and updates `linked_count`. The sentence models' `deleting`/`deleted`
  hooks already perform this cascade. Original-completeness no longer holds
  after a junctioned original-text sentence is deleted here; the match is
  flagged `pending` so a re-align restores it. This divergence from the
  alignment editor is deliberate.
- **Document order is now mutable by non-admins.** Drag-to-reorder is a fourth
  mutator of `*_entity_sentences.order`, alongside the admin Sentences tab,
  import, and `entity-orders:rebalance`. It reuses `SparseOrderService` and
  preserves sparse ordering.
- **Match staleness.** Every sentence mutation (insert / update / delete /
  reorder) flips `status = 'pending'` on all `EnRuEntityMatch` rows involving
  the entity, surfacing the need to re-align via Re-run / `alignments:resume`.
  The entity `signature` (an upload-time dedup artifact) is intentionally left
  stale — it is not an ongoing-integrity signal.
- **Sentence CRUD is JSON-driven**, mirroring `AlignmentEditorController`:
  endpoints return the updated sentence list rather than issuing Inertia
  redirects, because drag-and-drop requires JSON. The metadata form stays
  Inertia (PATCH → redirect to `entities.show`).

## Future work (known gaps, provisional)

Public-edit plus cascade-delete by any approved user, with no audit trail
(Q9: no `created_by`/`updated_by`, no edit log) and no soft-delete, carries
vandalism and accident risk on shared canonical texts. This is accepted for v1
per the user's "for now" framing; revisit when moderation becomes a real
concern. A per-sentence "linked in alignment #N" indicator was also deferred.

## Considered options

- **Separate Edit grant** (new `can_edit` pivot column / new pivot table) —
  rejected: "stake = edit" is simpler and the grant already records the
  relationship.
- **Creator-grant-only** (`similarity IS NULL`) — rejected: signature-match
  grantees have equal standing.
- **Publish-freezes-edit** (Public → admin-only) — rejected by the user;
  Public means freely editable by approved users for now.
- **Soft-delete + audit columns** — rejected as over-engineered for v1.
- **Per-sentence "linked" indicator** — rejected by the user for v1.
- **Inertia form submits for sentence CRUD** — rejected; drag-and-drop requires
  JSON transport.
