---
type: Feature
title: Anki Export
description: The path from saved words to the user's desktop Anki — per-user connection settings and card-field toggles on the profile's Anki tab, a deterministic popup-styled card template (the Front is the word, the Back mirrors the reading popup's header/body/footer) with hide-class semantics and popup-structured field values, one-click template sync into Anki, and duplicate-checked per-word sends from the word popup, all driven browser-side through AnkiConnect (ADR 0077).
tags: [anki, anki-connect, srs, export, profile, words, inertia, react]
status: stable
generated: { by: agent:zcode, at: 2026-10-15T13:40:00+03:00 }
verified: { by: agent:zcode, at: 2026-10-15T13:40:00+03:00 }
sources:
  - id: adr
    resource: docs/adr/0077-browser-side-ankiconnect.md
    title: ADR 0077 — Anki export is browser-side; values-always-sent + hide-class template semantics
  - id: anki-settings
    resource: laravel/app/Support/AnkiSettings.php
    title: AnkiSettings (saved connection + field toggles resolved over defaults)
  - id: anki-tab
    resource: laravel/resources/js/Pages/Profile/AnkiSettings.jsx
    title: AnkiSettings tab section (connection settings, field toggles, template preview, template sync + sample card)
  - id: anki-connect
    resource: laravel/resources/js/lib/ankiConnect.js
    title: ankiConnect.js (the only AnkiConnect caller — browser-side protocol wrappers)
  - id: anki-sync
    resource: laravel/resources/js/lib/ankiSync.js
    title: ankiSync.js (ensure deck/note-type/template, memoized per session; duplicate-checked note send)
  - id: anki-template
    resource: laravel/resources/js/lib/ankiTemplate.js
    title: ankiTemplate.js (field-key map, deterministic popup-styled Front/Back/CSS generator, structured buildNoteFields)
  - id: word-popup
    resource: laravel/resources/js/Components/WordPopup.jsx
    title: WordPopup (per-word "Save to Anki" send in the popup footer — UI-language labels baked at send time)
---

# What it does

Gives the words a user saves (the `user_word` rows behind the word popup and
familiarity) a path into **Anki**, the SRS the user actually drills with —
no more hand-copying word data into cards. The feature has three moving
parts, all keyed to the user: **connection settings** (AnkiConnect URL,
port, target deck), **card content settings** (twelve field toggles driving
a deterministic, read-only card template shaped like the app's reading
popup), and two one-click writes —
**template sync** (deck + "ExtApp Vocabulary" note type + Front/Back/CSS
landed in Anki) and **send to Anki** (the Anki tab's sample card, and a
per-word send in the word popup's footer everywhere it renders). Duplicates
are skipped with an "Already in Anki" notice; no existing note is ever
modified. Domain terms are collected in the repo-root `GLOSSARY.md`.

# Why the browser calls Anki, not PHP (ADR 0077)

AnkiConnect is a desktop-Anki add-on listening on the user's own machine
(`127.0.0.1:8765` by default), and the app's Laravel never runs where that
machine is: dev Laravel lives in Docker on WSL2 while Anki runs on the
Windows host; prod is remote. A PHP-side client could only ever reach a
desktop Anki installed on the server itself — no real user's Anki, ever.
So the browser is the only AnkiConnect caller in the codebase, and Laravel
stores settings only. The corollary: CORS is a one-time user setup step
(approve via `requestPermission`, or add the app's origin to
`webCorsOriginList`), and the connection test's failure UI teaches exactly
that. The full decision record — app-owned note type, values-always-sent +
hide-class template semantics, find-before-add duplicates — is
[ADR 0077](../../docs/adr/0077-browser-side-ankiconnect.md) (also in the
sources below).

# The flow

1. **Settings** — the profile's Anki tab autosaves connection + field
   toggles into the `anki` section of the per-user `ui_settings` blob
   (separate from `popup`/`simulator`/`reader`); the server resolves saved
   values over defaults in `AnkiSettings::for()` and shares them to every
   page as the `ankiSettings` Inertia prop. Tab layout, autosave, and
   validation details live in [Profile](/domains/profile.md).
2. **Template** — `generateCardTemplate(fields)` deterministically emits
   Front/Back/CSS in the reading popup's image. The **Front is the word
   alone** — the recognition cue and the duplicate-search key, never
   hidden (the transcription moved off it). The **Back** re-orders the
   twelve classed wrappers into the popup's structure: an `ef-header`
   (word title, then the Familiarity / Frequency / form-of /
   part-of-speech / bracketed-transcription meta lines), an `ef-body`
   (translations first — the popup's primary line — then the
   Definitions/Examples/Etymology sections), an `ef-footer` (a muted
   Tags · Language line, its separator rendered only when both fields
   carry values). Every field keeps its own classed wrapper, a toggled-off
   field additionally carrying the `ef-hidden` hide class. **Values always
   carry every field** — the hide class, not omission, is what a toggle
   changes, so re-syncing the template restyles all existing cards without
   re-exporting a single note (Anki cannot remove fields from a note type
   anyway). One rule breaks the symmetry: **the Front never hides Word** —
   hiding it would blank the card's face; Word's toggle keeps hiding its
   Back wrapper. Meta lines whose value carries literal decoration
   ("Familiarity: …/100", "Frequency: #…", the bracketed transcription)
   sit inside Anki-native `{{#Field}}…{{/Field}}` conditionals, so an
   empty field drops the whole line instead of a dangling prefix. The card
   surface is the popup's: vellum/ink/hairline/verdigris palette light +
   Anki night mode, capped 560px study width centered, serif headword at
   the popup's type scale — **local font stacks only** (no webfont fetch
   at review time). The dictionary structure itself — counts, entry
   labels, summary wording — lives in the field **values**, not the
   template (step 4); the template stays a static, deterministic function
   of the toggles. The tab previews the real generated artifacts in a
   sandboxed iframe with copy buttons.
   The twelve-field list and the connection defaults live canonically in
   `AnkiSettings` (PHP) mirrored by hand into the JS modules — a lockstep
   pinned by `tests/Unit/AnkiSettingsMirrorTest.php`, with the JS side's
   template semantics, AnkiConnect wire shapes, and ensure/send
   orchestration pinned executable by the colocated vitest specs
   (`resources/js/lib/ankiTemplate.test.js`, `ankiConnect.test.js`,
   `ankiSync.test.js`, plus `Components/WordPopup.ankiSend.test.js` — a
   source pin on the popup send's label pass-through, the first vitest
   file outside `lib/`, blessed by the ADR 0077 addendum — stubbed fetch,
   Node only).
3. **Template sync** — one browser-side ensure pass: deck
   create-if-missing, the app-owned "ExtApp Vocabulary" note type
   create-if-missing (twelve fields, fixed order, "Recognition" card), and
   a templates + CSS push when it already exists. A same-name note type
   with a foreign field set raises a warning naming expected vs found — it
   is never rewritten.
4. **Sends** — `buildNoteFields` flattens a word payload (the sample
   fixture on the tab, the popup's live payload in the word popup) into
   exactly twelve escaped values that carry the popup's dictionary
   structure: per-entry "WORD — pos" labels when a word has 2+ dictionary
   entries, counted `<details>` summaries ("Definitions (3)") for
   Definitions/Examples/Etymology — collapsed like the popup, expandable
   natively in Anki's WebView with no JS — the translations preview (the
   popup's first-8 cap) behind a "+N more…" expander, " · "-joined
   transcriptions, and the localized "form of" prefix inside the WordFamily
   value. A `labels` option carries the five UI-language strings (the three
   counted summaries, the expander, "form of"): senders pass their locale's
   wording — both build it with `ankiTemplate.js`'s `uiLabels(t)` over the
   app's `word.*` ui-strings, one shared mapping — and it is frozen into
   the note at send time; English defaults cover senders that pass none,
   and no in-app sender relies on them. The note is then find-before-add duplicate-checked (deck + Word + Language —
   the search word must be the RAW payload word, required, since the note's
   Word field value is HTML-escaped) and added with `allowDuplicate: false`
   as a race net. The first send of a page session auto-ensures the target
   (memoized per endpoint+deck, module state shared between the tab and the
   popup). Failures are never silent, and both surfaces (tab and popup)
   branch on the same error kinds: network errors render the
   connection-hint panel, Anki-side errors (kind `anki`) verbatim,
   note-type mismatches as re-sync warnings. The per-word send's
   behavior — always-visible footer button, independent of the
   popup-visibility toggles — is specified in
   [Interactive Words](/domains/interactive-words.md). Accepted
   consequence: notes sent before the popup-card rework keep their old
   plain `<br>`-joined values, which render as plain lines under the new
   template (bare WordFamily without the "form of" prefix, transcriptions
   "[a, b]") until the note is deleted and re-sent Anki-side — the
   duplicate check blocks a re-send from refreshing it
   (ADR 0077 addendum, point D).

# Boundaries

* The note type is **app-owned**: the twelve-field set is effectively the
  feature's schema version — extending it (audio is the deliberate 13th
  field follow-up) needs an explicit migration story, not a silent push.
* The template is **static by design**: counts, per-entry labels, and
  summary/expander wording are per-note data and live in the field values
  (baked at send time), never in the template — that is what keeps
  generation deterministic and template sync a pure restyle. The ADR 0077
  addendum records the rationale and the old-notes consequence.
* Sends only add: nothing in the user's collection is ever updated or
  deleted, and bulk export of all saved words is a follow-up (out of
  scope).
* Anki operations are never server-initiated — no scheduled exports
  without an open browser; that is the price of the browser-side
  architecture, accepted in ADR 0077.
* ADR 0028's rejection of an Anki-like SRS inside the app stands: the app
  keeps its own familiarity model, and Anki remains the drilling tool the
  user owns.
