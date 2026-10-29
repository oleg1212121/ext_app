# ADR 0092: Alignment cards stop at a read-only show page

Date: 2026-10-28
Status: Accepted

## Context

Since the editor moved onto the work branch (ADR
[0072](0072-work-nested-alignment-editor-routes.md)), an alignment had
exactly two surfaces: the Library work-branch list and the drag-and-drop
editor. The list's cards wired all three entries at once — the whole card
was a stretched link into the **editor**, and each card carried **Read**
(into the match-anchored reader, ADR
[0091](0091-reader-url-split.md)) and **Simulator** (into the pinned
simulator) buttons. Two costs:

1. **The card's biggest click was the most dangerous one.** Clicking
   anywhere on a card dropped the user straight into the dnd editor — a
   destructive-adjacent surface (rows can be moved, deleted, approved) —
   to do the common thing: look at an alignment.
2. **There was no place an alignment could be *about*.** Metadata
   (similarity, status, progress, sentence totals) lived only as card
   summaries, and every deep link had to choose a tool up front.

Naming blocked the natural fix. The route table had two wrong names: the
per-work list was `works.alignments.show` (it lists, it doesn't show one)
and the editor was `Alignments/Show.jsx` + `AlignmentController@show`
(it edits). Meanwhile the global branch list — the works catalog framed
for alignments (ADR 0039) — already owned `works.alignments.index`.

Constraints: house removal style applies to URLs, not route *names* —
renaming a name moves no URL, so no links break; the reader side already
demonstrated the target shape (`reader.works.alignments.index` for the
work's list, `reader.works.alignments.show` for one match).

## Decisions

### 1. A read-only detail page sits between the list and the tools

`GET /works/{work}/alignments/{match}` (`works.alignments.show`) renders
`Library/ShowAlignment`: the pair's metadata (status, similarity,
confirmed/linked progress, sentence totals, created date, work link) and
exactly three actions — **Read** → the match-anchored reader,
**Simulator** → the pinned simulator, **Edit** → the editor. Guards
mirror the editor: 404 unless `aEntity.work_id === {work}`, 403 without
`canReadMatch`. The cards' Simulator/Read buttons are gone; the whole
card links the detail page, and the editor is reached only through its
Edit button.

### 2. The list takes `.index`; the branch's works list steps aside

`/works/{work}/alignments` renames `works.alignments.show` →
`works.alignments.index`, mirroring the reader side. The global
`/works/alignments` branch list — actually a works-catalog variant, like
`entitiesIndex` — renames `works.alignments.index` →
`works.alignments.overview` (its only name consumers are the two blade
nav links). No URL changes.

### 3. Editor naming stops lying

`AlignmentController@show` → `edit()`, `Alignments/Show.jsx` →
`Alignments/Edit.jsx`. "Show" now means one thing on this branch: the
read-only detail page.

Rejected: routing card clicks straight to the reader instead of a detail
page — reading is one of the three intents, and the card already showed
everything a detail page would; the point is a neutral landing that
*chooses* nothing on the user's behalf. Also rejected: renaming the
global list to keep `.index` on it — the branch that owns a show page
should own its index name too, and `overview` says what the page is (a
works list, not an alignment list).

## Consequences

- **One more click before editing** — that is the intent: the editor is
  a tool you opt into, not a landing page.
- **Post-create stays on the list** (`works.alignments.index`), and the
  create form's duplicate-match link now points at the new show page —
  everything enters an alignment through the same door.
- **The stale-name trap is gone**: `Show` no longer means the editor,
  and no route name collides with a different page shape.
- **The Filament admin links are untouched** — they deep-link the editor
  (`works.alignments.edit`), which is unchanged in URL and name.
- **`library.open_alignment_editor` is dropped**; the card's sr-only
  label reuses `entities.open_alignment`, and the detail page adds
  `library.edit` / `library.similarity` / `library.linked_rows` /
  `library.created`.

## Amendment 2026-10-29: the page gains an inline read-only bilingual view

The page kept its metadata-only shape for two days; matching it to the
entity detail page (`Entities/Show`) changed that shape without touching
any decision above:

- **Actions move into the header**, top-right like the entity page, in the
  order **Read · Simulator · Get PDF (completed only) · Edit** — the
  entity page's "Get PDF between Read and Edit" rule (ADR 0093) with
  Simulator slotted next to Read. The page also adopts the entity page's
  two-column metadata card grid.
- **The bilingual text renders on the page itself**: one row per meaning
  match, two side-by-side columns per row, **the work's original language
  left** (`EntityMatch::originalSide()` with the A-side fallback — the
  `BookPdfService` rule, ADR 0093), sides resolved server-side so the page
  cannot get it wrong. Read-only: no dnd, no edit affordances; unconfirmed
  and single-sided rows appear exactly as the editor and the PDF cover
  them. Pagination is the entity page's server-side prev/next on
  `?page=N` (20 rows/page, hidden on a single page), extracted into the
  shared `Components/SimplePagination.jsx` that the entity page's sentence
  list now uses too.

The editor remains the only mutation surface — the view adds *where* the
text can be seen, not another way to change it. Amending this ADR rather
than adding a new one: the page's purpose (a neutral read-only landing)
is unchanged, and the additions are individually reversible.
