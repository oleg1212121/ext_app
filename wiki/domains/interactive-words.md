---
type: Feature
title: Interactive Words
description: Dictionary-linked clickable words with familiarity text-color tinting on the reader and bilinguals simulator — Ctrl+click word popups covering every part of speech of the headword (typography follows the host page's font setting, ADR 0031), an optional second tab with the AI Context explanation of the word in its sentence, render-time segmentation, read/lookup familiarity events.
tags: [reader, bilinguals, dictionary, words, ai, react, inertia]
status: stable
stale_after: 2026-12-21
generated: { by: agent:zcode, at: 2026-09-21T15:30:00Z }
sources:
  - id: word-controller
    resource: laravel/app/Http/Controllers/WordController.php
    title: WordController (word details + familiarity + events)
  - id: word-explain-endpoint
    resource: laravel/app/Http/Controllers/Bilinguals/SimulatorController.php
    title: SimulatorController::explainWord (AI Context explanation)
  - id: word-explain-request
    resource: laravel/app/Http/Requests/AiWordExplainRequest.php
    title: AiWordExplainRequest (explain payload validation)
  - id: familiarity-service
    resource: laravel/app/Classes/WordFamiliarityService.php
    title: WordFamiliarityService (ledger-deduplicated deltas)
  - id: word-map
    resource: laravel/app/Classes/EntityWordMap.php
    title: EntityWordMap (per-entity word map)
  - id: word-text
    resource: laravel/resources/js/Components/WordText.jsx
    title: WordText (segmenting renderer)
  - id: word-popup
    resource: laravel/resources/js/Components/WordPopup.jsx
    title: WordPopup (tabbed lazy detail popup)
  - id: familiarity-lib
    resource: laravel/resources/js/lib/wordFamiliarity.js
    title: Browser familiarity helpers (event POST, word ids, map patching)
  - id: tokenizer
    resource: laravel/resources/js/lib/wordTokenizer.mjs
    title: Browser tokenizer (port of App\Classes\WordTokenizer)
  - id: adr
    resource: docs/adr/0028-numeric-word-familiarity.md
    title: ADR 0028 (numeric word familiarity)
  - id: adr-popup-typography
    resource: docs/adr/0031-popup-typography-follows-page-font.md
    title: ADR 0031 (popup typography follows the host page's font setting)
---

# What it does

Makes words in a text interactive on both reading surfaces: tokens that link
to a dictionary **Word** open the **Word popup** on **Ctrl+click** — one
section per part of speech recorded under the headword (language + `l_word`),
each with its definitions, transcriptions, translations (native language
first), examples and etymology, plus word progress actions pinned in a footer
that never scrolls away. A plain click does nothing (ADR 0030), and a plain
double-click natively selects the word so browser extensions (translators,
dictionaries) can act on it. Their text is
tinted by the reader's **Word familiarity** (0–19 → rose, 20–59 →
amber, 60–99 → verdigris, ≥ 100 → green). Revealing a sentence pair on the
simulator credits its words a read (+1); opening a word's popup costs a
lookup (−2), once per word per sentence pair on both surfaces. All
segmentation is derived at render time — **no word positions are stored
anywhere** (ADR 0027); exposure events are ledgered instead (ADR 0028).

# How it works

1. **Server ships a word map, not markup.** Row text travels as plain
   strings; the page payload carries a compact per-entity map
   `{l_word: {w: word id, s: familiarity 0-100|null}}` built by
   `EntityWordMap` (dictionary-linked `entity_words` left-joined to the
   user's `user_word` rows). Unlinked tokens are absent → plain text.
2. **The browser segments the text.** `WordText` runs
   `lib/wordTokenizer.mjs` — a JS port of the PHP `WordTokenizer` regex —
   over each row and renders tokens found in the map as **`role="button"`
   spans**, not real `<button>` elements: Chromium treats button labels as
   widget chrome, so a double-click on a real button never produces the
   native text selection that browser extensions need. The spans keep the
   button keyboard contract (focusable; Ctrl+Enter / Ctrl+Space open the
   popup). Parity is enforced by `tests/Unit/TokenizerParityTest.php`
   (PHP vs node CLI on shared fixtures); a mismatch breaks coloring, not
   text.
3. **Popup details are lazy, per headword.** Ctrl-clicking a word fetches
   `GET /words/{word}?surface={l_word}`. The endpoint returns **every** `words`
   row sharing the headword — the linked row first, siblings after in
   `EntityWordLinker::classPriority` order — as
   `entries: [{id, word_class, transcriptions, definitions, translations,
   examples, etymologies}]`. `is_form` is true when the surface differs from
   the word's `l_word` (an inflected form resolved by the linker's forms
   pass), shown as "«surface» — form of «lemma»". The popover renders one
   section per entry, translations capped at 8 with a "+N more…" expander.
   It always fits the viewport: it opens below the word, flips above it when
   there is more room above, its height is capped to the larger side, the
   body scrolls internally. **Its typography follows the host page's
   font-size setting** (ADR 0031): the page derives
   `popupFontSizeFor(pageFont)` — 65% of the page font, floored at 14px,
   capped at 32px — and the popup scales with it (width 560px at the default
   17px, still viewport-clamped; inner text sizes em-relative). When the
   surrounding `WordText` has a `rowKey`
   (see below), the click also fires a **lookup event**.
4. **Progress actions.** `PATCH /words/{word}/progress`
   (`familiarity: 0-100`) and `DELETE /words/{word}/progress` implement "I
   know this word" / "Remove mark" (deletes the row). The popup
   reports the change up to the page, which recolors the word in every
   rendered row, and shows the current score ("Familiarity: 12/100").

# Context explanation tab (simulator only)

When the surrounding `WordText` receives a `rowKey`, a `side` and the
simulator's current AI model, the popup grows a tab strip: the dictionary
content above stays on the first tab and a second tab ("Explanation")
offers the **Context explanation** — an AI answer to "what does this word
mean in this sentence?". The Reader passes none of those props, so its
popup renders unchanged, tab-free.

* **The request is manual.** The tab shows an "Explain this word" button;
  pressing it POSTs `/ai/word-explain` (sync JSON, no streaming). Until
  then nothing is spent; no auto-fetch on popup open.
* **Sentence identity travels as an index.** The row text joins a side's
  non-empty sentences in document order with `\n`
  (`MeaningMatchPresenter::sideText`); `WordText` renders each sentence in
  its own inline span (visually identical — the joins render as single
  spaces) and remembers which sentence a Ctrl+click landed in. The payload
  is `{meaning_match_id, side, sentence_index, word_id, surface, model}`;
  the backend rebuilds the same sentence list, so the index resolves to the
  exact clicked `EntitySentence`.
* **Context assembly.** The endpoint takes the sentence before and the
  sentence after the clicked one by document order in the same entity
  (`entity_sentences.order` — neighbours may live in adjacent rows or off
  the current page), marks the surface form inside the clicked sentence
  with `**…**`, and prompts the model to name the sense that applies and
  give the closest native-language equivalent in 2–4 sentences. The reply
  language is the user's **Native language** setting, resolved server-side.
* **Model and access.** The model is the simulator's currently picked
  `provider:model` string (validated like `AiQuestionRequest`); the match
  must pass `EntityAccessService::canReadMatch`. Errors reuse the
  envelope `{data: {data: {error}, code}}`; the endpoint is throttled
  20/min like the other AI routes.
* **Client memo.** Answers are cached per popup instance in a page-lifetime
  `Map` keyed by `meaningMatch|side|sentenceIndex|surface|model` — no
  server-side cache, so re-opening the same word in the same sentence is
  free within the page, and "Ask again" drops the memo and refetches.
* Tests: `tests/Feature/AiWordExplainEndpointTest.php` (context assembly,
  multi-sentence rows, access, validation, provider-error mapping,
  throttle; `AIModelResolver` mocked at the container — provider calls are
  raw cURL and invisible to `Http::fake`).

# Familiarity events (ADR 0028)

* `POST /word-events` (`RecordWordEventsRequest`) takes up to 200 events
  `{row_key, kind: read|lookup, word_ids}`. The
  `WordFamiliarityService` inserts each fresh (user, word, row_key, kind)
  into the `user_word_event` ledger (unique — repeat requests are no-ops),
  applies +1 per fresh read / −2 per fresh lookup clamped to 0–100, and
  responds `{data: {familiarity: {wordId: value}}}` covering every
  referenced word so the client can recolor without a refetch.
* `row_key` scopes one sentence pair: `mm:{meaningMatchId}` (aligned rows)
  or `es:{entitySentenceId}` (unaligned reader rows). The payloads carry
  them one-to-one with the rows — `row_keys` in the simulator's `POST /text`
  response (`null` in legacy filename mode), `rowKeys` on the reader page —
  and the request validates the referenced rows exist.
* **Reads** fire only on the bilinguals simulator (checking a row's EN
  checkbox, or the `all_en` header checkbox which batches the whole loaded
  page into one request), crediting the learning-language side's words.
  **Lookups** fire on both surfaces, on the first popup open of a word
  within a row. The browser fires events best-effort
  (`lib/wordFamiliarity.js`) — failures never block reading.
* Schema: `user_word.familiarity` (unsigned tinyint, default 0) +
  `user_word_event` ledger; see
  [schema-overview](/database/schema-overview.md).

# Highlighting rules

* Tinting applies only to sides whose entity language ≠ the user's native
  language (`primaryHighlightable` / `translationHighlightable` /
  `word_maps.highlightable`), so a native speaker's side stays clean.
* Each surface has a persisted toggle: `reader.highlight` and
  `simulator.highlight_words` in `user_settings.ui_settings`
  (`UpdateUiSettingsRequest`), default on. Tint classes (`.word-unknown`,
  `.word-progress`, `.word-progress-strong`) color the **text** (not the
  background) via `--word-*` tokens with day/night variants, all in
  `resources/css/app.css`. The hover affordance is an **underline** (no color
  change), so the tint stays legible; tier colors win on hover over tinted
  words (ADR 0030).
* **Familiarity patches must never reshape `word_maps`.** The simulator keeps
  `highlightable` inside `wordMaps`; any update via `setWordMaps` must spread
  the whole object (`{...maps, a: patchWordMap(...), b: patchWordMap(...)}`)
  so `wordMaps.highlightable` survives — otherwise every word drops its tint
  and read crediting silently stops (guarded by `highlightable.a`).

# Routes

| Route | Handler | Purpose |
|-------|---------|---------|
| `GET /words/{word}` | `WordController::show` | Word popup payload: headword + `entries` per part of speech (class, transcriptions, definitions, native-first translations cap 100, examples, etymologies), `is_form` |
| `POST /ai/word-explain` | `SimulatorController::explainWord` | Context explanation: prev/current/next sentence of the clicked side's entity + focused prompt through `AIModelResolver::ask`, native-language reply (`AiWordExplainRequest`, throttle 20/min) |
| `PATCH /words/{word}/progress` | `WordController::setFamiliarity` | `UpdateWordProgressRequest` (`familiarity` 0–100); upsert `user_word` |
| `DELETE /words/{word}/progress` | `WordController::resetProgress` | Delete the `user_word` row (back to untouched) |
| `POST /word-events` | `WordController::recordEvents` | Ledger-deduplicated read/lookup events; returns resulting familiarity per word |

# Consuming surfaces

* **Reader** (`/reader-react/{lang}/{entityId}`): props `wordMap`,
  `translationWordMap`, `rowKeys`, `highlight`, `primaryHighlightable`,
  `translationHighlightable`; `ReaderRow` renders both row halves through
  `WordText` (the primary line is a `role="button"` div so the word tokens —
  themselves `role="button"` spans — stay valid HTML inside it) and derives
  the popup font from its own `fontSize`. Lookup events only — no read
  crediting. No `side`/`aiModel` props → no tab strip in the popup.
* **Bilinguals simulator** (`POST /text`): response gains `word_maps`
  (`{a, b, highlightable}`; `null` in legacy filename mode) and `row_keys`
  (aligned with `rows`); `TextContent` renders both cells through `WordText`
  (`side="a"`/`side="b"` and `aiModel = canUseAi ? currentModel : null`
  threaded through), fires read
  events from the row/column checkboxes, and enables the popup's Context
  explanation tab.
