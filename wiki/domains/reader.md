---
type: Feature
title: Reader
description: React reading interface for imported EN/RU entities with dictionary support.
tags: [reader, inertia, react]
status: stable
generated: { by: agent/glm-5.2, at: 2026-08-06T16:30:00Z }
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

## Visual system per page

| Surface | Tokens | Notes |
|---------|--------|-------|
| `/reader-react/{lang}` (index) | `--wbench-*` | Matches the [design system](/conventions/design-system.md) — cold paper + ultramarine accent, Source Serif 4 / IBM Plex Sans / JetBrains Mono. Hairline toolbar with mono `READER · EN ↔ RU` stamp and underline tabs; dense list with mono row numbers `01` and `.ribbon-mark` hover edge. Implements the four-state contract on the entity list: empty (`NO TEXTS IN THIS LIBRARY` eyebrow + serif invite), loading (the page's signature — a `.ai-loader-rule` fills under the toolbar while Inertia navigates between libraries, with a mono `LOADING · {Lang}` label), answer (the list), no error state (the static controller has no request to fail). |
| `/reader-react/{lang}/{entityId}` (reader) | `--color-vellum/*` (legacy) | Still on the warm vellum palette. Migrating it to `--wbench-*` is tracked as a follow-up so an EN/RU library switch does not visibly cross palettes when entering a text. |
