# ADR 0095: Book layout — the `paragraph` sentence type carries paragraph boundaries

Date: 2026-11-02
Status: Accepted (amends [ADR 0093](0093-book-pdfs.md) — its engine, endpoint,
cache, and guard decisions are unchanged)

## Context

[ADR 0093](0093-book-pdfs.md) shipped the book PDFs' machinery — dompdf,
digest-cached downloads, A4 title pages, page numbers — but the body laid
the text out as a web-page mirror: one block per sentence row. A book of a
300-sentence entity was 300 separately spaced blocks; the bilingual book
repeated its language header on every page and broke inside table rows
whenever a chapter wanted to start on a fresh page. What was missing is the
one thing a book needs that the sentence schema never stored: **where the
paragraphs are**. The source `.txt` files know (blank lines), but once
uploaded the text lives as flat sentence rows and the blank lines are gone.

This ADR records how paragraph boundaries are now carried, and the layout
rules both books render with ([ADR 0093](0093-book-pdfs.md)'s numbering
continues from 5).

## Decisions

### 5. Paragraph boundaries ride in the sentence type: `paragraph` opens a paragraph

A new seeded sentence type **`paragraph`** — a body sentence that *opens*
a paragraph — is the single carrier of paragraph boundaries. Plain
`sentence` rows *continue* the current paragraph; the book composes
consecutive body sentences into one flowing justified block and breaks the
block at each `paragraph` row. The split pipeline types a sentence
`paragraph` exactly where a paragraph would start — the first sentence of
the document, the first after a blank line in the source text, the first
after a title line — and only when the predicted type is plain `sentence`
(a paragraph-opening line predicted `quote` or `title` keeps that type;
those break flow visually anyway). Titles, subtitles, quotes, footnotes,
captions, and illustrations are non-body elements: each ends the open
paragraph, so a paragraph also starts implicitly at document start and
after any of them, even when the following sentence is typed plain
`sentence`.

Because the `/split` requests are stateless streaming chunks, python cannot
know whether a buffer begins a document or continues one — so the
paragraph-start position travels **in the text**: the PHP splitter prefixes
the document-start buffer with `\n\n`, and python prefixes a held-back
remainder that carries a paragraph-typed sentence with the same marker so
the next request (and the EOF finalize call) re-derives the type.
`normalize` strips the leading blank line, so the marker is read off the
raw request text; it is stitching prefix, never sentence content, and it
rides in `entities.split_remainder` transparently.

The type is stored but invisible on web surfaces (like `subtitle`): both
sentence editors list types from the database, so the seeded row appears
everywhere with no editor changes, and the type is ordinary alignable text
— no alignment, simulator, or word-index behavior change.

Rejected: **a boolean paragraph-start column on `entity_sentences`** —
a duplicate of what a sentence type already expresses, and the editors and
the pipeline already speak types (type names map dynamically, clones copy
types verbatim); a second encoding of the same fact invites the two to
drift. **Meaning-match rows as the only paragraph source** — paragraph
flow derived per alignment row works for the bilingual book but a
monolingual entity book has no rows at all, so the entity book would stay
a sentence-list mirror or need its own invented rule. **Re-split backfill
of existing entities** — re-running the splitter would delete and re-create
sentence rows, destroying alignment junctions (and the human curation they
carry); the per-sentence fallback below is the answer to old entities
instead. **PDF-time re-derivation from the stored source file** —
splitting the `.txt` again at render time would ignore every post-upload
edit (the entities frontend and the alignment editor mutate sentence rows
directly, and the language preprocessing alters content, breaking token
matching against the stored rows).

### 6. The entity book renders typed blocks; `title` opens a chapter page

`BookPdfService::composeBookBlocks()` composes the typed sentence rows into
a flat **block** list before the blade renders anything — flowing
`paragraph` blocks, passthrough title/subtitle/quote/footnote/caption
blocks, illustration blocks — so the layout is testable without a dompdf
render. Body blocks concatenate with single spaces (trimmed), justified
with a small first-line indent and a small inter-paragraph gap. A `title`
block opens a **chapter page** (page-break-before, centered, larger,
sans-serif); `subtitle` is an inline bold heading without a break.
Illustrations end the open paragraph and render as their own centered block
(≤ ~70% of text width) with the caption in small italics beneath; flow
resumes as a new paragraph. Quotes, footnotes, and captions keep their book
styling. **Adjacent titles stack into one heading** (newline-separated
lines; each renders as its own block line): two consecutive forced page
breaks would strand the first title alone on a page of its own — a real
book opens a part and a chapter on the same page («ЧАСТЬ ПЯТАЯ» /
«СВИСТУН»), not two. The bilingual book already merged consecutive title
*rows* into the shared heading (decision 9); this extends the same rule to
adjacent titles within one block stream.

### 7. Entities with zero `paragraph` rows compose with the same flow rules

Amended 2026-11-02, reversing the originally accepted rule: the first
shipped version fell back to **per-sentence paragraphs** for entities with
no `paragraph`-typed rows ("so old books never render as one giant
paragraph"), with `entityHasParagraphTyping()` as the per-entity switch.
The first real old book rendered from that fallback — 1,463 sentences, each
on its own line — and the author rejected it: the books must read like
books, sentences concatenated, new lines at paragraph boundaries. So the
fallback is gone: every entity composes with decision 5's flow rules, and
a zero-`paragraph` text breaks paragraphs only at the implicit starts
(document start, after non-body elements) — a book with chapter titles
gets one flowing paragraph per section, which is exactly the requested
shape. The honest cost, accepted: a text with no `paragraph` rows and no
non-body elements at all renders as a single paragraph — the stored data
carries no boundary information, and the alternatives (per-sentence
lines — rejected above; re-split backfill — rejected in decision 5 for
destroying alignment junctions) are both worse. `entityHasParagraphTyping()`
and the alignment `uses_paragraphs` flags are deleted.

### 8. The bilingual book keeps the 50/50 spread but drops the repeating header

The alignment book stays a borderless fixed 50/50 table (original side
left, [ADR 0018](0018-works-and-unified-language-keyed-tables.md)'s
`originalSide()` with the side-a fallback) of one row per meaning match,
but **the language-label header is removed**: the repeating
`table-header-group` header that named the two languages atop every page is
gone — language names live on the title page only, like any book. Each cell
composes its side's sentences with decision 5's rules (per table cell, so
paragraph flow stays within a cell); paired paragraphs stay visually level.
Illustrations render as separated centered blocks inside their column,
preserving row pairing.

### 9. Chapter pages share both languages; dompdf's page-break limits shape the blade

A row carrying a `title` on either side opens a **chapter page shared by
both languages** — both sides' titles stack into one centered heading, and
consecutive title rows merge into one heading. Two dompdf limits pin the
implementation (both locked by exactly-N-pages render tests):

- **dompdf cannot page-break inside table rows**, so the chapter machinery
  lives in the blade as a small state machine: title-bearing rows
  accumulate their titles into a pending list, and the heading flushes
  right before the next content-bearing row by **closing the `</table>`,
  emitting the `h2.chapter-heading`, and reopening the table** — the
  columns resume paired under the heading. A title row that also carries
  body content renders the heading first, then its body as the first row of
  the reopened table (pairing preserved even when only one side holds the
  title).
- **dompdf does not collapse a `break-after` with a following
  `break-before`**: the title page's forced break plus the first chapter
  heading's `page-break-before` produced a blank page between them, so the
  first heading while no row has rendered yet carries `chapter-open`, which
  suppresses its forced break.

### 10. Each book kind carries its own digest-version constant

The layout is an input to the cache: `DIGEST_VERSION` (`entity-book/vN`)
sits in the entity digest, `MATCH_DIGEST_VERSION` (`alignment-book/vN`) in
the match digest, and each bumps when its book's layout changes in a way
that must invalidate every cached render at once — which is how this
rework shipped without a migration: both constants bumped to `/v2` at
first, then again to `/v3` when decision 7's fallback was removed (every
cached old book must re-render under the new flow rules), and each first
Get PDF after deploy re-rendered every cache row in place. The match
digest folds both sides' entity digests, so an entity-book bump re-renders
alignment caches once too ([ADR 0093](0093-book-pdfs.md) decision 3's
regime, unchanged). A stale cached row re-renders transparently on the next
request; nothing deletes or backfills.

## Consequences

- **Paragraph boundaries are data, not presentation**: every future
  consumer (web reader layout, exports beyond PDF) reads the same
  `paragraph` type instead of re-deriving paragraphs per surface.
- **Old entities render book-shaped immediately** (decision 7): sentences
  concatenate and paragraphs break at implicit starts, so the paragraph
  flow of a pre-`paragraph` text stays coarse (section-granular, from its
  titles) until the text is re-imported — accepted, because the
  alternative was destroying alignment junctions across the whole corpus.
- **New uploads type paragraph starts for free**: the splitter emits the
  type during the ordinary split; no user-facing upload change, and the
  exact-copy clone copies types verbatim so clones inherit correct
  paragraphs.
- **The bilingual book reads like a book**: no header noise on body pages;
  the cost is that a reader who opens the book mid-page no longer sees the
  language names until the title page — accepted.
- **dompdf's two page-break limits are now repo knowledge** (decision 9),
  pinned by render tests; any future layout work around chapters or forced
  breaks starts from them instead of rediscovering them.
- **Layout changes are one-constant deploys** (decision 10); forgetting a
  bump would serve stale layouts from cache — each bump is pinned by a
  stored-old-digest re-render test.
