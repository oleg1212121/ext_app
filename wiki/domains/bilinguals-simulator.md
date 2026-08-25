---
type: Feature
title: Bilinguals Simulator
description: Side-by-side EN/RU reading trainer where users translate and get AI assessment of their translation.
tags: [bilinguals, simulator, ai, inertia]
status: stable
generated: { by: human:alex, at: 2026-08-25T12:00:00Z }
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
  prompt**. The default model is computed by `AIModelResolver::firstModelKey()`
  — the globally cheapest model **available to the signed-in user** (enabled
  provider + a User key they stored), sorted by price and grouped by provider.
  When the user has no stored keys the picker is empty and the page shows an
  "Add an API key in your Profile" empty state instead of the model dropdown.
* The text dropdown lists `EnRuEntityMatch` records as
  `"<EN entity name> / <RU entity name>"`.
* **Read access is gated per Entity, not per match.** Both the dropdown and
  `text()` filter/403 on `EntityAccessService::canReadMatch` — the caller must
  hold an Access grant (or be admin) on **both** the EN and RU entities of the
  match (ADR 0014). A user who uploaded only one side of a work therefore cannot
  read the bilingual simulator content until they also upload/match the other
  side.
* `text()` paginates (default 50/page, max 200) and can serve either an entity
  match (`en_ru_entity_match_id`) or legacy text files; meaning matches are
  shaped for the UI by `MeaningMatchPresenter`.
* AI calls go through `AIModelResolver::ask()` with a `provider:model` string —
  see [AI Providers](/domains/ai-providers.md). Validation via
  `App\Http\Requests\AiQuestionRequest` / `BilingualsTextRequest`.
* Answers are rendered from markdown with the shared
  `AiProvider::markdownToHtml()`.
* On the React page the streamed answer is rendered client-side by
  `renderMarkdown()` in `Bilinguals.jsx` (arrow normalization → `marked.parse`
  → `highlightQuotes()` → DOMPurify sanitize). `highlightQuotes()` wraps
  straight double-quoted `"phrases"` in `<mark class="ai-quote">`, skipping
  anything inside `<pre>`/`<code>` blocks; they render red via
  `.ai-prose mark.ai-quote` using the `--wbench-danger` /
  `--wbench-danger-night` tokens in `public/css/simulator.css`.
* Answer typography is relative to the user-controlled base font size
  (`DEFAULT_FONT_SIZE = 26` in `Bilinguals.jsx`, adjustable ±2px via the
  toolbar `+`/`−` buttons): `.ai-prose h1–h4` are mono-caps labels sized at
  `1.15em` so they stay visibly larger than body text at any size. The
  **gloss-run hover** affordance on `#ai_answer_div` text elements casts a
  crisp accent-tinted shadow just below the glyphs
  (`text-shadow: 0 1px 1px color-mix(...)` on `--wbench-accent`, night variant
  on `--wbench-accent-night`) instead of a background fill.

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
