---
type: Feature
title: Reader
description: React reading interface for imported text entities in any enabled language, with bilingual rows from alignments, server-side pagination, and a per-device reading position.
tags: [reader, inertia, react]
status: stable
stale_after: 2026-12-19
generated: { by: agent:zcode, at: 2026-09-22T16:15:00Z }
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
  - id: routes
    resource: laravel/routes/web.php
    title: Routes
---

# What it does

A reading UI over imported text entities: pick a language and a text, read it
with its aligned counterpart when one exists. Backed by the same
[entities](/database/entities-alignment.md) the alignment pipeline fills;
`{lang}` is validated against enabled languages (any language with entities,
not a hardcoded pair).

# Routes

| Route | Handler | Purpose |
|-------|---------|---------|
| `/reader-react/{lang}` | `ReaderController::index` | React index of texts (`{lang}` validated against enabled languages), named `reader.react.index` |
| `/reader-react/{lang}/{entityId}` | `ReaderController::show` | React reader for one entity, named `reader.react` |
| `/reader-react` | redirect | Defaults to `/reader-react/en` |

# Frontend

Inertia pages under `resources/js/Pages/Reader/` — `ReaderIndexApp` (listing,
language tabs driven by the enabled `languages` prop) and `ReaderApp` +
`ReaderRow` (reading view).

# Bilingual rows

`ReaderController::buildRows()` finds the entity's `EntityMatch` (either
side). With no match — or when the caller may not read the **other** side's
entity — it falls back to single-language rows rather than leaking the
restricted counterpart (mirrors the simulator both-sides rule from ADR 0014).
With a readable match the meaning matches are shaped into bilingual rows by
`MeaningMatchPresenter::toSimulatorRows()` and normalized for the reading
side: rows are `[a, b]` pairs, flipped when reading from the b side so the
reading language always comes first. The payload also carries `rowKeys` —
`mm:{meaningMatchId}` per bilingual row (never flipped by the side
normalization) or `es:{entitySentenceId}` per single-language row — used to
scope familiarity lookup events to a sentence pair (ADR 0028).

Reads are gated by `EntityAccessService` (see the [Entity Access](
../../CONTEXT.md#entity-access-context) context). The index lists only
entities the caller may read (Public, or Restricted with an Access grant);
`show` 403s on a Restricted entity without a grant.

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

## Visual system per page

| Surface | Tokens | Notes |
|---------|--------|-------|
| `/reader-react/{lang}` (index) | `--wbench-*` | Matches the [design system](/conventions/design-system.md) — cold paper + ultramarine accent, Source Serif 4 / IBM Plex Sans / JetBrains Mono. Hairline toolbar with mono `Reader · En ↔ Ru` stamp (glyph map per language code, code fallback) and underline tabs over the enabled languages; dense list with `.ribbon-mark` hover edge. Implements the four-state contract on the entity list: empty (`No texts in this library` eyebrow + serif invite), loading (the page's signature — an `.ai-loader-rule` fills under the toolbar while Inertia navigates between libraries, with a mono `Loading · {Language}` label), answer (the list), no error state (the static controller has no request to fail). |
| `/reader-react/{lang}/{entityId}` (reader) | `--color-vellum/*` (legacy) | Still on the warm vellum palette. Migrating it to `--wbench-*` is tracked as a follow-up so a library switch does not visibly cross palettes when entering a text. |
