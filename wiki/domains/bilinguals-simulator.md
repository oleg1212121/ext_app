---
type: Feature
title: Bilinguals Simulator
description: Side-by-side bilingual reading trainer where users translate and get AI assessment of their translation.
tags: [bilinguals, simulator, ai, inertia]
status: stable
stale_after: 2026-12-21
generated: { by: agent:zcode, at: 2026-09-21T15:30:00Z }
sources:
  - id: controller
    resource: laravel/app/Http/Controllers/Bilinguals/SimulatorController.php
    title: SimulatorController
  - id: routes
    resource: laravel/routes/web.php
    title: Routes
---

# What it does

The simulator presents a text and its aligned counterpart from another
language (an `EntityMatch` produced by the
[alignment pipeline](/domains/sentence-alignment.md) — any language pair of a
work, not just EN/RU). The learner writes their own translation, then asks an
AI model to assess it — meaning accuracy, grammar, corrections, and improved
variants.

# Entry points

| Route | Method | Handler | Purpose |
|-------|--------|---------|---------|
| `/bilinguals/en/ru/simulator` | GET | `SimulatorController::simulator` | Inertia page `Bilinguals/Bilinguals` |
| `/text` | POST | `SimulatorController::text` | Paginated aligned text content (JSON) |
| `/ai/question` | POST | `SimulatorController::askAi` | Ask an AI model about the text (JSON), named `ai.question` |
| `/ai/question/stream` | POST | `SimulatorController::askAiStreamed` | SSE-streamed variant, named `ai.question.stream` |
| `/ai/word-explain` | POST | `SimulatorController::explainWord` | AI Context explanation of a Ctrl-clicked word in its sentence (JSON), named `ai.word-explain` |
| `/ui-settings` | PATCH | `UiSettingsController::update` | Debounced autosave of UI settings sections (`simulator` / `reader`), named `ui-settings.update` |

# Key behavior

* The page loads with a **default model and a detailed default assessment
  prompt**. The default model is computed by `AIModelResolver::firstModelKey()`
  — the globally cheapest model **available to the signed-in user** (enabled
  provider + a User key they stored), sorted by price and grouped by provider.
  When the user has stored no keys the picker is empty and the page shows an
  "Add an API key in your Profile" empty state instead of the model dropdown.
* The text dropdown lists `EntityMatch` records as
  `"<a-side entity name> / <b-side entity name>"`.
* **Read access is gated per Entity, not per match.** Both the dropdown and
  `text()` filter/403 on `EntityAccessService::canReadMatch` — the caller must
  hold an Access grant (or be admin) on **both** entities of the match
  (ADR 0014). A user who uploaded only one side of a work therefore cannot
  read the bilingual simulator content until they also upload/match the other
  side.
* `text()` paginates (default 50/page, max 200) and serves an entity match by
  `entity_match_id` (the meaning matches shaped for the UI by
  `MeaningMatchPresenter`); a legacy `filename` mode still reads pre-aligned
  file pairs from `public/texts/simulator/`. Entity-match responses also
  carry `word_maps` (`{a, b, highlightable}` — the
  [interactive word](/domains/interactive-words.md) maps for both sides;
  `null` in filename mode) and `row_keys` (`mm:{meaningMatchId}` per row,
  `null` in filename mode), so `TextContent` renders both cells through the
  shared `WordText`/`WordPopup` components with a
  `simulator.highlight_words` toolbar toggle.
* **Checking a row's EN checkbox credits a read** (+1 familiarity to the
  EN side's dictionary words, ADR 0028): `onToggleRow` fires one
  best-effort `POST /word-events` scoped to the row's `row_key`; the
  response's familiarity values recolor the words on both sides. The `all_en`
  header checkbox is a controlled React checkbox that reveals the whole
  column and batches one read event per loaded row into a single request.
  Only actual checkbox opens fire events — the localStorage restore paths
  re-check boxes silently.
* AI calls go through `AIModelResolver::ask()` with a `provider:model` string —
  see [AI Providers](/domains/ai-providers.md). Validation via
  `App\Http\Requests\AiQuestionRequest` / `AiWordExplainRequest` /
  `BilingualsTextRequest`.
* **Word-popup Context explanation** (`POST /ai/word-explain`, throttle
  20/min): given the clicked meaning match, side, sentence index within the
  row side, word id, surface and model, the endpoint rebuilds the side's
  sentence list (the same join `MeaningMatchPresenter::sideText` used for
  rendering, so the index is exact), takes the clicked `EntitySentence`
  plus its before/after neighbours **in the same entity** by document
  order, marks the surface with `**…**`, and asks the picked model for a
  2–4-sentence explanation of the word's sense in that context, replied in
  the user's **Native language**. Manual fire only (button on the popup's
  second tab) — see
  [interactive words](/domains/interactive-words.md).
* Answers are rendered from markdown with the shared
  `AiProvider::markdownToHtml()`.
* On the React page the streamed answer is rendered client-side by
  `renderMarkdown()` — extracted to the shared `resources/js/lib/markdown.js`
  module (also used by the word popup's Context explanation tab): arrow
  normalization (all `→`/LaTeX
  arrow forms → `=>`) → `==highlight==` phrases swapped for `\u0001` sentinels
  (fenced/inline code slot-protected first) → `marked.parse` → four HTML
  passes over slot-protected HTML (`<pre>`/`<code>`/`<kbd>`/`<samp>` content
  untouched): sentinel pairs → `<mark>`, correction pairs `X => Y` (also
  `-&gt;`; each side a quoted phrase, a wrapped inline tag, or one word) →
  `.ai-correction` spans, quotes in four styles — `"…"`, `«…»`, `“…”`, `‘…’`
  — → `<mark class="ai-quote">`, and `\d{1,3}%` scores →
  `<mark class="ai-score">` → DOMPurify sanitize.
* Styling in `public/css/simulator.css` (day + night, `--wbench-*` tokens):
  quotes red (`--wbench-danger`), scores as JetBrains Mono chips
  (`--wbench-emphasis` tint), corrections with a danger-struck old side, a
  soft-ink mono `→`, and an accent-underlined new side; `==…==` uses the base
  `mark` emphasis tint; quotes nested inside a correction inherit that side's
  color. GFM tables get hairline rules and mono-caps headers. Inside the AI
  answer panel (`#ai_answer_div`), `--wbench-danger` and `--wbench-emphasis`
  are both overridden to the shared red `#fe2500`.
* The default assessment question (`SimulatorController::simulator`) instructs
  the model to use `##` headings per task, straight double quotes for cited
  words, `~~removed~~`/`**added**` for corrections, `==double equals==` for the
  key weak-point phrases, and `>` blockquotes for improved versions — each
  maps onto a styled element above.
* Answer typography is relative to the user-controlled base font size
  (`DEFAULT_FONT_SIZE = 26` in `Bilinguals.jsx`, adjustable ±2px via the
  toolbar `+`/`−` buttons): `.ai-prose h1–h4` are mono-caps labels sized at
  `1.15em` so they stay visibly larger than body text at any size. The
  **gloss-run hover** affordance on `#ai_answer_div` text elements casts a
  soft gray shadow just below the glyphs
  (`text-shadow: 1px 1px 5px rgb(128 128 128 / 50%)`) instead of a background
  fill.
* The same `+`/`−` buttons also drive the **word-popup typography** (ADR
  0031): `popupFontSizeFor(font_size)` — 65% of the page font, floored at
  14px, capped at 32px — flows `Bilinguals` → `TextContent` → `WordText` →
  `WordPopup`, whose width scales with it (560px at the default 17px). The
  dictionary words themselves are selectable `role="button"` spans, so a
  plain double-click natively selects a word for browser extensions; the
  popup stays Ctrl+click (ADR 0030).

# Frontend

React page `resources/js/Pages/Bilinguals/` (`Bilinguals.jsx` plus `AI/`,
`TextContent/`, `Workplace/` sub-components). Props include `aiModels`
(grouped by provider), `textList`, `show*` feature flags
(`showWorkplace`, `showQuestion`, `showText`, `showAI`), plus the saved UI
settings seeds (`fontSize`, `aiPanelWidth`, `workplaceHeight`, and the
`show*`/`currentModel`/`currentQuestion` props pre-merged with saved values).

# Persistence

Split by write frequency (ADR 0024):

* **Stable settings → DB.** Font size, panel visibility, AI model, question,
  AI panel width, workplace height live in `user_settings.ui_settings`
  (JSONB, `simulator` section). Seeded into page props by
  `SimulatorController::simulator()` (the saved model only if still in the
  user's available list, else the cheapest default; `DEFAULT_QUESTION`
  constant is the question fallback). The frontend writes back via the
  `useUiSettingsAutosave` hook — one debounced (~800 ms) PATCH to
  `/ui-settings` per change burst; the backend section-merges so a simulator
  save never wipes the `reader` section (the Reader page persists its own
  `font_size` the same way). Validation bounds mirror the client clamps
  (`UpdateUiSettingsRequest`).
* **Working state → localStorage, per device.** Key
  `ext_app.simulator.position.v1` (`lib/simulatorPosition.js`): current
  entity match plus, per alignment, the last page and the last opened row
  (`{n, en, ru}` — global row number and which halves were revealed). On
  mount the saved alignment auto-loads at its saved page; the saved row's
  checkboxes are re-checked (controlled `checkedRows` state in
  `TextContent.jsx`) and the row scrolls into view. Switching alignments and
  pressing Load restores each alignment's own saved page instead of resetting
  to page 1. The header RU master checkbox stays uncontrolled; `all_en` is
  controlled React state (`allEn`, reset on every page load) and is
  deliberately NOT persisted; `per_page` is not persisted either.
