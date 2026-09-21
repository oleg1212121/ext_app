# Entity uploader tracking and the approved edit lock

ADR 0013 deliberately omitted a creator column ("the uploader's access is a
grant row like any other"), and ADR 0015 made every Public entity editable by
any approved user, explicitly rejecting "publish freezes edit" — while noting
the vandalism risk as accepted-for-v1 future work.

Both positions are now superseded. Entities gain a nullable `created_by`
**uploader** reference (null = system/admin import; the creator grant pivot
stays as the access mechanism), and a boolean `is_approved` **edit lock**:

- An approved entity is frozen for content changes — metadata, sentences,
  every entity match it participates in (alignment-editor mutations,
  re-align/run-from-scratch, and the `alignments:resume` scheduler, which
  skips matches with an approved side), and deletion. Admins bypass via
  Gate::before (ADR 0008); the uploader is locked out like everyone else.
- The approval flag itself stays flippable in both directions by exactly two
  parties: the uploader and admins (a new `entities.approved.update` endpoint
  plus a Filament toggle). Un-approve → edit → re-approve is the intended
  maintenance loop for uploaders.
- `EntityAccessService::canEdit` gains the lock (approved ⇒ admin only);
  alignment-editor mutations move from `canReadMatch` to a new
  `canEditMatch` (both sides readable AND neither side approved).

## Consequences

- **Supersedes** ADR 0013's "intentionally no created_by" and narrows ADR
  0015's free-edit rule: Public-and-unapproved entities remain editable by
  any approved user; approved ones do not.
- **The lock is a freeze, not protection status.** Approval says "this
  content is good" — it is not a moderation state, has no workflow
  (no reviewer queue, no audit log), and confers no visibility change: a
  Restricted approved entity stays readable by grantees only, a Public one by
  everyone. Visibility remains `is_restricted` + grants, untouched (ADR 0013,
  ADR 0021).
- **The uploader is not an owner.** `created_by` identifies who uploaded; it
  grants exactly one power beyond a creator grant — flipping approval — and
  is lost if the user is deleted (null on delete; the entity survives).
- **Sentence staleness interplay**: sentence mutations are impossible on an
  approved entity, so its matches cannot be flipped pending through it; a
  match with an approved side and an edited *other* side stays pending until
  an admin un-approves, re-aligns, and re-approves. That stalled-pending
  state is the visible signal of the lock, not a bug.

## Considered options

- **A separate `is_private` visibility flag** — rejected: it would duplicate
  or contradict the existing Restricted/grants machinery; "private" is the
  Restricted default, now with a first-class uploader.
- **Approval as admin-only curation** — rejected by the user: the uploader
  may approve their own entity (self-approval is trust-the-uploader, not
  curation).
- **Policies (Laravel `Policy` classes)** — rejected for now: the codebase's
  access rules live in `EntityAccessService` + `abort_unless`, with the
  admin bypass in `Gate::before`; a policy layer would be a second
  enforcement path to keep in sync.
