# ADR 0093: Book PDFs — dompdf-rendered, digest-cached downloads

Date: 2026-10-28
Status: Accepted

## Context

The Library's entity and alignment pages ([ADR
0073](0073-work-nested-entity-routes.md), [ADR
0092](0092-alignment-show-page.md)) show texts only on screen. Learners want
the same content as a **book PDF to keep**: a monolingual book of one entity,
and a bilingual two-column book of a completed alignment. Requirements that
shaped every decision below:

- **Cyrillic must render.** The app's languages are en/ru/be; a renderer
  without Cyrillic-capable bundled fonts is out.
- **Repeat clicks must be cheap.** A book of a whole entity is dozens of
  pages; rendering it on every click is not acceptable — and neither is
  serving a stale book after the text was edited.
- **The file lands in Laravel storage.** The artifact is a first-class
  stored file (attachment download), not a transient browser blob.
- **No new moving parts.** The ML python container and the deploy stamps
  must not be involved.

## Decisions

### 1. The engine is dompdf, wrapped by `barryvdh/laravel-dompdf`

Pure PHP composer dependency — no container definition change, no deploy
consequences, hermetic in tests (no `Http::fake()` needed). dompdf's HTML
table support fits the bilingual two-column layout directly, and its bundled
**DejaVu Serif/Sans** fonts cover Latin + Cyrillic, which is why dompdf won
over the alternatives: render happens with `defaultFont: 'dejavu serif'`,
`isRemoteEnabled: false` (illustrations embed by local filesystem path, never
by URL). One service (`BookPdfService`) owns both books: blade views
(`pdf/entity-book`, `pdf/alignment-book`), the digests, and the cache.
WebP illustrations (dompdf cannot embed them) are GD-converted to a temp PNG,
degrading to the caption alone when GD lacks webp.

Rejected: **WeasyPrint in `ext_python`** — print-quality CSS, but it needs
pango/cairo system packages inside the ML container, a new endpoint there,
and cross-service faking in every test. **Client-side pdfmake** — rendering
in the browser fights the "file lands in Laravel storage" requirement; the
cache would hold whatever the browser POSTed back.

### 2. A synchronous download endpoint, no job machinery

`GET /works/{work}/entities/{entity}/pdf` and
`GET /works/{work}/alignments/{entityMatch}/pdf` render on the first click
(~1–3 s for a book) and stream the stored file afterwards. No polling, no
job states, no UI for render progress.

Rejected: **queued job + polling** — machinery whose only payoff is hiding a
one-time 1–3 s wait behind a progress spinner. **Eager pre-generation on
every content change** — renders books nobody downloads, on every sentence
edit.

### 3. Caching is one `pdf_renders` row per subject, keyed by a content digest

Each subject (entity, entity match) owns exactly one cache row — partial
unique index on the FK — plus **one fixed file path** on the `local` disk
(`pdfs/entities/{id}.pdf`, `pdfs/matches/{id}.pdf`): a stale render is
overwritten in place, files never accumulate. The row's `content_hash` is a
sha256 over what the book actually renders: the entity's fresh `text_hash`
(via `EntityTextHasher::refreshIfStale`, illustrations folded in through
their `image_hash` markers, [ADR
0033](0033-exact-copy-cloning-and-hash-based-alignment-reuse.md)) plus the
title-page inputs (names, labels, work title/author); the alignment digest
adds both sides' digests and the ordered pairing (each meaning match's order
+ both sides' sentence ids in document order), so any row edit, relink, or
sentence edit re-renders — while `entity_matches.updated_at` churn
(approve/reject, similarity) deliberately does not. A missing file with a
matching hash still re-renders. Layout changes that must invalidate every
cache at once bump the digest's version constant.

Rejected: **hash-suffixed filenames with no DB row** — a row is what makes
"the one current render" queryable and the stale file findable; suffixes
accumulate orphans.

### 4. A4 books; the original side sits left; completed alignments only; plain content

Both books are A4 portrait with DejaVu Serif body / DejaVu Sans headings,
title pages, and canvas-drawn page numbers. The bilingual book is a
borderless 50/50 table whose repeating header carries the two language
names; **the left column is the work's original language** when
determinable (`EntityMatch::originalSide()`, [ADR
0018](0018-works-and-unified-language-keyed-tables.md)), falling back to
canonical side a. The alignment PDF **exists only for `status = completed`**
([ADR 0055](0055-stale-status-no-auto-realign.md) vocabulary): an unfinished
alignment is not a book yet — the endpoint 404s and the button hides. Books
render the plain `content` column only — never `stressed_content`
([ADR 0052](0052-local-sentence-enrichment.md)); stress marks are a screen
feature.

## Consequences

- **First click per content revision renders synchronously** (~1–3 s);
  every later click streams the stored file — accepted in exchange for zero
  job machinery.
- **Renders never accumulate**: fixed paths plus the digest check mean the
  disk holds at most one current PDF per subject; deleting the subject
  nulls its cache row (`nullOnDelete`), and a subsequent orphan file is
  unreachable (its row is gone).
- **The digest is a correctness seam**: whatever a book renders must be an
  input — forgetting one (e.g. a title-page field) would serve a stale
  book; adding an irrelevant one (e.g. `updated_at`) re-renders needlessly.
  Both directions are pinned by tests.
- **Downloads are attachment-disposition only** — the browser downloads,
  never navigates; guards mirror the source pages exactly (404 wrong-work,
  403 unreadable, plus the completed rule on matches).
- **`wiki:validate` baseline entries parked by the implementation tickets
  are pruned** with the wiki upkeep pass that ships this ADR.
