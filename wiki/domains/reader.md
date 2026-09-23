---
type: Feature
title: Reader
description: React reading interface for imported text entities in any enabled language, with bilingual rows from alignments, a native-language default reading side with a client-side swap, server-side pagination, and a per-device reading position.
tags: [reader, inertia, react]
status: stable
stale_after: 2026-12-23
generated: { by: agent:zcode, at: 2026-09-23T12:00:00Z }
sources:
  - id: controller
    resource: laravel/app/Http/Controllers/ReaderController.php
    title: ReaderController
  - id: presenter
    resource: laravel/app/Classes/MeaningMatchPresenter.php
    title: MeaningMatchPresenter (bilingual row shaping)
  - id: page-request
    resource: laravel/app/Http/Requests/ReaderPageRequest.php
    title: ReaderPageRequest (tolerant ?page normalization)
  - id: position-store
    resource: laravel/resources/js/lib/readingPosition.js
    title: readingPosition.js (localStorage Reading position store)
  - id: side-flip-store
    resource: laravel/resources/js/lib/sideFlip.js
    title: sideFlip.js (localStorage Side swap store)
  - id: routes
    resource: laravel/routes/web.php
    title: Routes
---

# What it does

A reading UI over imported text entities: read a text with its aligned
counterpart when one exists. Backed by the same
[entities](/database/entities-alignment.md) the alignment pipeline fills.
The route is language-segment-free — `GET /reader/{entityId}`; the entity id
alone names the text and its match carries both languages (ADR 0037, same
reasoning as ADR 0036's simulator route). The reader is reached through deep
links — each alignment card's "Read · {LANG}" button and the entity page's
Read button. The old `/reader/{lang}/{entityId}` shape is deleted (404,
test-guarded).

# Routes

| Route | Handler | Purpose |
|-------|---------|---------|
| `/reader/{entityId}` | `ReaderController::show` | React reader for one entity, named `reader.show`. The URL entity only anchors its match — the **Reading side** rule (ADR 0037) picks which language is read. Legacy `/reader-react*`, `/reader`, `/reader/{lang}`, `/reader/{lang}/{entityId}` all 404 |

# Side rule and language toggle

`EntityMatch::readingSideFor(nativeLanguageId)` decides the reading side:
the side in the user's **Native language** becomes the translation, else the
work's original side, else the A-side — the exact rule
`LibraryController::readerTarget()` uses for the card's Read button, so link
and page agree. `buildRows()` normalizes rows for that side (reading text in
column 0) and the payload ships `primaryLang` / `translationLang` (null for
single-language texts), `primarySide`, and per-column word maps and
highlight/explain flags.

`ReaderApp` renders a two-option language radio (labelled with the actual
language codes) whenever a translation side exists. Flipping is pure client
display state — rows, word maps, flags and `primarySide` swap in render, no
reload — and persists as a **Side swap** (Working state) under
`ext_app.reader.side-flip.v1` (`lib/sideFlip.js`), keyed by `positionKey`.

# Frontend

Inertia pages under `resources/js/Pages/Reader/` — `ReaderApp` +
`ReaderRow` (reading view). The back arrow is browser-history back; there is
no in-app listing to return to.

# Bilingual rows

`ReaderController::buildRows()` finds the entity's `EntityMatch` (either
side). With no match — or when the caller may not read **both** sides'
entities — it falls back to single-language rows rather than leaking the
restricted counterpart (mirrors the simulator both-sides rule from ADR 0014).
With a readable match the meaning matches are shaped into bilingual rows by
`MeaningMatchPresenter::toSimulatorRows()` and normalized for the reading
side: rows are `[a, b]` pairs, flipped so the reading language always comes
first. The payload also carries `rowKeys` — `mm:{meaningMatchId}` per
bilingual row (never flipped by the side normalization) or
`es:{entitySentenceId}` per single-language row — used to scope familiarity
lookup events to a sentence pair (ADR 0028).

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
`wordMapForRows()` keeps only entries whose token occurs in the page's row
texts (tokenized with the same `WordTokenizer` that built the `l_word`
keys), so the payload no longer scales with the text's length.

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

# Interactive words

`show()` also ships the [interactive word](/domains/interactive-words.md)
payload (scoped to the current page's rows): `wordMap` for the reading
entity and `translationWordMap` for the
aligned counterpart entity (empty when rows are single-language), plus
`highlight` (the saved `reader.highlight` setting) and the
`primaryHighlightable` / `translationHighlightable` language flags (side
language ≠ the user's native language). The same rule now gates the AI
Context explanation tab: `primaryExplainable` / `translationExplainable`
flags, `primarySide` ('a'|'b', which match side the primary column reads —
`null` for single-language texts) and `explain` (`{enabled, modelKey}` from
`AIModelResolver::resolveExplanationModel()`, ADR 0035). `ReaderRow` renders
both row halves through the shared `WordText`/`WordPopup` components (each
gets the row's `rowKey`, so clicking a word fires a ledger-deduplicated
**lookup** event — the reader never credits reads); the primary line is a
`role="button"` div (not a `<button>`) so word buttons inside it stay valid
HTML — activating the line itself still toggles the translation, word clicks
stop propagation.

# Rendering cost

A page mounts tens of thousands of token spans, so rows render with
`content-visibility: auto` (`.reader-row` in `app.css`) — off-screen rows
skip layout and paint until scrolled near — and both `ReaderRow` and
`WordText` are `React.memo`ized with stable prop identities (memoized
explain payloads, `useCallback` handlers, CSS-only hover via `.group:hover`).
There is deliberately no row virtualization yet; find-in-page and row
reveal still work because `content-visibility` keeps rows in the DOM.

## Visual system per page

| Surface | Tokens | Notes |
|---------|--------|-------|
| `/reader/{entityId}` (reader) | `--color-vellum/*` (legacy) | Still on the warm vellum palette. Migrating it to `--wbench-*` is tracked as a follow-up so a library switch does not visibly cross palettes when entering a text. (The deleted reader index was the `--wbench-*` reference implementation; the design-system page's canonical example is now the simulator.) |
