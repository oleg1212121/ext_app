---
type: Feature
title: Bilinguals Simulator
description: Side-by-side EN/RU reading trainer where users translate and get AI assessment of their translation.
tags: [bilinguals, simulator, ai, inertia]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
sources:
  - id: controller
    resource: laravel/app/Http/Controllers/Bilinguals/SimulatorController.php
    title: SimulatorController
  - id: routes
    resource: laravel/routes/web.php
    title: Routes
---

# What it does

The simulator presents an English text and its Russian counterpart (produced by
the [alignment pipeline](/domains/sentence-alignment.md)). The learner writes
their own translation, then asks an AI model to assess it — meaning accuracy,
grammar, corrections, and improved variants.

# Entry points

| Route | Method | Handler | Purpose |
|-------|--------|---------|---------|
| `/bilinguals/en/ru/simulator` | GET | `SimulatorController::simulator` | Inertia page `Bilinguals/Bilinguals` |
| `/text` | POST | `SimulatorController::text` | Paginated aligned text content (JSON) |
| `/ai/question` | POST | `SimulatorController::askAi` | Ask an AI model about the text (JSON), named `ai.question` |

# Key behavior

* The page loads with a **default model and a detailed default assessment
  prompt** (see `simulator()` — currently
  `openrouter:google/gemini-3.1-flash-lite-preview` and a 4-task prompt:
  meaning accuracy %, grammar %, highlighted fixes, improved versions).
* The text dropdown lists `EnRuEntityMatch` records as
  `"<EN entity name> / <RU entity name>"`.
* `text()` paginates (default 50/page, max 200) and can serve either an entity
  match (`en_ru_entity_match_id`) or legacy text files; meaning matches are
  shaped for the UI by `MeaningMatchPresenter`.
* AI calls go through `AIModelResolver::ask()` with a `provider:model` string —
  see [AI Providers](/domains/ai-providers.md). Validation via
  `App\Http\Requests\AiQuestionRequest` / `BilingualsTextRequest`.
* Answers are rendered from markdown with the shared
  `AiProvider::markdownToHtml()`.

# Frontend

React page `resources/js/Pages/Bilinguals/` (`Bilinguals.jsx` plus `AI/`,
`TextContent/`, `Workplace/` sub-components). Props include `aiModels`
(grouped by provider), `textList`, and `show*` feature flags
(`showWorkplace`, `showQuestion`, `showText`, `showAI`).

# Legacy sibling

`BilingualsController` (non-namespaced) still serves the older text-file flow:
`GET /get-texts`, `POST /get-texts`, `POST /dictionary/selection/save`,
`POST /dictionary/interactions/save` (dictionary lookup interaction tracking).
Files come from `public/texts/simulator/`.
