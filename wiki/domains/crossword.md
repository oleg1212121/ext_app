---
type: Feature
title: Crossword
description: Crossword puzzle feature in two generations — legacy Livewire and the current React app.
tags: [crossword, livewire, react, legacy]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
sources:
  - id: controller
    resource: laravel/app/Http/Controllers/Test.php
    title: Test controller (crossword + word endpoints)
  - id: livewire
    resource: laravel/app/Livewire/Crossword.php
    title: Legacy Livewire component
  - id: class
    resource: laravel/app/Classes/Crossword.php
    title: Crossword generator logic
---

# Two generations

* **Current: React.** Routes `/crossword-react/{lang}` (`lang` ∈ `en|ru`,
  named `crossword.react`, `/crossword-react` redirects to `/en`) render the
  Inertia pages in `resources/js/Pages/Crossword/` (full React app with its
  own `api.js` and hooks). Data comes from `POST /get-crossword`
  (`Test::getCrossword`).
* **Legacy: Livewire + Blade.** Route `/crossword` (named `crossword`,
  `Test::crossword`) renders `App\Livewire\Crossword`
  (`resources/views/livewire/`). Do not extend it; new work goes to the React
  version (see [Frontend](/architecture/frontend.md)).

# Related word endpoints (same controller)

| Route | Handler | Purpose |
|-------|---------|---------|
| `POST /word/upvote` | `Test::upvote` | Mark word known/useful |
| `POST /word/acknowledge` | `Test::acknowledge` | Acknowledge a word |
| `POST /word/dismiss` | `Test::dismiss` | Dismiss a word |
| `POST /word/ask-ai` | `Test::askAI` | Ask AI about a word (uses [AI Providers](/domains/ai-providers.md)) |
| `GET /get-texts` | `Test::getTexts` | Text listing used by legacy pages |

Puzzle generation logic lives in `App\Classes\Crossword`; words come from the
dictionary tables.
