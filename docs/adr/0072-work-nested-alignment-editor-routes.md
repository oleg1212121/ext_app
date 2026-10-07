# ADR 0072: The alignment editor moves onto the work branch

Date: 2026-10-07
Status: Accepted

## Context

The alignment editor's page lived at the flat `GET /alignments/{entityMatch}`
with eleven JSON endpoints beside it (`/alignments/{id}/rows`, `/unmatched`,
`/needs-review`, `/approve`, the sentence endpoints). The browse/create
surfaces had already moved under each work (`/works/{work}/alignments`,
ADR [0039](0039-library-dropdown-works-branches.md)), so a reader landing on the editor
saw a URL that named neither the work nor the section it belonged to — and
the entity match is not a free-floating object: both of its entities belong
to exactly one **Work** (creation enforces same-work), so the work is a
stable, meaningful parent for the URL.

Meanwhile the editor kept its pagination purely in React state: four
independent paginations (rows with per-page, unmatched A, unmatched B,
needs review) all reset to page 1 on reload, and a deep link always opened
the top of the table. Reviewing row 400 of a 1 500-row alignment meant
paging through by hand every visit. Two more editor gaps surfaced in the
same pass: the rail buttons were text-only, and there was no way to mark a
pairing as wrong — approve promoted a row to a similarity-1.0 landmark, but
no mirror action existed.

Constraints:

1. `entity_matches` has no `work_id`; the work is reached through the
   A-side entity (`aEntity.work_id`). Every nested route must resolve and
   verify that relationship itself.
2. The editor's JSON API is called by the page's own fetch wrapper with
   hand-built URLs (no route helper on the client).
3. Re-align (`AlignEntitySentences::begin`) deletes every row below the
   landmark bar (`similarity >= 0.90`) regardless of the human-chunk
   sentinel — human rows survive only because every editor mutation trusts
   them at similarity 1.0.

## Decisions

### 1. All editor routes nest under the work: `/works/{work}/alignments/{match}/...`

The page becomes `GET /works/{work}/alignments/{entityMatch}/edit` (route
name `works.alignments.edit`), and all eleven JSON endpoints move to the
same prefix. Every controller action binds `Work $work` and 404s unless
`$entityMatch->aEntity?->work_id === $work->id` — a match path under the
wrong work is indistinguishable from a missing one. The JSON API carries
`work_id` in the match payload, so the client builds the base path
(`/works/{id}/alignments/{matchId}`) once and derives every endpoint from
it. New action: `POST .../rows/{row}/disapprove` —
`AlignmentEditorService::rejectRow` sets `similarity = 0.0` and leaves
`alignment_chunk` untouched: a rejection is a number, not a permanent human
verdict, so the existing Re-align delete pass (below 0.90) removes the row
and its sentences re-pair on the next run. Needs review keeps its pure
similarity rule — a rejected row (0 < 0.55) stays listed as to-fix work.

Alternatives rejected: page-only nesting with the JSON API left flat (two
URL vocabularies for one feature — the inconsistency the change exists to
remove); adding a `work_id` column to `entity_matches` to make the check a
route-level binding (denormalizes the canonical A-side path for no query
gain — the A-side entity is always loaded anyway).

### 2. The old flat routes are deleted without redirects

`GET /alignments/{id}` and the eleven old endpoints are gone outright —
old links 404. The editor URL appears in shared links only (a single-user
auth-gated surface behind an approved-users gate), the DOM entry points
were all updatable in one pass (alignment cards, entity page, duplicate-match
flash link, Filament edit actions), and a method-preserving 308 across
eleven mutation endpoints would keep dead route weight in the table
forever for a redirect no legitimate client would follow.

Alternatives rejected: 301/308 redirects for everything (no crawler value,
fragile method+body preservation on mutations); keeping the flat page route
as an alias (the ambiguity of two canonical URLs is what prompted the
change).

### 3. The editor's pagination state lives in the URL query

Each section's page — `rows_page`, `rows_per_page`, `unmatched_a_page`,
`unmatched_b_page`, `review_page` — is seeded server-side on page load
(validated by `AlignmentEditorPageRequest`, clamped to the last page when
stale or hand-edited, in the spirit of the reader's `?page=` handling) and
mirrored client-side with `history.replaceState` on every page/per-page
change, so copying the URL shares the exact view without server round-trips
per click. Defaults are omitted to keep URLs clean. Section open/closed
state deliberately stays client-local: it is presentation, not position.

Alternatives rejected: Inertia partial-reload pagination on every page
change (a server round-trip per click for a fetch-driven editor already
holding live state); `useRemember` (state in history entries is invisible
in the address bar and dies with the tab — the opposite of shareable).

## Consequences

- Every editor URL and all editor test traffic now names the work; the
  work↔match relationship is verified server-side on all twelve routes.
- Alignment cards, the entity page, the duplicate-match link and the
  Filament edit actions build the nested URL; the match payload's
  `work_id` is the single source for both the client base path and the
  back link to `works/{work}/alignments` in the page header.
- Sharing a URL reproduces all four paginations after reload; a stale or
  hand-edited page lands on the nearest valid page instead of an empty
  table.
- The row rail is icon-only (approve ✓, disapprove ⊘, create-below ＋,
  delete 🗑) and rows can be approved in place from the needs-review list,
  which removes them immediately (similarity 1.0).
- Old `/alignments/{id}` links 404 by design (Decision 2).
