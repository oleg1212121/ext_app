# ADR 0077: Anki export is browser-side — the browser calls AnkiConnect, Laravel only stores settings

Date: 2026-10-14
Status: Accepted

## Context

Words a user saves (the `user_word` rows behind the word popup and
familiarity) had no path into Anki, the SRS the user actually drills with —
building a card meant hand-copying word data. AnkiConnect, the desktop
add-on that exposes Anki over HTTP, listens on the user's own machine
(`127.0.0.1:8765` by default). The app's topology makes that address
unreachable from PHP in every deployment that matters: dev runs Laravel
inside Docker on WSL2 while desktop Anki runs on the Windows host, and prod
is a remote server — a PHP-side Anki client could only ever reach a desktop
Anki installed on the same host as the server, i.e. no real user's Anki,
ever. Only the browser is guaranteed to run on the same machine as the
user's desktop Anki, for every user, in every deployment.

Anki's data model added constraints on the card side. Anki can add fields
to an existing note type but can never remove them; it can update a note
type's card templates and styling, and that update restyles every existing
card at once — no per-note re-export. And the note type is a shared,
user-visible object: a same-named model the app didn't create may exist in
a user's collection, and existing notes in it may not be ours.

The settings themselves are per-user UI state — endpoint, target deck,
which of the card's fields to show — shaped like the Popups tab's
visibility toggles but semantically a different thing (connection + card
content, not popup blocks).

## Decisions

### 1. The browser is the only AnkiConnect caller; Laravel stores settings

Laravel persists the connection settings and field toggles in
`ui_settings.anki` (a fourth section of the existing per-user blob, fully
separate from `popup`/`simulator`/`reader`), resolves them over defaults
server-side into the shared `ankiSettings` Inertia prop — and never issues
an AnkiConnect request. One browser client module owns the protocol
(version, `requestPermission`, deck/model/template/note actions; AnkiConnect
reports errors as a string inside HTTP 200, network/CORS failures as fetch
rejections — both normalized into one error type). Everything Anki-side —
connection test, template sync, sample card, per-word send — is the
browser talking to the user's own desktop Anki.

### 2. The note type is app-owned: "ExtApp Vocabulary"

One note type, twelve fields in fixed order (Word, Transcription,
PartOfSpeech, Frequency, Definitions, Translations, Examples, Etymology,
WordFamily, Tags, Familiarity, Language), one card template
("Recognition"). Create-if-missing on sync; a same-name model whose field
set doesn't match the app-owned twelve is a foreign note type — surface a
warning naming expected vs found and never rewrite it. The twelve-field
set is effectively the feature's schema version: extending it later (audio
is the deliberate 13th-field follow-up) needs an explicit migration story,
not a silent push.

### 3. Values always sent; hide-class template semantics

Every note carries all twelve field values, always. A field toggled off in
settings is not omitted from the note — the generated template bakes a
hide class (`ef-hidden`) into that field's wrapper, and the template CSS
hides it (`Anki cannot remove fields` anyway, and omission would make the
stored note lossy). Consequence: re-syncing the template is a pure re-style
— it re-applies the hide classes to all existing cards without re-exporting
a single note — and field toggles never invalidate the send path (a note
sent while the deck still runs an older template is still correct; the
next sync hides the field retroactively).

### 4. The template is generated, deterministic, read-only

Front/Back HTML + CSS are generated deterministically from the field
toggles — every field wrapped in its own classed div — with no
hand-edited HTML/CSS override; the tab shows the real artifacts read-only
with copy buttons and a live preview rendering the generated template with
sample values (what Anki renders at review time). One generator, one
place the card's shape is decided.

### 5. Duplicates are found before they are added; no existing note is ever modified

Each send first searches the deck for the word (scoped to deck + Word +
Language), skips with an "Already in Anki" notice on a hit, and passes
`allowDuplicate: false` as a race net. Nothing in the user's collection is
ever updated or deleted by the app — sends only add.

### 6. The first send of a session auto-ensures the target

Deck, note type, and generated template are ensured (create-if-missing /
push-if-changed) before the first send of a page session, memoized per
endpoint+deck so subsequent sends skip straight to the duplicate check;
the tab's explicit sync button forces a fresh pass.

## Consequences

- **CORS is a one-time user setup step, and the UI teaches it.** A browser
  call from the app's origin to `127.0.0.1` is cross-origin: AnkiConnect's
  default config trusts `http://localhost` only. The connection test's
  failure UI explains the exact fix — approve via AnkiConnect's
  `requestPermission` popup, or add the app's origin to
  `webCorsOriginList` and restart Anki.
- **Language identity crosses the wire in two units, and mixing them fails
  silently.** `buildNoteFields` narrows the Translations field by comparing
  a language *code* against payload `language_code`s — but the user's
  native language is stored in settings as a numeric *id*
  (`user_settings.native_language_id`). An early version passed the id
  where the code belonged; nothing errored, the filter just never matched
  and cards silently carried all translations instead of the native subset.
  The fix passes the code from the shared `auth.user.native_language` prop.
  Rule of thumb: payload-land speaks codes, settings-land speaks ids —
  convert explicitly at the boundary.
- Laravel needs no network path to Anki at all — no server-side config,
  no proxy endpoint, identical behavior in Docker/WSL2 dev and remote prod;
  the cost is that Anki operations can never be server-initiated (no
  scheduled bulk exports without an open browser).
- Bulk export of all saved words stays a follow-up; the per-word send keeps
  sends user-paced, and the duplicate check makes re-sending harmless.

## Addendum (2026-10-15): the card looks like the reading popup

Feature `anki-popup-card` reworked the generated card artifacts: the Front
is the word alone, and the Back re-renders the twelve fields in the
reading popup's own structure — header (word title, then the
Familiarity / Frequency / form-of / part-of-speech / bracketed-transcription
meta lines), dictionary body, muted Tags · Language footer — on the popup's
palette, light and Anki night mode. Every decision above still holds
unchanged: same app-owned note type, same twelve fields in fixed order,
values-always-sent, hide-class semantics, deterministic generation,
find-before-add sends. What follows are the refinements the rework forced.

### A. Structure lives in the field values; the template stays static

The popup shows the dictionary body as per-entry blocks ("MELT — verb"),
counted collapsed sections ("Definitions (3)"), and a translations preview
behind a "+N more…" expander. None of that can live in the template:
counts and entry labels are **per-note data** (true of one note, not of the
note type), and the label wording is the sender's **UI language**, which a
template cannot know. Anki templates are computationally near-blind — a
template can test a field's emptiness and substitute its value, nothing
more. So `buildNoteFields` bakes the structure into the values at send
time: per-entry labels for words with 2+ dictionary entries, counted
summaries, the preview/expander split, " · "-joined transcriptions, and the
localized "form of" prefix inside the WordFamily value. A `labels` option
carries the five UI-language strings (three counted summaries, the
"more" expander, "form of"); both senders (the word popup's send and the
Anki tab's sample card) pass their locale's wording — it is frozen into the
note like every other value, and the English defaults serve no in-app
sender. The template stays a static, deterministic function of the twelve
toggles that renders whatever the values carry; its own literals shrank to
three meta-line prefixes ("Familiarity: …/100", "Frequency: #…", the
bracketed transcription), each wrapped in an Anki-native
`{{#Field}}…{{/Field}}` conditional so an empty field drops the whole line
instead of leaving a dangling "Frequency: #". The form-of line needs no
template literal precisely because its label moved into the value.

### B. The Back's dictionary sections are collapsed `<details>` elements

The card copies the popup's tap-to-open sections with native HTML
`<details>`/`<summary>` — collapsible in Anki's WebView with no JavaScript
and **collapsed by default**, so a review flip shows the word, its
translations, and clean counted summaries instead of a wall of examples;
expanding is one tap where the learner wants depth. The popup's interactive
affordances that mean nothing at review time (the Explanation tab, the
progress buttons, the Save-to-Anki footer) stay off the card.

### C. Local font stacks only

Review must never fetch webfonts: the template CSS uses local stacks only —
Figtree degrading through Segoe UI/Arial sans, Source Serif 4 through
Georgia serif — with no `@import` and no `url()`. A card must look right
offline and never cost a network request per review; the app's exact fonts
are a progressive enhancement, not a dependency.

### D. Previously-sent notes keep their old values until re-sent

Template sync restyles every existing card, but the *values* on notes sent
before this rework are the old plain `<br>`-joined strings — verified to
render as clean plain lines under the new template (no dangling conditionals,
no half-structure). Two cosmetic deltas remain next to a freshly-sent note:
the WordFamily line shows the bare headword list without its "form of"
prefix (the label moved into the value, so old values predate it), and the
transcription line shows "[a, b]" — the old ", " join, not the popup's
" · ". Re-sending cannot refresh an old note: the find-before-add check
(decision 5) resolves the existing Word+Language match as a duplicate and
adds nothing. Old notes update only via Anki-side deletion and re-send, or
future tooling — accepted, per the feature spec.

### E. The send path's pin is the first vitest file outside lib/

ADR 0066 scopes vitest to the pure modules in `lib/`. The popup's
`sendToAnki` is React wiring with no runtime seam, but it carries one real
decision — the send must pass the UI-language labels — so
`Components/WordPopup.ankiSend.test.js` reads the JSX as text and pins the
pass-through: the same source-pin pattern the Pest mirror suites use
(`AnkiSettingsMirrorTest`, `PopupSectionsMirrorTest`). Node-only, nothing
rendered — within ADR 0066's intent, outside its letter. This addendum is
the standing blessing: a source-pin mirror test may live in vitest next to
the wiring it pins, where the pinned decision is untestable at any runtime
seam and the surrounding behavior is already pinned at the real seams
(`ankiSync.test.js`, `ankiTemplate.test.js`).

## Addendum v2 (2026-10-15): Route A — the dictionary body is the 13th field's send-time value

A review of the real card against the popup killed addendum A's remaining
compromise: the popup interleaves — one section per part of speech carrying
that entry's translations, transcriptions, definitions and etymology
together — and flat per-info-type fields can never express that ordering,
however their values are structured. Decision (feature round V2, tickets
06–07): the whole dictionary body is rendered at send time into the value
of a new 13th field, and the body section toggles move from the template to
the send. This addendum supersedes the toggle-blind invariant **for the
body sections only**; everything else above stands.

### Schema v2: thirteen fields, `Body` last

The "ExtApp Vocabulary" note type gains `Body` as its 13th and last field.
The flat fields keep their full structured values — they stay searchable in
Anki's browser — but only `Body` renders the dictionary. The Back is the
header (word title, then the Familiarity / Frequency / form-of /
part-of-speech meta lines; the bracketed header-transcription line is gone —
transcriptions live per-entry in the body), the `{{#Body}}` slot, and the
Tags · Language footer. The slot is dropped whole when the value is empty
(the `body` master toggle off, or a word with no dictionary content), so
the header meets the footer with nothing dangling.

### The toggle-blind invariant is split, not abandoned

For the **scalar toggles** (word, familiarity, frequency, word_family,
part_of_speech, tags, language) nothing changes: values always sent,
hide-class template semantics, re-syncing the template restyles ALL cards —
existing ones included. The **body toggles** (translations, transcription,
definitions, examples, etymology, plus the new `body` master) are
**send-time**: both senders (the popup's per-word send and the tab's sample
card/preview) pass the resolved fields map, and `buildNoteFields` shapes
the Body value accordingly — a dropped section leaves no dangling label or
placeholder behind. Accepted consequence: re-syncing the template no longer
restyles the body content of existing cards — the body a note carries is
the body it was sent with; only a new send re-shapes it, and the
find-before-add duplicate check means an existing note is refreshed only
after Anki-side deletion. The Anki tab's toggle groups are relabeled to say
which semantics each group has (the tab is the only place both kinds are
edited, so the split has to be legible there).

### Value-side fidelity notes (shipped judgement calls)

- The Body labels **every** entry — including a single-entry word's own
  section, exactly the popup's pane. The flat fields keep the V1 rule of
  labeling only when a word has 2+ entries (there a label disambiguates
  stacked entries; in the Body it is the popup's section header).
- The Body's per-entry translations are **not** narrowed by native
  language — the popup renders the payload raw (the backend sorts
  native-first); the flat Translations field keeps its narrowing.
- Two calm placeholder strings ("No translations yet…", "No definitions
  yet.") travel through the labels option beside the counted summaries, so
  a localized sender bakes localized placeholders (seven labels total:
  three counted summaries, the "more" expander, "form of", the two
  placeholders).

### The 12 → 13 field migration is user-visible, and the error teaches it

AnkiConnect cannot add a field to an existing note type, so a pre-v2
twelve-field install fails the field-set check with
`AnkiNoteTypeMismatchError` (expected 13, found 12). The mismatch UI copy
(popup error line and Anki tab) teaches the one-time fix: in Anki, Tools →
Manage Note Types, delete "ExtApp Vocabulary" — its notes are deleted with
it — then sync again and re-send the words. Decisions 2 and 5 stand: no
silent push, no rewrite of a foreign note type, and the deletion is the
user's own action in Anki, not something the app ever does.
