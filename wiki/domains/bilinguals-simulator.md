---
type: Feature
title: Bilinguals Simulator
description: Side-by-side bilingual reading trainer where users translate and get AI assessment of their translation, with a native-language default learning side and a per-device language swap.
tags: [bilinguals, simulator, ai, inertia]
status: stable
stale_after: 2026-12-23
generated: { by: agent:zcode, at: 2026-09-24T20:00:00+03:00 }
sources:
  - id: controller
    resource: laravel/app/Http/Controllers/Bilinguals/SimulatorController.php
    title: SimulatorController
  - id: adr-preferences
    resource: docs/adr/0035-per-user-ai-model-preferences.md
    title: ADR 0035 — Per-user AI model preferences, resolved server-side
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
| `/simulator` | GET | `SimulatorController::simulator` | The standalone page with the **alignment picker** (Practice → Simulator, ADR 0038): same Inertia page `Bilinguals/Bilinguals`, no pinned match — a Select + Load header control lists the readable matches and loads one in place via `POST /text`. The old picker URL `/bilinguals/en/ru/simulator` stays deleted (404, test-guarded) |
| `/bilinguals/simulator/{entityMatch}` | GET | `SimulatorController::simulatorForMatch` | Inertia page `Bilinguals/Bilinguals` with the match **pinned by the URL** (opened from an alignment card's Simulator button, ADR 0036): no text selector, the match label is shown instead, 403 without `canReadMatch` |
| `/text` | POST | `SimulatorController::text` | Paginated aligned text content (JSON) |
| `/ai/question` | POST | `SimulatorController::askAi` | Ask an AI model about the text (JSON), named `ai.question` |
| `/ai/question/stream` | POST | `SimulatorController::askAiStreamed` | SSE-streamed variant, named `ai.question.stream` |
| `/ai/word-explain` | POST | `SimulatorController::explainWord` | AI Context explanation of a Ctrl-clicked word in its sentence (JSON), named `ai.word-explain` |
| `/ui-settings` | PATCH | `UiSettingsController::update` | Debounced autosave of UI settings sections (`simulator` / `reader`), named `ui-settings.update` |

# Key behavior

* The page carries **no model picker** (ADR 0035): the answer model is a
  per-user preference picked in the Profile's AI Models tab
  (`user_settings.ai_model_id`, resolved server-side by
  `AIModelResolver::resolveAnswerModel()`). The toolbar shows the effective
  model's label as a link to `/profile?tab=ai`; with API keys stored but no
  model chosen it shows "Choose an AI model" (asking is blocked with the same
  guidance), and with no keys the "Add an API key in your Profile" empty
  state. The default assessment prompt comes from
  `SimulatorController::DEFAULT_QUESTION` — a `:base` **template**: the
  client substitutes the current base column's language name and regenerates
  it on toggle. A saved custom question ships verbatim and is never
  rewritten; a saved copy of the pre-template default (the hardcoded
  "Compare Russian original…" text, `LEGACY_DEFAULT_QUESTION`) counts as not
  customized so it keeps tracking the toggle (ADR 0037).
* **Columns are positional display roles, not languages** (ADR 0037): the
  left **target** column hides the language being learned (revealed per row,
  read-credited); the right **base** column carries the Open/Ask actions and
  pairs with the workplace. A toolbar language radio picks the learning side
  around the server-computed `defaultLearningSide`
  (`EntityMatch::readingSideFor` — native side translates, then the work's
  original, then A-side, shared with the reader); the flip is pure display
  state (rows and word maps swap columns, `WordText` keeps receiving the
  actual match `side` so word-explain payloads stay exact). Column headers
  show the sides' real language names and the toolbar badge the match's real
  codes — no more hardcoded EN/RU.
* Two **entry points share one page** (ADR 0038): the pinned URL from an
  alignment card (match fixed, label shown in the toolbar) and the Practice
  menu's `/simulator` (no pin — the Select + Load picker lists the readable
  matches via `getEntityMatchTextList()`, preselecting the last picker
  choice from the per-device position store). Load fetches the chosen match
  in place via `POST /text`; switching text needs no reload. Saved per-device
  positions (page, revealed row, flip) key on the match id either way, so a
  reopen restores that match's last position.
* **Read access is gated per Entity, not per match.** The pinned route and
  `text()` both 403/filter on `EntityAccessService::canReadMatch` — the
  caller must hold an Access grant (or be admin) on **both** entities of the
  match (ADR 0014). A user who uploaded only one side of a work therefore
  cannot read the bilingual simulator content until they also upload/match
  the other side.
* `text()` paginates (default 50/page, max 200) and serves an entity match by
  `entity_match_id` (the meaning matches shaped for the UI by
  `MeaningMatchPresenter`); a legacy `filename` mode still reads pre-aligned
  file pairs from `public/texts/simulator/`. Entity-match responses also
  carry `word_maps` (`{a, b, highlightable, explainable}` — the
  [interactive word](/domains/interactive-words.md) maps for both sides plus
  the per-side explain-eligibility rule "column language ≠ native language";
  `null` in filename mode), `row_keys` (`mm:{meaningMatchId}` per row,
  `null` in filename mode), and — so the picker page's language toggle tracks
  the loaded match (ADR 0038) — `languages` (`{a, b}` code/name) and
  `default_learning_side` (the same side rule the pinned route applies at
  render time), so `TextContent` renders both cells through the
  shared `WordText`/`WordPopup` components with a
  `simulator.highlight_words` toolbar toggle.
* **Revealing a row's target cell credits a read** (+1 familiarity to the
  learning side's dictionary words, ADR 0028): `onToggleRow` fires one
  best-effort `POST /word-events` scoped to the row's `row_key`; the
  response's familiarity values recolor the words on both sides. The
  `all_target` header checkbox is a controlled React checkbox that reveals
  the whole column and batches one read event per loaded row into a single
  request. Only actual checkbox opens fire events — the localStorage restore
  paths re-check boxes silently.
* AI calls go through `AIModelResolver::ask()`; the model is the user's
  stored answer model, resolved server-side — the client sends no model
  field (ADR 0035) — see
  [AI Providers](/domains/ai-providers.md). Validation via
  `App\Http\Requests\AiQuestionRequest` / `AiWordExplainRequest` /
  `BilingualsTextRequest`.
* **Word-popup Context explanation** (`POST /ai/word-explain`, throttle
  20/min): given the clicked meaning match, side, sentence index within
  the row side, word id and surface, the endpoint rebuilds the side's
  sentence list (the same join `MeaningMatchPresenter::sideText` used for
  rendering, so the index is exact), takes the clicked `EntitySentence`
  plus its before/after neighbours **in the same entity** by document
  order, marks the surface with `**…**`, and asks the user's resolved
  **explanation model** for a 2–4-sentence explanation of the word's sense
  in that context, replied in the user's **Native language**. Manual fire
  only (button on the popup's second tab) — see
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
* Styling in `public/css/simulator.css` (day + night, `--wbench-*` tokens;
  loaded page-scoped via a `<Head>` link in `Bilinguals.jsx`, not globally —
  the global `app.blade.php` link was removed in the 2026-09-23 reader-freeze
  work):
  quotes red (`--wbench-danger`), scores as JetBrains Mono chips
  (`--wbench-emphasis` tint), corrections with a danger-struck old side, a
  soft-ink mono `→`, and an accent-underlined new side; `==…==` uses the base
  `mark` emphasis tint; quotes nested inside a correction inherit that side's
  color. GFM tables get hairline rules and mono-caps headers. Inside the AI
  answer panel (`#ai_answer_div`), `--wbench-danger` and `--wbench-emphasis`
  are both overridden to the shared red `#fe2500`.
* The default assessment question (`SimulatorController::DEFAULT_QUESTION`) instructs
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
`TextContent/`, `Workplace/` sub-components). Props include `pinnedMatch`
(`{id, text}` — the URL-pinned match and its toolbar label; **null** on the
`/simulator` picker entry, where `textList` (`[{id, text}]`, the readable
matches) drives the Select + Load header instead), `languages`
(`{a: {code, name}, b: …}` — labels the columns and feeds the question
template; client state on the picker entry, updated from each `/text`
response), `defaultLearningSide`
('a'|'b' from the shared side rule), `questionTemplate`
(`DEFAULT_QUESTION` with its `:base` placeholder), `answerModel`
(`{id, label}` or null — the resolved answer model shown in the toolbar),
`explanationModelKey` (resolved explanation model id, only discriminates the
word popup's client cache), `show*` feature flags
(`showWorkplace`, `showQuestion`, `showText`, `showAI`), plus the saved UI
settings seeds (`fontSize`, `aiPanelWidth`, `workplaceHeight`, and the
`show*` props; `currentQuestion` ships **null** unless the user customized
the question, in which case it is the verbatim custom text).

# Persistence

Split by write frequency (ADR 0024):

* **Stable settings → DB.** Font size, panel visibility, question, AI panel
  width, workplace height live in `user_settings.ui_settings` (JSONB,
  `simulator` section). **The model is not part of `ui_settings`** — it is a
  typed `user_settings.ai_model_id` preference edited in the Profile
  (ADR 0035; the legacy `simulator.model` key was backfilled into it and
  removed). The **question saves only what the user typed**: the textarea is
  seeded from the `:base` template (regenerated on toggle) and `question`
  persists `null` until an actual edit makes it custom (ADR 0037). Seeded
  into page props by `SimulatorController::simulatorForMatch()`. The
  frontend writes back via the `useUiSettingsAutosave` hook — one debounced
  (~800 ms) PATCH to `/ui-settings` per change burst; the backend
  section-merges so a simulator save never wipes the `reader` section (the
  Reader page persists its own `font_size` the same way). Validation bounds
  mirror the client clamps (`UpdateUiSettingsRequest`).
* **Working state → localStorage, per device.** Key
  `ext_app.simulator.position.v1` (`lib/simulatorPosition.js`): current
  entity match plus, per alignment, the last page, the last opened row
  (`{n, target, base}` — global row number and which display halves were
  revealed; legacy `{n, en, ru}` entries are read positionally), and the
  language `flipped` flag. On mount the pinned match auto-loads at its saved
  page; the saved row's checkboxes are re-checked (controlled `checkedRows`
  state in `TextContent.jsx`) and the row scrolls into view. The base-side
  master checkbox stays uncontrolled; `all_target` is controlled React state
  (`allTarget`, reset on every page load) and is deliberately NOT persisted;
  `per_page` is not persisted either.
