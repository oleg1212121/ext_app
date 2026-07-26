---
type: Feature
title: Reader
description: React reading interface for imported EN/RU entities with dictionary support.
tags: [reader, inertia, react]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
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
