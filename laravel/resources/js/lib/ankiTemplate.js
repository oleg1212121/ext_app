// The Anki card-template generator (the profile's Anki tab). This module is
// the single JS mirror of App\Support\AnkiSettings::FIELDS — the field-key →
// Anki-field-name mapping lives HERE and nowhere else — and it generates the
// note type's Front/Back HTML and CSS deterministically from the twelve field
// toggles: the same toggles always produce byte-identical artifacts.
//
// Semantics (spec decisions): values ALWAYS carry every field; a toggled-off
// field gets the `ef-hidden` class baked into the generated HTML, so
// re-syncing the template restyles all existing cards instead of dropping
// data. Front shows the recognition pair (Word + Transcription); the Back
// carries all twelve wrappers. Nothing in this module touches Anki — the
// template push and sample card reuse these artifacts from the later Anki
// tickets.

// Settings key → Anki note-type field name, in the note type's fixed field
// order (must stay in lockstep with App\Support\AnkiSettings::FIELDS).
export const ANKI_FIELDS = [
    {key: 'word', name: 'Word'},
    {key: 'transcription', name: 'Transcription'},
    {key: 'part_of_speech', name: 'PartOfSpeech'},
    {key: 'frequency', name: 'Frequency'},
    {key: 'definitions', name: 'Definitions'},
    {key: 'translations', name: 'Translations'},
    {key: 'examples', name: 'Examples'},
    {key: 'etymology', name: 'Etymology'},
    {key: 'word_family', name: 'WordFamily'},
    {key: 'tags', name: 'Tags'},
    {key: 'familiarity', name: 'Familiarity'},
    {key: 'language', name: 'Language'},
]

export const ANKI_NOTE_TYPE = 'ExtApp Vocabulary'
export const ANKI_CARD_NAME = 'Recognition'

const HIDDEN_CLASS = 'ef-hidden'

// The styling side of the "ExtApp Vocabulary" note type: the `.card` base
// plus the hide class the generated HTML leans on, with a night-mode variant
// for Anki's dark theme. Static by design — the toggles shape the HTML, the
// CSS just knows how to hide.
const TEMPLATE_CSS = `.card {
    font-family: Georgia, "Times New Roman", serif;
    font-size: 20px;
    line-height: 1.5;
    color: #2b2620;
    background-color: #f3edde;
    text-align: center;
    padding: 1.5rem;
}
.ef-field { margin: 0.35em 0; }
.ef-word { font-size: 1.9em; font-weight: 600; line-height: 1.2; }
.ef-transcription { font-style: italic; color: #6f6654; }
.ef-part_of_speech { font-style: italic; color: #6f6654; }
.ef-frequency { font-size: 0.8em; color: #8a8170; }
.ef-tags { font-size: 0.8em; color: #8a8170; }
.ef-familiarity { font-size: 0.8em; color: #8a8170; }
.ef-language { font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.08em; color: #8a8170; }
.ef-hidden { display: none; }
.nightMode .card {
    color: #e6dfcf;
    background-color: #26221b;
}
.nightMode .ef-transcription,
.nightMode .ef-part_of_speech { color: #b3a98f; }
.nightMode .ef-frequency,
.nightMode .ef-tags,
.nightMode .ef-familiarity,
.nightMode .ef-language { color: #948b76; }`

// Generate the note type's card template from the field toggles. `fields`
// maps field key → boolean; an absent key means on (the settings blob is
// sparse and the defaults are all-on).
export function generateCardTemplate(fields = {}) {
    const byKey = new Map(ANKI_FIELDS.map((field) => [field.key, field]))

    const wrapper = (key) => {
        const field = byKey.get(key)
        const hidden = fields[key] === false ? ` ${HIDDEN_CLASS}` : ''
        return `<div class="ef-field ef-${key}${hidden}">{{${field.name}}}</div>`
    }

    return {
        front: ['word', 'transcription'].map(wrapper).join('\n'),
        back: ANKI_FIELDS.map(({key}) => wrapper(key)).join('\n'),
        css: TEMPLATE_CSS,
    }
}

const escapeHtml = (value) => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')

// Sentence-sized lists stack vertically; short inline values join compactly.
const listValue = (values) => values.filter(Boolean).map(escapeHtml).join('<br>')
const inlineValue = (values) => values.filter(Boolean).map(escapeHtml).join(', ')

const distinct = (values) => [...new Set(values)]

// Build the complete 12-field note from a word payload (the GET /words/{word}
// shape) — exactly what the send-to-Anki tickets will put on the wire. Every
// field gets a value, toggles or not: hide-class semantics mean the template,
// never an omission, hides content. `nativeLanguage` (a language code) narrows
// Translations to the user's native language, falling back to all
// translations when none match (or nothing was passed).
export function buildNoteFields(data, {familiarity = null, tags = [], nativeLanguage = null} = {}) {
    const entries = data?.entries ?? []
    const translations = entries.flatMap((entry) => entry.translations ?? [])
    const native = nativeLanguage
        ? translations.filter((translation) => translation.language_code === nativeLanguage)
        : []

    return {
        Word: escapeHtml(data?.word ?? ''),
        Transcription: inlineValue(distinct(entries.flatMap((entry) => (entry.transcriptions ?? []).map((t) => t.value)))),
        PartOfSpeech: inlineValue(distinct(entries.map((entry) => entry.word_class))),
        Frequency: data?.frequency == null ? '' : escapeHtml(data.frequency),
        Definitions: listValue(entries.flatMap((entry) => entry.definitions ?? [])),
        Translations: listValue((native.length ? native : translations).map((translation) => translation.word)),
        Examples: listValue(entries.flatMap((entry) => entry.examples ?? [])),
        Etymology: listValue(entries.flatMap((entry) => entry.etymologies ?? [])),
        WordFamily: inlineValue(data?.form_of ?? []),
        Tags: inlineValue(tags),
        Familiarity: familiarity == null ? '' : escapeHtml(familiarity),
        Language: escapeHtml(data?.language_code ?? ''),
    }
}

// Substitute note values for Anki's {{Field}} placeholders — what Anki does
// at review time — so the tab's preview renders the real generated card.
export function renderCardHtml(templateHtml, noteFields = {}) {
    return templateHtml.replace(/\{\{(\w+)\}\}/g, (placeholder, name) => (
        Object.prototype.hasOwnProperty.call(noteFields, name) ? noteFields[name] : placeholder
    ))
}
