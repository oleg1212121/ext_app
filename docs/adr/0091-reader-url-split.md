# ADR 0091: The reader URL splits into work-scoped entity and alignment readers

Date: 2026-10-27
Status: Accepted

## Context

Since [0037](0037-reading-side-rule-and-reader-route.md) reading was one
entity-anchored route, `GET /reader/{entityId}`, that **auto-detected** its
mode from the entity's match: if the entity sat in an `EntityMatch` whose
both sides the user could read, the page rendered bilingual rows; otherwise
it fell back to monolingual. Three problems accumulated on that shape:

1. **The URL did not say what you would get.** One address was two different
   readers, and the choice was made by alignment state that changes
   independently of the URL — a later alignment silently turns a
   bookmarked monolingual page into a bilingual one.
2. **The entry index organized by the wrong axis.** The language-tab index
   (`GET /reader/{lang?}`, ADR
   [0038](0038-practice-menu-revives-reader-index-and-simulator-picker.md))
   listed texts per language, but every entity belongs to a **work** since
   ADR [0018](0018-works-and-unified-language-keyed-tables.md) — the same
   axis every other surface had already moved to (the alignment editor,
   ADR [0072](0072-work-nested-alignment-editor-routes.md); the entity
   pages, ADR [0073](0073-work-nested-entity-routes.md)).
3. **The side rule leaked into link building.** A Library alignment card
   had to precompute a `reader_target` — re-running the server's reading
   side rule (`EntityMatch::readingSideFor`) at payload time to decide
   whether Read linked the bilingual reader or a monolingual one — so the
   rule lived in two places and a card's link could disagree with what the
   page then rendered.

Constraints carried from the earlier route moves: single-user, auth-gated
tool, no external deep links to preserve — old URLs die without redirects
(the house removal style of ADRs 0072/0073); saved reading positions must
survive any URL change (they are keyed by `mm:{matchId}`/`ent:{entityId}`,
not by URL); and a match has no `work_id` of its own — the work comes via
its a-side entity (canonical a/b, lower entity id = a).

## Decisions

### 1. An explicit work-scoped route tree; the mode lives in the URL

Reading becomes six named routes, all under the `['auth','approved']`
group, all id segments `whereNumber`:

| Route | Name | What it is |
|-------|------|------------|
| `GET /reader` | `reader.index` | The **works-first entry**: the public works catalog with readable-scoped counts — every work, empty ones included (ADR 0021) |
| `GET /reader/works/{work}` | `reader.works.show` | The work's **sections page**: header + Entities/Alignments cards |
| `GET /reader/works/{work}/entities` | `reader.works.entities.index` | The work's readable texts (search, language filter) |
| `GET /reader/works/{work}/alignments` | `reader.works.alignments.index` | The work's readable completed/stale matches (search, either-side language filter) |
| `GET /reader/works/{work}/entities/{entity}` | `reader.works.entities.show` | The **entity reader** — always monolingual |
| `GET /reader/works/{work}/alignments/{match}` | `reader.works.alignments.show` | The **alignment reader** — always bilingual |

The split is the point: entity reading is **always monolingual** (one row
per sentence, no translation column, no language radio — even when the
entity sits in a readable match) and alignment reading is **always
bilingual** (the match's meaning matches, no single-language fallback).
Which reader an address renders is readable from the URL, and the work
segment is fully verifiable server-side: an entity is scoped with
`where('work_id', …)`; a match is anchored through
`aEntity.work_id === {work}` (404 otherwise) — wrong-work URLs 404 like
every other work-nested surface. Readability gates stay per surface:
`EntityAccessService::canRead` for the entity reader, `canReadMatch`
(both sides) for the alignment reader.

Alternative rejected: a `?mode=` query on the old single route — the mode
would stay invisible in links and untypeable in bookmarks, and the
controller would keep both readers behind one auto-detect.

### 2. The side rule lives only server-side, applied inside the page

Alignment cards (Library and the reader's own alignments list) link the
match-anchored URL **unconditionally**; `reader_target` is deleted from
the Library payload. `readingSideFor(nativeLanguageId)` — native side,
else the work's original side, else the a-side — runs once inside
`alignmentShow` and ships as `defaultSide`; the canonical a/b rows (ADR
0060) never reorder, and the client flips display state around the
default as before (ADR 0037 amendment). Link and page agree by
construction because there is nothing left to disagree.

### 3. Old reader URLs are removed without redirects

`reader.show`, the language-tab index route, their page components and the
controller's auto-detect machinery are deleted — consistent with the ADR
0072/0073 removals. After the split, `/reader/{anything}` (a bare id, a
language code, or the old `{lang}/{entityId}` shape) resolves to nothing:
404 for approved users, pinned by tests. Alternatives rejected: redirects
from `/reader/{entityId}` — they would have to guess the intended work
branch from an entity that may sit in several matches, and the tool has no
external link corpus to protect.

### 4. Cross-links bridge the split

An entity's bilingual entry point is no longer its own URL — it is its
work's alignments list. The entity reader's header carries a cross-link
to `/reader/works/{work}/alignments`, present only while the work has at
least one match the user may read at status `completed` or `stale`
(pending/aligning/failed have no meaning-match rows to read) — the same
rule everywhere via one shared query builder, `Support\ReadableWorkAlignments`
(a-side work scope + completed/stale + `readableMatchConstraint`): the
works list's per-card alignment counts, the sections page's Alignments
card, the cross-link's presence and the alignments list's total all
compute from it, so they agree by construction. The alignment reader
cross-links each side's entity to its monolingual reader. The back button
stays browser-history back; cross-links are explicit header links.

## Consequences

- **Bookmarked old reader URLs break** (accepted, per decision 3): the
  bare-id reader, `/reader/{lang}` and `/reader/{lang}/{entityId}` all
  404. `/reader` itself survives with new content (the works list), so the
  navbar link needed no change.
- **Saved reading positions survive untouched.** The position keys were
  already `mm:{matchId}`/`ent:{entityId}` (ADR 0032) and the split
  deliberately reused them: a bookmarkless but position-bearing reader
  opens on the same page it was left on, under the new URL. This was the
  one piece of user state the removal could have destroyed; it wasn't.
- **Monolingual reading of a matched entity costs one more click** (reader
  → alignments list header link → monolingual reader of the side). That is
  the trade for the mode being explicit; the cross-link keeps it to one
  hop, and only appears when a bilingual reading actually exists.
- **The side rule has one home.** Deleting `reader_target` means no
  surface but the alignment reader decides a reading side; UI labels
  dropped the embedded language code ("Read in ru" → plain "Read").
- **The count rule is one query builder.** Any new surface counting a
  work's readable alignments must use `ReadableWorkAlignments`, or card
  counts and list totals drift apart again.
