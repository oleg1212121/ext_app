---
type: Feature
title: Interactive Words
description: Dictionary-linked clickable words with familiarity text-color tinting on the reader and bilinguals simulator — Ctrl+click word popups covering every part of speech of the headword, render-time segmentation, read/lookup familiarity events.
tags: [reader, bilinguals, dictionary, words, react, inertia]
status: stable
stale_after: 2026-12-19
generated: { by: agent:zcode, at: 2026-09-19T00:00:00Z }
sources:
  - id: word-controller
    resource: laravel/app/Http/Controllers/WordController.php
    title: WordController (word details + familiarity + events)
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
    title: WordPopup (lazy detail popup)
  - id: familiarity-lib
    resource: laravel/resources/js/lib/wordFamiliarity.js
    title: Browser familiarity helpers (event POST, word ids, map patching)
  - id: tokenizer
    resource: laravel/resources/js/lib/wordTokenizer.mjs
    title: Browser tokenizer (port of App\Classes\WordTokenizer)
  - id: adr
    resource: docs/adr/0028-numeric-word-familiarity.md
    title: ADR 0028 (numeric word familiarity)
---

# What it does

Makes words in a text interactive on both reading surfaces: tokens that link
to a dictionary **Word** open the **Word popup** on **Ctrl+click** — one
section per part of speech recorded under the headword (language + `l_word`),
each with its definitions, transcriptions, translations (native language
first), examples and etymology, plus word progress actions pinned in a footer
that never scrolls away. A plain click does nothing (ADR 0030). Their text is
tinted by the reader's **Word familiarity** (0–19 or no row → rose, 20–59 →
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
   over each row and renders tokens found in the map as word buttons.
   Parity is enforced by `tests/Unit/TokenizerParityTest.php` (PHP vs node
   CLI on shared fixtures); a mismatch breaks coloring, not text.
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
   body scrolls internally. When the surrounding `WordText` has a `rowKey`
   (see below), the click also fires a **lookup event**.
4. **Progress actions.** `PATCH /words/{word}/progress`
   (`familiarity: 0-100`) and `DELETE /words/{word}/progress` implement "I
   know this word" (sets 100) / "Remove mark" (deletes the row). The popup
   reports the change up to the page, which recolors the word in every
   rendered row, and shows the current score ("Familiarity: 12/100").

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
| `PATCH /words/{word}/progress` | `WordController::setFamiliarity` | `UpdateWordProgressRequest` (`familiarity` 0–100); upsert `user_word` |
| `DELETE /words/{word}/progress` | `WordController::resetProgress` | Delete the `user_word` row (back to untouched) |
| `POST /word-events` | `WordController::recordEvents` | Ledger-deduplicated read/lookup events; returns resulting familiarity per word |

# Consuming surfaces

* **Reader** (`/reader-react/{lang}/{entityId}`): props `wordMap`,
  `translationWordMap`, `rowKeys`, `highlight`, `primaryHighlightable`,
  `translationHighlightable`; `ReaderRow` renders both row halves through
  `WordText` (the primary line is a `role="button"` div so word buttons stay
  valid HTML inside it). Lookup events only — no read crediting.
* **Bilinguals simulator** (`POST /text`): response gains `word_maps`
  (`{a, b, highlightable}`; `null` in legacy filename mode) and `row_keys`
  (aligned with `rows`); `TextContent` renders both cells through `WordText`
  and fires read events from the row/column checkboxes.
