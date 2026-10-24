---
type: Feature
title: Anki Export
description: The path from saved words to the user's desktop Anki — per-user per-language connection/card settings (deck + field toggles per enabled language over a legacy global base) on the profile's Anki tab, a deterministic popup-styled card template (the Front is the word; the Back is header + the 13th Body field's popup-interleaved dictionary + footer) with split toggle semantics — scalar toggles hide-class template-side, body toggles shape the Body value at send time — one-click template sync into Anki, and per-word sends from the word popup that detect on open and refresh an already-saved own note in place, all driven browser-side through AnkiConnect (ADR 0077 + addenda; v3 records the updates-in-place reversal).
tags: [anki, anki-connect, srs, export, profile, words, inertia, react]
status: stable
generated: { by: agent:zcode, at: 2026-10-24T12:30:00+03:00 }
verified: { by: agent:zcode, at: 2026-10-15T17:00:00+00:00 }
sources:
  - id: adr
    resource: docs/adr/0077-browser-side-ankiconnect.md
    title: ADR 0077 — Anki export is browser-side; values-always-sent + hide-class template semantics (+ addenda; v3 — re-sends update own notes in place, per-language targets)
  - id: anki-settings
    resource: laravel/app/Support/AnkiSettings.php
    title: AnkiSettings (saved connection, per-language deck/fields over the legacy global base, resolved per enabled language + default, per-language note-type naming)
  - id: anki-tab
    resource: laravel/resources/js/Pages/Profile/AnkiSettings.jsx
    title: AnkiSettings tab section (language selector, per-language field toggle groups + deck, template preview, template sync + sample card)
  - id: anki-tab-state
    resource: laravel/resources/js/lib/ankiTabState.js
    title: ankiTabState.js (the tab's per-language editing state — whole-section shape, entry accessors, per-language sync config + sample payload)
  - id: anki-word-config
    resource: laravel/resources/js/lib/ankiWordConfig.js
    title: ankiWordConfig.js (the popup's per-word sync config — deck/fields from the word's resolved language entry, note type follows the resolved entry)
  - id: anki-connect
    resource: laravel/resources/js/lib/ankiConnect.js
    title: ankiConnect.js (the only AnkiConnect caller — browser-side protocol wrappers incl. the note-update actions)
  - id: anki-sync
    resource: laravel/resources/js/lib/ankiSync.js
    title: ankiSync.js (ensure deck/note-type/template memoized per endpoint+deck+note type; find-before-add send that updates our own notes in place; memoized on-open duplicate lookup)
  - id: anki-template
    resource: laravel/resources/js/lib/ankiTemplate.js
    title: ankiTemplate.js (field-key map, per-language note-type naming, deterministic popup-styled Front/Back/CSS generator, structured buildNoteFields)
  - id: word-popup
    resource: laravel/resources/js/Components/WordPopup.jsx
    title: WordPopup (per-word Anki send from the popup's action toolbar — detect-on-open, update-in-place, UI-language labels baked at send time)
---

# What it does

Gives the words a user saves (the `user_word` rows behind the word popup and
familiarity) a path into **Anki**, the SRS the user actually drills with —
no more hand-copying word data into cards. The feature has three moving
parts, all keyed to the user: **connection settings** (AnkiConnect URL,
port, target deck), **card content settings** (thirteen field toggles in
two groups whose descriptions say which semantics each kind has — scalar
toggles restyle all cards on template re-sync, body toggles shape the Body
value at send time — driving a deterministic, read-only card template shaped
like the app's reading popup), and two one-click writes —
**template sync** (deck + the language's "ExtApp Vocabulary" note type +
Front/Back/CSS landed in Anki) and **send to Anki** (the Anki tab's sample
card, and a per-word send from the word popup's action toolbar everywhere
it renders). A word already in the deck is never duplicated: a re-send of
one of the app's own notes **refreshes it in place** — all thirteen values
rebuilt, tags replaced, note id / deck / scheduling untouched — and only a
foreign-model note leaves the send as an "Already in Anki" skip (ADR 0077
addendum v3). Deck and card content are **per-language**. Domain terms are
collected in the repo-root `GLOSSARY.md`.

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
hide-class template semantics, find-before-add duplicates, and (addendum
v3) re-sends that refresh our own notes in place across per-language
targets — is
[ADR 0077](../../docs/adr/0077-browser-side-ankiconnect.md) (also in the
sources below).

# The flow

1. **Settings** — the profile's Anki tab autosaves connection + field
   toggles into the `anki` section of the per-user `ui_settings` blob
   (separate from `popup`/`simulator`/`reader`); the server resolves saved
   values over defaults in `AnkiSettings::for()` and shares them to every
   page as the `ankiSettings` Inertia prop. Deck and fields are
   **per-language** (anki-popup-refresh): the section may carry a sparse
   `languages` map keyed by enabled language code (`{deck?, fields?}` per
   entry), the endpoint whitelists exactly those codes (non-enabled codes
   and unknown keys never persist), and `for()` resolves one entry per
   enabled language plus a `default` entry for words with an unknown or
   missing `language_code` — each walking the chain per-language → legacy
   global base → defaults, so a user who never customized a language
   resolves to today's global deck/fields. The tab's language selector
   edits these per-language entries directly (deck + toggles + preview +
   the sync/sample targets all follow the selection; connection stays
   global; `default` is never selectable), autosaving the whole `anki`
   section — connection + complete languages map — on every change. The
   legacy top-level base is deliberately absent from that payload (review
   fixes): the endpoint strips incoming `deck`/`fields` — no rule accepts
   them — and grafts the previously saved pair back onto the stored
   section, so a tab save can never rewrite the read-only base the
   word-level fallback reads; without enabled languages nothing is
   editable (the resolved `default` entry serves as a read-only view and
   the deck input + toggles render disabled). `default` is never
   persistable. Tab layout, autosave, and validation
   details live in [Profile](/domains/profile.md).
2. **Template** — `generateCardTemplate(fields)` deterministically emits
   Front/Back/CSS in the reading popup's image. The **Front is the word
   alone** — the recognition cue and the duplicate-search key, never
   hidden. The **Back** is the popup's shape in three parts: an `ef-header`
   (the word title, then the Familiarity / Frequency / form-of /
   part-of-speech meta lines — the bracketed header-transcription line is
   gone in schema v2; transcriptions live per-entry in the body), the
   `{{#Body}}` slot (dropped whole when the 13th field's value is empty —
   the `body` master toggle off, or a word with no dictionary content), and
   an `ef-footer` (a muted Tags · Language line, its separator rendered
   only when both fields carry values). **The scalar toggles are
   template-side**: every scalar field keeps its own classed wrapper, a
   toggled-off scalar additionally carrying the `ef-hidden` hide class, and
   **values always carry every field** — the hide class, not omission, is
   what a toggle changes, so re-syncing the template restyles all existing
   cards' scalar fields without re-exporting a single note (Anki cannot
   remove fields from a note type anyway). One rule breaks the symmetry:
   **the Front never hides Word** — hiding it would blank the card's face;
   Word's toggle keeps hiding its Back wrapper. Meta lines whose value
   carries literal decoration ("Familiarity: …/100", "Frequency: #…") sit
   inside Anki-native `{{#Field}}…{{/Field}}` conditionals, so an empty
   field drops the whole line instead of a dangling prefix. The **body
   toggles are not template concerns** (step 4): the template renders
   whatever the Body value carries. The card surface is the popup's:
   vellum/ink/hairline/verdigris palette light + Anki night mode, capped
   560px study width centered, serif headword at the popup's type scale —
   **local font stacks only** (no webfont fetch at review time). The
   dictionary structure itself — counts, entry labels, summary wording —
   lives in the field **values**, not the template (step 4); the template
   stays a static, deterministic function of the scalar toggles. The tab
   previews the real generated artifacts in a sandboxed iframe with copy
   buttons.
   The thirteen-field list and the connection defaults live canonically in
   `AnkiSettings` (PHP) mirrored by hand into the JS modules — a lockstep
   pinned by `tests/Unit/AnkiSettingsMirrorTest.php` (the mirror now also
   covers the per-language note-type naming), with the JS side's
   template semantics, AnkiConnect wire shapes (the note-update actions
   included), and ensure/send orchestration pinned executable by the
   colocated vitest specs (`resources/js/lib/ankiTemplate.test.js`,
   `ankiConnect.test.js`, `ankiSync.test.js`, `ankiTabState.test.js`,
   `ankiWordConfig.test.js`, plus the Components-side source pins
   `Components/WordPopup.ankiSend.test.js` — label and fields
   pass-throughs — and `WordPopup.toolbar.test.js`, the first vitest files
   outside `lib/`, blessed by the ADR 0077 addendum — stubbed fetch, Node
   only).
3. **Template sync** — one browser-side ensure pass: deck
   create-if-missing, the app-owned note type create-if-missing — the
   per-language "ExtApp Vocabulary ⋅ <code>" when the caller names one
   (`noteTypeForLanguage`; the tab syncs the selected language's type, the
   unsuffixed "ExtApp Vocabulary" remains the base for words with an
   unknown language and for language-less callers)
   (thirteen fields, fixed order, `Body` last,
   "Recognition" card), and a templates + CSS push when it already exists.
   A same-name note type with a foreign field set raises a warning naming
   the model, expected vs found — it is never rewritten. On a **pre-v2
   twelve-field install** the check fails by design (AnkiConnect cannot add
   a field to an existing note type) and the mismatch copy teaches the
   one-time fix: in Anki, Tools → Manage Note Types, delete the named
   type — its notes are deleted with it — then sync again and re-send the
   words.
4. **Sends** — `buildNoteFields` flattens a word payload (the sample
   fixture on the tab, the popup's live payload in the word popup) into
   exactly thirteen escaped values that carry the popup's dictionary
   structure — reading only the dictionary keys, so the payload's
   provenance state (the header badge's `source`, the toolbar's
   `ai_asked`) never reaches a note. The twelve flat fields keep their
   searchable values:
   per-entry "WORD — pos" labels when a word has 2+ dictionary entries,
   counted `<details>` summaries ("Definitions (3)") for
   Definitions/Examples/Etymology — collapsed like the popup, expandable
   natively in Anki's WebView with no JS, each definition line prefixed
   with its usage labels as plain text ("(устар., редк.) …" — both display
   tiers alike, the prefix is study information rather than styling;
   popup-usage-labels ticket 03) — the translations preview (the
   popup's first-8 cap) behind a "+N more…" expander, " · "-joined
   transcriptions, and the localized "form of" prefix inside the WordFamily
   value. The 13th field, **`Body`, is the rendered dictionary**: one
   hairline-separated section per entry in the popup's order — "WORD —
   pos" label (every entry, single-entry words included, exactly the
   popup's pane), the translations line (cap 8 + expander, or the calm
   "No translations yet" placeholder), the bracketed transcriptions (first
   + "+N more…"), the counted collapsed Definitions/Examples/Etymology
   ("No definitions yet." when empty; a labelled definition line carries
   the same plain-text label prefix) — and it is the only field that
   renders the dictionary on the card. **The body toggles are send-time**:
   both senders pass the resolved fields map, and the body sections
   (translations, transcription, definitions, examples, etymology) plus
   the `body` master shape the Body value accordingly — a dropped section
   leaves no dangling label or placeholder — so they affect **newly sent
   cards only**; existing notes keep the body they were sent with, and
   re-syncing the template does not restyle it (ADR 0077 addendum v2). A
   `labels` option carries the eight UI-language strings (the three
   counted summaries, the expander, "form of", the two calm placeholders,
   and since popup-usage-labels ticket 03 the definition `label` resolver —
   the slug→name mapping behind the plain-text prefix, resolved through
   the same `word.label_*` strings the popup's chips use):
   senders pass their locale's wording — both build it with
   `ankiTemplate.js`'s `uiLabels(t)` over the app's
   `word.*` ui-strings, one shared mapping — and it is frozen into
   the note at send time; English defaults cover senders that pass none,
   and no in-app sender relies on them. The note is then find-before-add
   checked (deck + Word + Language — the search word must be the RAW
   payload word, required, since the note's Word field value is
   HTML-escaped): a fresh word is **added** with `allowDuplicate: false`
   as a race net; a hit on one of the app's OWN note types (the configured
   per-language type, the unsuffixed legacy base, or any name carrying the
   app prefix — a language disabled since the note was sent resolves its
   word to the `default` entry while its note still wears the
   "⋅ <code>" name) is **updated in
   place** — all thirteen values rebuilt, the tag set replaced, the note
   addressed by id alone so deck and review scheduling cannot move (ADR
   0077 addendum v3's decision-5 reversal); a foreign-model-only match is
   skipped, never modified. Each word resolves its own target: the popup
   goes through `ankiWordSyncConfig(settings, code)` — deck + fields from
   `languages[code] ?? languages.default`, global url/port, and the note
   type following the RESOLVED entry, so a code that fell through to
   `default` keeps the base type and a send can never mint a
   "⋅ <code>" type the user never enabled. The first send of a page
   session auto-ensures the target (memoized per endpoint+deck+note type,
   module state shared between the tab and the popup). The popup also
   **detects on open**: the same search runs silently
   (`findExistingNote` — memoized per session, never runs the ensure,
   resolves null on any failure) so the Anki icon can show the word's
   saved state before any click; the icon semantics live in
   [Interactive Words](/domains/interactive-words.md). Failures are never
   silent, and both surfaces (tab and popup)
   branch on the same error kinds: network errors render the
   connection-hint panel, Anki-side errors (kind `anki`) verbatim,
   note-type mismatches with the teaching copy (step 3's delete-and-resync
   fix; the error names the model it inspected). The per-word send's
   behavior — toolbar icon placement, disabled-not-hidden, the
   added/updated/duplicate status lines — is specified in
   [Interactive Words](/domains/interactive-words.md). Legacy notes: a
   word saved before the popup-card rework (plain `<br>`-joined values) or
   before schema v2 (no Body value at all) is brought current by simply
   re-sending it — the update rebuilds all thirteen values, current
   structure included (addendum v3 closes addendum D's old-notes gap; the
   duplicate check no longer blocks a refresh). Only a pre-v2 twelve-field
   note TYPE needs the taught migration (step 3) — AnkiConnect cannot add
   a field to an existing type — and that migration deletes its notes with
   it, after which the re-sends land the full card.

# Boundaries

* The note type is **app-owned**: the field set is effectively the
  feature's schema version (v2: thirteen fields, `Body` last) — extending
  it (audio is the natural next field) needs an explicit migration story,
  not a silent push; AnkiConnect cannot add a field to an existing note
  type, so v2's migration is the taught delete-note-type-and-resync path.
* The template is **static by design**: counts, per-entry labels, and
  summary/expander wording are per-note data and live in the field values
  (baked at send time), never in the template — that is what keeps
  generation deterministic. Template sync is a pure restyle **for the
  scalar fields only** since schema v2: the body toggles are send-time,
  so an existing note's Body value never changes under it (ADR 0077
  addendum v2 records the split and the rationale).
* Sends add or refresh, never destroy: a send creates a note or updates
  one of the app's own in place (ADR 0077 addendum v3's decision-5
  reversal) — nothing in the user's collection is ever deleted by the
  app, foreign-model notes are never touched, and bulk export of all
  saved words is a follow-up (out of scope).
* Anki operations are never server-initiated — no scheduled exports
  without an open browser; that is the price of the browser-side
  architecture, accepted in ADR 0077.
* ADR 0028's rejection of an Anki-like SRS inside the app stands: the app
  keeps its own familiarity model, and Anki remains the drilling tool the
  user owns.
