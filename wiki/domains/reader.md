---
type: Feature
title: Reader
description: React reading interface for imported text entities in any enabled language, with bilingual Reading rows (canonical a/b row objects, ADR 0060), inline illustration sentences (ADR 0050), a server-default reading side with a client-side swap, server-side pagination, and a per-device reading position.
tags: [reader, inertia, react, illustrations]
status: stable
stale_after: 2026-12-28
generated: { by: agent:zcode, at: 2026-10-05T23:10:48+03:00 }
sources:
  - id: controller
    resource: laravel/app/Http/Controllers/ReaderController.php
    title: ReaderController
  - id: presenter
    resource: laravel/app/Classes/ReadingRowsPresenter.php
    title: ReadingRowsPresenter (Reading row shaping)
  - id: page-request
    resource: laravel/app/Http/Requests/ReaderPageRequest.php
    title: ReaderPageRequest (tolerant ?page normalization)
  - id: position-store
    resource: laravel/resources/js/lib/readingPosition.js
    title: readingPosition.js (localStorage Reading position store)
  - id: side-flip-store
    resource: laravel/resources/js/lib/sideFlip.js
    title: sideFlip.js (shared localStorage Side swap store)
  - id: side-flip-hook
    resource: laravel/resources/js/hooks/useSideFlip.js
    title: useSideFlip (shared Side swap hook)
  - id: routes
    resource: laravel/routes/web.php
    title: Routes
---

# What it does

A reading UI over imported text entities: read a text with its aligned
counterpart when one exists. Backed by the same
[entities](/database/entities-alignment.md) the alignment pipeline fills.
The reading route is language-segment-free — `GET /reader/{entityId}`; the
entity id alone names the text and its match carries both languages
(ADR 0037, same reasoning as ADR 0036's simulator route). Entries: the
**Practice** menu's Reader item opens the revived text library
(`GET /reader/{lang?}`, ADR 0038) whose list deep-links into the reading
page, and the alignment card's "Read · {LANG}" button and the entity page's
Read button link straight to it. The old `/reader/{lang}/{entityId}` shape
is deleted (404, test-guarded); `/reader` and `/reader/{lang}` are live
again as the index.

# Routes

| Route | Handler | Purpose |
|-------|---------|---------|
| `/reader/{entityId}` | `ReaderController::show` | React reader for one entity, named `reader.show`. The URL entity only anchors its match — the **Reading side** rule (ADR 0037) picks which language is read. Legacy `/reader-react*`, `/reader/{lang}/{entityId}` all 404 |
| `/reader/{lang?}` | `ReaderController::index` | The text library (Practice → Reader), named `reader.index`. Language tabs over the readable entities of that language (top 100, `EntityAccessService::readableQuery`); bare `/reader` derives the user's native enabled language, fallback en. Clicking a text visits `/reader/{id}` |

# Side rule and language toggle

`EntityMatch::readingSideFor(nativeLanguageId)` decides the default reading
side: the side in the user's **Native language** becomes the translation,
else the work's original side, else the A-side — the exact rule
`LibraryController::readerTarget()` uses for the card's Read button, so link
and page agree. The rows themselves stay canonical a/b (ADR 0060): the
server ships them as `defaultSide` plus side-keyed `langs` / `wordMaps` /
`highlightable` / `explainable`, and never reorders.

`ReaderApp` renders a two-option language radio (labelled with the actual
language codes) whenever a translation side exists. Flipping is pure client
display state — no row copying, no reload — and persists as a **Side swap**
(Working state) under `ext_app.reader.side-flip.v1` (`lib/sideFlip.js`),
keyed by `positionKey`. Both surfaces flip through the shared `useSideFlip`
hook (`hooks/useSideFlip.js`), which owns the flip state and derives which
canonical side each column shows on top of `lib/readingRows.mjs` (the only
side-mapping module) — one flip per text, so a flip on the simulator opens
the reader flipped for the same match (ADR 0037 amendment).

# Frontend

Inertia pages under `resources/js/Pages/Reader/` — `ReaderApp` +
`ReaderRow` (reading view), plus the restored index `Pages/ReaderIndex.jsx`
→ `Reader/ReaderIndexApp.jsx` (language tabs + text list, ADR 0038). The
back arrow is browser-history back; the index is a separate page, not an
in-app listing inside the reader.

# Reading rows

`ReaderController::buildRows()` finds the entity's `EntityMatch` (either
side). With no match — or when the caller may not read **both** sides'
entities — it falls back to single-language rows rather than leaking the
restricted counterpart (mirrors the simulator both-sides rule from ADR 0014).
With a readable match the meaning matches are shaped into **Reading rows**
by `ReadingRowsPresenter::toReadingRows()` (ADR 0060): one row object per
meaning match — `{key: "mm:{id}", a: {sentences}, b: {sentences}}` in
canonical side order — each sentence self-describing: `{id, text,
stressed?, phrasal?}` for text, `{id, image: {url, width, height},
text: caption}` for illustrations, annotation keys present only when the
data exists. Single-language rows (via `forEntitySentences()`) are one
sentence per row with `b: null`. Row `key`s (`mm:`/`es:` prefixes) scope
familiarity lookup events to a sentence pair (ADR 0028); the sentence `id`
is what the word-explanation request addresses.

**Illustrations are image sentences inside the ordered list** (ADR 0050):
they ride their side's `sentences` in document order and render interleaved
with the text (shared `Components/IllustrationFigure.jsx`). Caption tokens
are not part of the page word maps.

Reads are gated by `EntityAccessService` (see the [Entity Access](
../../CONTEXT.md#entity-access-context) context): `show` 403s on a
Restricted entity without an Access grant.

# Pagination

Rows paginate server-side at a fixed **50 per page** under `?page=N`
(ADR 0032): both row sources — meaning matches and single-language entity
sentences — go through `ReaderController::paginateRows()`, which clamps the
requested page into `[1, lastPage]` so stale bookmarks and junk values land
on a valid page (`ReaderPageRequest` normalizes `?page` tolerantly rather
than failing validation — it's a shareable URL, not a form field). The
payload carries a flat `meta` prop (`current_page`, `per_page`, `total`,
`last_page`) and the page's rows only. **Word maps are page-scoped too**:
`ReadingRowsPresenter::wordMapsFor()` — the shared reading-surface payload
seam since the ADR 0060 amendment (the simulator's `/text` uses the same
builder and the same filtering) — keeps only entries whose token occurs in
the page's row texts (tokenized with the same `WordTokenizer` that built
the `l_word` keys), so the payload no longer scales with the text's length;
`meta` comes from the presenter's `metaFor()` too.

# Reading position

The last page reached per text — the **Reading position** (a Working-state
kind) — lives per device in localStorage (`ext_app.reader.position.v1`,
`lib/readingPosition.js`), keyed by the server-provided `positionKey`:
`mm:{entityMatchId}` for matched texts (both reading sides share one key —
same rows) or `ent:{entityId}` for single-language ones (`es:` is
deliberately excluded — that prefix names a single entity sentence in
row-key vocabulary). `ReaderApp` writes it on every page turn and, on open
when the URL has no `?page`, history-replaces to the saved page clamped to
the current `meta.last_page` (repairing the stored value if the text
shrank). Page turns are Inertia partial reloads (`only` the paged props,
`preserveState`) so the component — and its audio player — stay mounted;
the word-map state mirrors are resynced from props on page change.

⚠️ The restore's original call, `router.replace(url, {only, …})`, was **wrong
for Inertia v3**: `replace()` is a client-side page-object patch
(`clientVisit`), takes no options object, and never fetches — so the saved
page was never actually loaded; the call garbage-patched the page object,
reset scroll, and rewrote the URL, reading as a "reload back to the start"
(2026-09-23). The restore now uses `router.visit(url, {only, preserveState,
preserveScroll, replace: true, onFinish})` — a real partial reload with
history-replace semantics. When a restore is pending at mount, `ReaderApp`
holds the rows and pager back behind a small spinner (`restoring` state,
cleared by the visit's `onFinish` — success or failure): rendering page 1
for a beat and then swapping rows under a user who had already scrolled
looked like a reload and yanked scroll to the top. With the placeholder,
the container stays short so the post-visit scroll is a no-op and the saved
page appears directly, at its top.

# Interactive words

`show()` also ships the [interactive word](/domains/interactive-words.md)
payload (scoped to the current page's rows): `wordMaps` keyed by canonical
side (`{a, b}`; the b map empty when rows are single-language), plus
`highlight` (the saved `reader.highlight` setting) and the `highlightable`
language flags (side language ≠ the user's native language). The same rule
now gates the AI Context explanation tab: `explainable` flags and `explain`
(`{enabled, modelKey, modelLabel, followsAnswer, answerLabel}` — assembled
server-side by `AIModelResolver::explainConfig()`, the same builder the
simulator ships from; the reader's `answerLabel` is null — ADR 0035).
`ReaderRow` takes two display-column descriptors
(`{side, language, wordMap, highlightable, explainable}` — the reading
surfaces' shared shape, ADR 0068; the reader's `language` carries the code
only) and renders both row halves
through the shared `WordText`/`WordPopup` components (each gets the row's
`key`, so clicking a word fires a ledger-deduplicated **lookup** event —
the reader never credits reads); the primary line is a `role="button"` div
(not a `<button>`) so word buttons inside it stay valid HTML — activating
the line itself still toggles the translation, word clicks stop
propagation. Both reading lines style themselves with
`var(--font-reading)` — `'Source Serif 4', Georgia, …` (see
`conventions/design-system.md`): a single font must cover Latin + Cyrillic
so glyphs never switch mid-word. The stress toggle draws acutes as empty
positioned marks (`.stress-mark`) over intact plain text — no U+0301 is
rendered — but source content itself can still carry combining marks: if
such a mark and its base letter resolve from different fonts, attachment
breaks and the acute renders as a spacing glyph (gaps after stressed
vowels; the old Fraunces-first `--font-serif` had exactly that defect).

# Rendering cost

Both `ReaderRow` and `WordText` are `React.memo`ized with stable prop
identities (memoized explain payloads, `useCallback` handlers, CSS-only hover
via `.group:hover`), so scrolling and unrelated state changes (audio status,
page picker, sibling row expansion) never re-render token-heavy rows. Rows
carry no per-row `--fs` style effects: the custom property had no CSS
consumer, and its writes were the attribute-mutation storm observed in the
2026-09-23 freeze forensics.

There is deliberately **no `content-visibility` on rows** and no row
virtualization. Pages are capped at 50 paginated rows of page-scoped data
(ADR 0032), so a page renders at most ~1–2k token spans even with a fully
populated word index — rendering everything is cheap. A `content-visibility:
auto` + `contain-intrinsic-size: auto 8rem` mitigation was tried and removed
the same day: rendered rows (~66px) are roughly half the placeholder height,
so Chromium kept flipping rows between placeholder and rendered state at the
relevance boundary while scrolling — scroll height churned, relayout
repeated, and CPU stayed pegged even after scrolling stopped. If pages ever
grow past pagination, row virtualization is the fix — not
`content-visibility` with a mismatched intrinsic size.

Additional engine-side guards on `#contentContainer` (2026-09-23, second
freeze pass): `[overflow-anchor:none]` (Chromium scroll anchoring recomputes
anchor nodes on layout changes and is a known scroll-freeze ingredient) and
`[scrollbar-gutter:stable]` (no appear/disappear width oscillation). The
width-toggle wrapper uses `transition-[max-width]` instead of
`transition-all`, and rows are keyed by `rowKey` (`mm:`/`es:` ids), not
list index, so partial reloads keep row identity stable.

Fourth pass (2026-09-23, after the probe proved the main thread wedges
~1.5–2s after load — inside the webfont-swap window, not scroll-caused):
rows carry **no transitions at all** (primary line, translation reveal, and
the hover rule span lost `transition-*`; there is no `.reader-row:hover`
recolor either — recoloring inline text while rows sweep under the cursor
forces per-row glyph re-rasterization). When a side's word map is empty,
`WordText` renders the raw display text instead of its tokenizer/segment
machinery (the plain-text fast path lives inside `WordText` since ADR
0060's reshape). The page picker is `type="text"
inputMode="numeric"` (Chrome spins focused `type="number"` on wheel). Fonts
are self-hosted from `public/fonts/` via `resources/css/fonts.css` (same
five families the former Google css2 link provided) — no network font fetch,
no swap-timed reflow. `public/css/simulator.css` is loaded only by the
simulator page (`<Head>` link in `Bilinguals.jsx`), not globally.

# Freeze resolution (2026-09-23)

The freeze was diagnosed with a temporary frontend performance probe (since
removed together with its `/perf-probe` route and test): it proved the main
thread wedged ~1.5–2s after load — inside the external webfont swap window —
long before scrolling, while static analysis had (correctly) shown zero
scroll-reactive code. After pass 4 (fonts self-hosted, rows de-animated,
plain-text fast path), a 3.5-minute instrumented session on `/reader/15`
showed a flat heap (~25 MB), zero React commits across 726 scroll events,
≤6 ms event-loop lag, and 2 long tasks total. `wiki/log.md` keeps the full
pass-by-pass timeline.

## Visual system per page

| Surface | Tokens | Notes |
|---------|--------|-------|
| `/reader/{entityId}` (reader) | `--color-vellum/*` (legacy) | Still on the warm vellum palette. Migrating it to `--wbench-*` is tracked as a follow-up so a library switch does not visibly cross palettes when entering a text. (The reader index — the `--wbench-*` reference implementation, deleted with the Practice disposal and restored by ADR 0038 — still uses the `--wbench-*` tokens; the design-system page's canonical example is the simulator.) |
