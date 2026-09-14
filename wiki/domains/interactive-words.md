---
type: Feature
title: Interactive Words
description: Dictionary-linked clickable words with knowledge tinting on the reader and bilinguals simulator — render-time segmentation, lazy word popups, word progress actions.
tags: [reader, bilinguals, dictionary, words, react, inertia]
status: stable
stale_after: 2026-12-13
generated: { by: agent:zcode, at: 2026-09-13T21:00:00Z }
sources:
  - id: word-controller
    resource: laravel/app/Http/Controllers/WordController.php
    title: WordController (word details + progress)
  - id: word-map
    resource: laravel/app/Classes/EntityWordMap.php
    title: EntityWordMap (per-entity word map)
  - id: word-text
    resource: laravel/resources/js/Components/WordText.jsx
    title: WordText (segmenting renderer)
  - id: word-popup
    resource: laravel/resources/js/Components/WordPopup.jsx
    title: WordPopup (lazy detail popup)
  - id: tokenizer
    resource: laravel/resources/js/lib/wordTokenizer.mjs
    title: Browser tokenizer (port of App\Classes\WordTokenizer)
  - id: adr
    resource: docs/adr/0027-render-time-word-segmentation.md
    title: ADR 0027 (render-time word segmentation)
---

# What it does

Makes words in a text interactive on both reading surfaces: tokens that link
to a dictionary **Word** render as clickable buttons that open a popup with
definitions, transcriptions, translations (native language first) and word
progress actions; their background is tinted by the reader's **Word
progress** (unknown → rose, learning/solved → amber, known → no tint). All
of this is derived at render time — **no word positions are stored anywhere**
(ADR 0027).

# How it works

1. **Server ships a word map, not markup.** Row text travels as plain
   strings; the page payload carries a compact per-entity map
   `{l_word: {w: word id, s: status|null}}` built by `EntityWordMap`
   (dictionary-linked `entity_words` left-joined to the user's `user_word`
   rows). Unlinked tokens are absent → plain text.
2. **The browser segments the text.** `WordText` runs
   `lib/wordTokenizer.mjs` — a JS port of the PHP `WordTokenizer` regex —
   over each row and renders tokens found in the map as word buttons.
   Parity is enforced by `tests/Unit/TokenizerParityTest.php` (PHP vs node
   CLI on shared fixtures); a mismatch breaks coloring, not text.
3. **Popup details are lazy.** Clicking a word fetches
   `GET /words/{word}?surface={l_word}` — `is_form` is true when the surface
   differs from the word's `l_word` (an inflected form resolved by the
   linker's forms pass), shown as "«surface» — form of «lemma»".
4. **Progress actions.** `PATCH /words/{word}/progress` (`status: known`)
   and `DELETE /words/{word}/progress` implement "I know this word" / "Remove
   mark" — the route ADR 0025 anticipated; the crossword remains a second
   writer of the same `user_word` rows. The popup reports the change up to
   the page, which recolors the word in every rendered row.

# Highlighting rules

* Tinting applies only to sides whose entity language ≠ the user's native
  language (`primaryHighlightable` / `translationHighlightable` /
  `word_maps.highlightable`), so a native speaker's side stays clean.
* Each surface has a persisted toggle: `reader.highlight` and
  `simulator.highlight_words` in `user_settings.ui_settings`
  (`UpdateUiSettingsRequest`), default on. Tint classes (`.word-unknown`,
  `.word-progress`) and the `.word-token` affordance live in
  `resources/css/app.css` with day/night variants.

# Routes

| Route | Handler | Purpose |
|-------|---------|---------|
| `GET /words/{word}` | `WordController::show` | Word popup payload: lemma, class, `is_form`, transcriptions, definitions, native-first translations (cap 100), examples |
| `PATCH /words/{word}/progress` | `WordController::markKnown` | `UpdateWordProgressRequest` (`status` must be `known`); upsert `user_word` |
| `DELETE /words/{word}/progress` | `WordController::resetProgress` | Delete the `user_word` row (back to unknown) |

# Consuming surfaces

* **Reader** (`/reader-react/{lang}/{entityId}`): props `wordMap`,
  `translationWordMap`, `highlight`, `primaryHighlightable`,
  `translationHighlightable`; `ReaderRow` renders both row halves through
  `WordText` (the primary line is a `role="button"` div so word buttons stay
  valid HTML inside it).
* **Bilinguals simulator** (`POST /text`): response gains `word_maps`
  (`{a, b, highlightable}`; `null` in legacy filename mode); `TextContent`
  renders both cells through `WordText`.
