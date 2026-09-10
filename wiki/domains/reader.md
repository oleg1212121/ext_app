---
type: Feature
title: Reader
description: React reading interface for imported text entities in any enabled language, with bilingual rows from alignments.
tags: [reader, inertia, react]
status: stable
stale_after: 2026-12-10
generated: { by: agent:zcode, at: 2026-09-10T00:00:00Z }
sources:
  - id: controller
    resource: laravel/app/Http/Controllers/ReaderController.php
    title: ReaderController
  - id: presenter
    resource: laravel/app/Classes/MeaningMatchPresenter.php
    title: MeaningMatchPresenter (bilingual row shaping)
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
reading language always comes first.

Reads are gated by `EntityAccessService` (see the [Entity Access](
../../CONTEXT.md#entity-access-context) context). The index lists only
entities the caller may read (Public, or Restricted with an Access grant);
`show` 403s on a Restricted entity without a grant.

## Visual system per page

| Surface | Tokens | Notes |
|---------|--------|-------|
| `/reader-react/{lang}` (index) | `--wbench-*` | Matches the [design system](/conventions/design-system.md) — cold paper + ultramarine accent, Source Serif 4 / IBM Plex Sans / JetBrains Mono. Hairline toolbar with mono `Reader · En ↔ Ru` stamp (glyph map per language code, code fallback) and underline tabs over the enabled languages; dense list with `.ribbon-mark` hover edge. Implements the four-state contract on the entity list: empty (`No texts in this library` eyebrow + serif invite), loading (the page's signature — an `.ai-loader-rule` fills under the toolbar while Inertia navigates between libraries, with a mono `Loading · {Language}` label), answer (the list), no error state (the static controller has no request to fail). |
| `/reader-react/{lang}/{entityId}` (reader) | `--color-vellum/*` (legacy) | Still on the warm vellum palette. Migrating it to `--wbench-*` is tracked as a follow-up so a library switch does not visibly cross palettes when entering a text. |
