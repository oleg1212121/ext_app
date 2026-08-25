---
type: Feature
title: Reader
description: React reading interface for imported EN/RU entities with dictionary support.
tags: [reader, inertia, react]
status: stable
generated: { by: human:alex, at: 2026-08-25T00:00:00Z }
sources:
  - id: controller
    resource: laravel/app/Http/Controllers/ReaderController.php
    title: ReaderController
  - id: textreader
    resource: laravel/app/Classes/TextReader.php
    title: TextReader service class
  - id: routes
    resource: laravel/routes/web.php
    title: Routes
---

# What it does

A reading UI over imported text entities: pick a language and a text, read it
with dictionary/translation support. Backed by the same
[entities](/database/entities-alignment.md) the alignment pipeline fills.

# Routes

| Route | Handler | Purpose |
|-------|---------|---------|
| `/reader` | `Test::reader` | Legacy Blade reader |
| `/reader-react/{lang}` | `ReaderController::index` | React index of texts (`lang` ∈ `en\|ru`), named `reader.react.index` |
| `/reader-react/{lang}/{entityId}` | `ReaderController::show` | React reader for one entity, named `reader.react` |
| `/reader-react` | redirect | Defaults to `/reader-react/en` |

# Frontend

Inertia pages under `resources/js/Pages/Reader/` — `ReaderIndexApp` (listing)
and `ReaderApp` + `ReaderRow` (reading view).

# Access

Reads are gated by `EntityAccessService` (see the [Entity Access](
../../CONTEXT.md#entity-access-context) context). The index lists only entities the
caller may read (Public, or Restricted with an Access grant); `show` 403s on a
Restricted entity without a grant. When an entity has an `EnRuEntityMatch`, the
reader renders bilingual rows only if the caller may also read the *other* side;
otherwise it falls back to single-language rows rather than leaking the
restricted counterpart (mirrors the simulator both-sides rule from ADR 0014).

## Visual system per page

| Surface | Tokens | Notes |
|---------|--------|-------|
| `/reader-react/{lang}` (index) | `--wbench-*` | Matches the [design system](/conventions/design-system.md) — cold paper + ultramarine accent, Source Serif 4 / IBM Plex Sans / JetBrains Mono. Hairline toolbar with mono `READER · EN ↔ RU` stamp and underline tabs; dense list with mono row numbers `01` and `.ribbon-mark` hover edge. Implements the four-state contract on the entity list: empty (`NO TEXTS IN THIS LIBRARY` eyebrow + serif invite), loading (the page's signature — a `.ai-loader-rule` fills under the toolbar while Inertia navigates between libraries, with a mono `LOADING · {Lang}` label), answer (the list), no error state (the static controller has no request to fail). |
| `/reader-react/{lang}/{entityId}` (reader) | `--color-vellum/*` (legacy) | Still on the warm vellum palette. Migrating it to `--wbench-*` is tracked as a follow-up so an EN/RU library switch does not visibly cross palettes when entering a text. |
