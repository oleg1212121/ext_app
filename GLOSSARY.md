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
into the same per-user settings blob. The endpoint is global; the deck has
moved into the per-language settings.

**Deck**:
An Anki deck — a folder of notes — that words are sent into, addressed by
name. Each enabled language can target its own deck (per-language Anki
settings); words whose language is unknown or not enabled go to the
default target.

**Per-language Anki settings**:
The per-language Anki settings: each enabled language carries its own deck
and card-field toggles; a language the user never customized resolves to
the saved global values, then the defaults. Only what a card says and
where it lands is per-language — the connection settings stay global.

**Card field**:
One of the thirteen named slots every note carries (schema v2, ADR 0077):
the flat reading fields — Word, Transcription, Part of speech, Frequency,
Definitions, Translations, Examples, Etymology, Word family, Tags,
Familiarity, Language — plus Body, the whole rendered dictionary, last.
Values for all of them always ride on every note — a field the user turned
off is hidden, never omitted.

**Note type**:
"ExtApp Vocabulary" and its per-language variants — the app-owned Anki
note types: the thirteen card fields in fixed order (Body last) plus one
card template each. A same-named note type with a different field set is
treated as foreign and warned about, never rewritten.
_Avoid_: model (Anki's API term — fine inside AnkiConnect calls, not in
prose)

**Language note type**:
The per-language note type, "ExtApp Vocabulary ⋅ <code>" — one per enabled
language, same thirteen card fields and same card template today, split so
a language's template can diverge later. A word whose language is not
enabled (or missing) uses the unsuffixed "ExtApp Vocabulary" base type
instead; that legacy type is never rewritten, and its notes stay findable
and updatable like any of the app's own.

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
Sending one word into the configured deck as a note — from the Anki tab's
sample card or the word popup's Anki icon. A word already in the deck is
never duplicated: if the existing note is one of the app's own note types
it is refreshed in place (an update send); only a foreign-model note
leaves the send as a skip ("Already in Anki"). Avoid: export, sync
(reserved for template sync)

**Update send**:
Re-sending a word that is already in the deck: the existing note is
refreshed in place — all thirteen card-field values rewritten and the tag
set replaced — while the note's identity, deck, and review scheduling are
untouched. Notes the app does not own are never updated; they only mark
the word as already sent.

**Sample card**:
The fixture-built note the Anki tab sends as an end-to-end proof that
connection, the selected language's note type and deck, template, and
send all work with the user's real Anki.

## Interactive words

**Word completeness**:
How much is known about a dictionary word, scored 0–7: linked
translations weigh most, and having any definitions, transcriptions,
examples, etymology, and a real frequency rank each add a point. The
word popup uses the score to show a word's fullest entries first.
_Avoid_: quality score, richness

**AI word enrichment (ask AI)**:
Filling a sparse word's entry on demand by asking the user's own AI
model (the explanation model). The reply lands as ordinary dictionary
content with no visual marker, and one successful ask per word locks it
against further asks; failed asks can be retried.
_Avoid_: AI import, auto-enrichment

**Missing word**:
A word clicked in a text that the dictionary holds no entry for. It
opens a placeholder card — the clicked spelling plus a plain
"not in the dictionary yet" note — and asking AI is the only way that
card becomes a real dictionary entry.
_Avoid_: unknown word, out-of-dictionary word
