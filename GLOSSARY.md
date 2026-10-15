# ext_app

Terms of art used in ext_app, a bilingual language-learning app. Definitions
say what a term **is**; where rival names exist, the preferred one leads and
the rest are listed under _Avoid_.

## Anki export

Everything around sending saved words into a user's desktop
[Anki](https://apps.ankiweb.net) — connection settings, the generated card
template, template sync, and per-word sends.

**AnkiConnect**:
The desktop Anki add-on that exposes Anki over local HTTP (`127.0.0.1:8765`
by default). In this app it is called only from the user's browser, never
from the server.

**Connection settings**:
The per-user Anki endpoint (URL + port) and target deck name, edited on the
profile's Anki tab. Distinct from popup visibility even though both autosave
into the same per-user settings blob.

**Card field**:
One of the thirteen named slots every note carries (schema v2, ADR 0077):
the flat reading fields — Word, Transcription, Part of speech, Frequency,
Definitions, Translations, Examples, Etymology, Word family, Tags,
Familiarity, Language — plus Body, the whole rendered dictionary, last.
Values for all of them always ride on every note — a field the user turned
off is hidden, never omitted.

**Note type**:
"ExtApp Vocabulary" — the app-owned Anki note type: the thirteen card
fields in fixed order (Body last) plus one card template. A same-named
note type with a different field set is treated as foreign and warned
about, never rewritten.
_Avoid_: model (Anki's API term — fine inside AnkiConnect calls, not in
prose)

**Card template**:
The Front/Back HTML + CSS Anki renders a note with, generated
deterministically from the user's field toggles and never hand-edited:
Front shows the word alone; the Back is the reading popup's
header/dictionary/footer shape — scalar header and footer fields in
classed wrappers around the Body slot, which renders the whole dictionary
from its send-time-shaped value (the other flat fields stay searchable
note values the template no longer renders; schema v2, ADR 0077).

**Hide class**:
The marker (`ef-hidden`) the generated template bakes into a toggled-off
scalar field's wrapper so the template hides it while the note still
carries the value. Toggling the template fields off and re-syncing
restyles existing cards without re-exporting any note (the body section
toggles work at send time instead — they shape the Body value when a card
is sent; schema v2, ADR 0077).

**Template sync**:
The one-click push that makes Anki match the app: deck create-if-missing,
the app-owned note type create-if-missing, then the generated Front/Back/CSS
applied. Avoid: export, push, upload

**Send to Anki**:
Adding one word to the configured deck as a note — from the Anki tab's
sample card or the word popup's "Save to Anki" button. A word already in
the deck is skipped ("Already in Anki"), and no existing note is ever
modified. Avoid: export, sync (reserved for template sync)

**Sample card**:
The fixture-built note the Anki tab sends as an end-to-end proof that
connection, note type, template, and send all work with the user's real
Anki.
