# ADR 0044: Per-user processing limits

Date: 2026-09-27
Status: Accepted

## Context

Any approved user can create entities and alignments without limit. Each
entity upload runs a multi-job pipeline (split → hash → signature, ADR 0033)
and each alignment runs the potentially half-hour chunked alignment pipeline —
all on the shared `default` lane, most of it calling the Python service. A
single user can saturate the queue and the service for everyone. We cap how
much one user may have processing at once: 2 entities, 1 alignment. Admins are
exempt, and the numbers must stay changeable without touching code.

## Decisions

### 1. Entities get an explicit status column; alignments already have one

`entities.status` is `processing | completed | failed`. An entity is born
`completed` when created without a file (nothing to do) and `processing` when
an upload starts the pipeline. It becomes `completed` when the upload pipeline
finishes (sentence split + text hash + signature); it becomes `failed` when
any pipeline job exhausts its retries (a new `failed()` hook on each pipeline
job). Scheduled enrichment (word index, frequency counts) never re-enters
`processing` — the status tracks the upload pipeline only, which is the work a
user waits on. The old `signatureStatus()` display derivation stays. Entity
matches keep their existing `status` column (`pending | aligning | completed |
failed`).

### 2. Alignment ownership is explicit: `entity_matches.created_by`

A match previously had no owner — only the two side entities' uploaders, who
can differ. A nullable `created_by` is added, set at every creation point to
the authenticated user (Filament actions included — those are admin-only).
Existing rows are backfilled from the a-side entity's uploader. The alignment
limit counts matches the user created, regardless of whose entities they pair.

### 3. In-flight is defined per kind; the limits gate creation only

A user's in-flight entity count is `entities` with `created_by = user` and
`status = 'processing'`. In-flight alignments are `entity_matches` with
`created_by = user` and `status IN ('pending', 'aligning')`. The limits are
checked only at creation entry points (the two Inertia entity stores and the
alignment store). Accepted gaps:

- Sentence mutations flip existing matches back to `pending` (re-alignment)
  without any limit check; re-driving an existing pair is not creation.
- Matches frozen by the approved-entity freeze (ADR 0034) stay `pending`
  indefinitely and hold their creator's slot until approved.
- A match stuck `aligning` (job chain died before `failed()`) keeps holding
  its slot; `alignments:resume` does not re-queue `aligning`.
- Admin-only Filament re-align/re-run actions skip the limits (admins are
  exempt anyway).

### 4. Limits come from config; admins bypass

`config/limits.php` holds `entities_processing_per_user` (default 2) and
`alignments_processing_per_user` (default 1), each env-overridable — the
house pattern for configurable numbers. An approved admin skips every check,
matching the EntityAccessService bypass.

### 5. Checks are race-safe at the creation choke points

Count-then-create runs inside a transaction with the creator's user row
`lockForUpdate`-ed, from `EntityCreationService::create()` and
`LibraryController::storeAlignment()`. Two parallel submissions by the same
user cannot both pass the count. Exact-copy fast paths are born `completed`
and never count or check — except a clone whose source was still mid-pipeline
(it gets its own signature pass, so it is born `processing` and consumes a
slot).

## Consequences

The limits are per-user totals, not per-work. A slot frees only through
status: pipeline completion/failure for entities, `completed`/`failed` for
matches. An entity stuck `processing` (worker killed before the job's
`failed()` hook ran) holds a slot until an admin re-runs the signature action
or deletes it — accepted, same exposure the pipeline already had. Changing a
limit is a config change plus the usual deploy cache rebuild.
