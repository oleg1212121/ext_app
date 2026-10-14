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
