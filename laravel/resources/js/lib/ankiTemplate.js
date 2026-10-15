// The Anki card-template generator (the profile's Anki tab). This module is
// the single JS mirror of App\Support\AnkiSettings::FIELDS — the field-key →
// Anki-field-name mapping lives HERE and nowhere else — and it generates the
// note type's Front/Back HTML and CSS deterministically from the twelve field
// toggles: the same toggles always produce byte-identical artifacts.
//
// Semantics (spec decisions): values ALWAYS carry every field; a toggled-off
// field gets the `ef-hidden` class baked into the generated HTML, so
// re-syncing the template restyles all existing cards instead of dropping
// data. Front shows the word alone — the recognition cue and the
// duplicate-search key. The Back mirrors the reading popup's shape: a header
// (word title, then Familiarity / Frequency / form-of / part-of-speech /
// bracketed-transcription meta lines), the dictionary body sections, and a
// muted Tags · Language footer. Meta lines whose value carries literal
// decoration sit inside Anki-native `{{#Field}}…{{/Field}}` conditionals, so
// an empty field drops the whole line (renderCardHtml does the same for the
// tab's preview); the form-of line needs none — its localized label is part
// of the value. The values themselves carry the popup's structure: per-entry
// "WORD — pos" labels for multi-entry words, counted <details> sections for
// Definitions/Examples/Etymology, the translations preview behind a
// "+N more…" expander — with the UI-language summary/label wording baked in
// at send time via the labels option (English defaults). One rule breaks the
// hide symmetry: the Front NEVER hides Word — hiding it would blank the
// card's face — and Word's toggle keeps hiding its Back wrapper. Nothing in
// this module touches Anki — the template push and sample card reuse these
// artifacts from lib/ankiSync.js.

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

// Meta lines whose value carries literal decoration (a prefix, a suffix,
// brackets) wrap the whole line in an Anki-native conditional block: an
// empty field drops the line entire instead of leaving a dangling
// "Frequency: #" or "[]" on the card. The wording mirrors the popup's
// English strings (database/seeders/ui-strings/words.php). WordFamily needs
// no conditional: its localized "form of" label is part of the VALUE
// (buildNoteFields bakes it at send time), so an empty field renders an
// empty placeholder — nothing dangles.
const META_LINES = {
    familiarity: (name) => `{{#${name}}}Familiarity: {{${name}}}/100{{/${name}}}`,
    frequency: (name) => `{{#${name}}}Frequency: #{{${name}}}{{/${name}}}`,
    transcription: (name) => `{{#${name}}}[{{${name}}}]{{/${name}}}`,
}

// The footer's " · " join renders only when BOTH footer fields carry values
// — nested conditionals, exactly Anki's own semantics.
const FOOTER_SEPARATOR = '{{#Tags}}{{#Language}}<span class="ef-footer-sep"> · </span>{{/Language}}{{/Tags}}'

const placeholder = (key, name) => (META_LINES[key] ? META_LINES[key](name) : `{{${name}}}`)

// The styling side of the "ExtApp Vocabulary" note type: the popup's card
// surface (vellum, hairline, soft shadow, 560px study width) plus the hide
// class the generated HTML leans on, with a night-mode variant for Anki's
// dark theme. Static by design — the toggles shape the HTML, the CSS just
// knows how to hide. Local font stacks only: review must never fetch
// webfonts, so Figtree and Source Serif 4 degrade to their installed
// neighbors (Segoe UI/Arial, Georgia).
const TEMPLATE_CSS = `.card {
    /* The popup's chrome (WordPopup's popupContainerClass): vellum surface,
       hairline border, soft shadow — capped to the popup's 560px study
       width and centered on the review screen. */
    --ef-surface: #FDFBF5;
    --ef-ink: #1A1814;
    --ef-hairline: #C7BC9C;
    --ef-verdigris: #2F6E66;
    font-family: "Figtree", "Segoe UI", -apple-system, Arial, sans-serif;
    font-size: 17px;
    line-height: 1.5;
    text-align: left;
    color: var(--ef-ink);
    background-color: var(--ef-surface);
    max-width: 560px;
    margin: 12px auto;
    border: 1px solid var(--ef-hairline);
    border-radius: 6px;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2), 0 4px 6px -4px rgba(0, 0, 0, 0.2);
}
/* Anki's night mode (the class lands on the card body itself or an
   ancestor, depending on the platform). */
.nightMode .card, .card.nightMode {
    --ef-surface: #1C1915;
    --ef-ink: #E7DFCC;
    --ef-hairline: #3A352A;
    --ef-verdigris: #6FA89F;
}

/* The popup's sections — header, dictionary body, muted footer — separated
   by the popup's hairline rules (its tab strip above, its footer below). */
.ef-header {
    padding: 14px 14px 10px;
    border-bottom: 1px solid var(--ef-hairline);
}
.ef-body {
    padding: 12px 14px;
}
.ef-footer {
    padding: 8px 14px 10px;
    border-top: 1px solid var(--ef-hairline);
}
.ef-field { margin: 0; }

/* The headword in the popup's title style (font-reading, 1.286em). On the
   Front it is the whole card, so it carries the card padding itself. */
.ef-word {
    font-family: "Source Serif 4", Georgia, "Times New Roman", serif;
    font-size: 1.286em;
    line-height: 1.25;
    padding: 14px;
}
.ef-header .ef-word { padding: 0; }

/* Header meta lines in the popup's small muted type (0.786em opacity-60,
   tabular ranks). */
.ef-familiarity, .ef-frequency {
    font-size: 0.786em;
    font-variant-numeric: tabular-nums;
    opacity: 0.6;
    margin-top: 2px;
}

/* Form-of and part of speech, in the popup's italic annotation style. */
.ef-word_family, .ef-part_of_speech {
    font-size: 0.857em;
    font-style: italic;
    opacity: 0.7;
    margin-top: 4px;
}

/* The bracketed transcription line. */
.ef-transcription {
    font-size: 0.857em;
    opacity: 0.8;
    margin-top: 4px;
}

/* Dictionary body: translations are the popup's primary verdigris line,
   the dictionary sections follow at its 0.857em reading size. */
.ef-translations {
    font-size: 0.929em;
    color: var(--ef-verdigris);
}
.ef-body > .ef-field + .ef-field { margin-top: 8px; }
.ef-definitions, .ef-examples, .ef-etymology {
    font-size: 0.857em;
}

/* Value-side structure (buildNoteFields bakes these classes into the field
   values): the popup's per-entry blocks — its label type and hairline
   section rules — the tap-to-open counted summaries, and the translations
   "+N more…" expander, an inline <details> so it opens into the same
   line instead of breaking the joined list. */
.ef-entry + .ef-entry {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid var(--ef-hairline);
}
.ef-entry-label {
    display: block;
    margin-bottom: 4px;
    /* The popup sizes section labels at 0.786em of the card; the label sits
       inside the section's 0.857em context, so compensate (0.786/0.857). */
    font-size: 0.917em;
    letter-spacing: 0.05em;
    opacity: 0.6;
}
.ef-summary {
    margin-top: 2px;
    cursor: pointer;
    list-style: none;
    opacity: 0.7;
}
.ef-summary::-webkit-details-marker { display: none; }
.ef-definitions-list {
    margin: 6px 0 0;
    padding: 0;
    list-style: decimal inside;
    font-size: 0.929em;
    line-height: 1.375;
}
.ef-definitions-list li + li { margin-top: 4px; }
.ef-examples-list {
    margin: 4px 0 0 16px;
    padding: 0;
    list-style: disc;
    font-style: italic;
    opacity: 0.8;
}
.ef-examples-list li + li { margin-top: 2px; }
.ef-etymology-item {
    margin: 4px 0 0;
    font-style: italic;
    opacity: 0.75;
}
.ef-more { display: inline; }
.ef-more .ef-summary {
    margin: 0 4px;
    text-decoration: underline;
    text-underline-offset: 2px;
}

/* The muted footer line: Tags · Language. */
.ef-tags, .ef-language, .ef-footer-sep {
    font-size: 0.786em;
    opacity: 0.6;
}
.ef-tags, .ef-language { display: inline; }

/* The hide class the field toggles bake into the wrappers. */
.ef-hidden { display: none; }`

// Generate the note type's card template from the field toggles. `fields`
// maps field key → boolean; an absent key means on (the settings blob is
// sparse and the defaults are all-on).
export function generateCardTemplate(fields = {}) {
    const byKey = new Map(ANKI_FIELDS.map((field) => [field.key, field]))

    // `side` exists for one exception: the Front face never hides `word` —
    // it is the recognition cue and the duplicate-search key (sendAnkiNote
    // queries Word:"…"), so hiding it would blank the card's face. Word's
    // toggle keeps hiding its Back wrapper; every other field hides through
    // the class as configured.
    const wrapper = (key, side) => {
        const field = byKey.get(key)
        const hideable = !(side === 'front' && key === 'word')
        const hidden = hideable && fields[key] === false ? ` ${HIDDEN_CLASS}` : ''
        return `<div class="ef-field ef-${key}${hidden}">${placeholder(key, field.name)}</div>`
    }

    // The Front is the recognition cue alone — the word in the popup's serif
    // title style (the .ef-word rule carries the card padding when it is the
    // card's only section).
    const front = wrapper('word', 'front')

    // The Back mirrors the popup's shape. Wrapper order here is the card's
    // visual order; the note type's field order (ANKI_FIELDS) is untouched.
    const back = [
        '<div class="ef-header">',
        wrapper('word', 'back'),
        wrapper('familiarity', 'back'),
        wrapper('frequency', 'back'),
        wrapper('word_family', 'back'),
        wrapper('part_of_speech', 'back'),
        wrapper('transcription', 'back'),
        '</div>',
        '<div class="ef-body">',
        wrapper('translations', 'back'),
        wrapper('definitions', 'back'),
        wrapper('examples', 'back'),
        wrapper('etymology', 'back'),
        '</div>',
        '<div class="ef-footer">',
        wrapper('tags', 'back'),
        FOOTER_SEPARATOR,
        wrapper('language', 'back'),
        '</div>',
    ].join('\n')

    return {
        front,
        back,
        css: TEMPLATE_CSS,
    }
}

const escapeHtml = (value) => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')

// Short inline values join compactly.
const inlineValue = (values) => values.filter(Boolean).map(escapeHtml).join(', ')

const distinct = (values) => [...new Set(values)]

// --- Value-side structure: the popup's dictionary body ----------------------

// The popup's translations preview cap (WordPopup's TRANSLATIONS_PREVIEW).
const TRANSLATIONS_PREVIEW = 8

// English default wording for the value-side labels — the popup's own
// strings (database/seeders/ui-strings/words.php). Senders (the word popup's
// send, the Anki tab) pass their UI language's strings through the `labels`
// option; the wording is baked into the note values at send time (spec), so
// the template stays language-free and deterministic. Trusted app strings —
// never escaped, exactly like the template's literal prefixes.
const DEFAULT_LABELS = {
    definitions: (count) => `Definitions (${count})`,
    examples: (count) => `Examples (${count})`,
    etymology: (count) => `Etymology (${count})`,
    more: (count) => `+${count} more…`,
    formOf: 'form of',
}

// The popup's per-entry section label (WordPopup's sectionLabel, ADR 0045):
// headword uppercase, POS lowercase, em dash — "MELT — verb" for a
// base-word entry, the headword's own form for its matching entry. An
// entry belongs to the headword when its word matches (case-insensitively)
// or is absent. Data-derived text, so it is escaped like every payload value.
const entryMatchesHeadword = (entry, headword) =>
    !entry.word || entry.word.toLowerCase() === String(headword ?? '').toLowerCase()

const entryLabel = (entry, headword) => [
    String(entryMatchesHeadword(entry, headword) ? headword : entry.word ?? '').toUpperCase(),
    entry.word_class?.toLowerCase(),
].filter(Boolean).map(escapeHtml).join(' — ')

// One tap-to-open counted section, the popup's <details> summary.
const countedSection = (summary, content) =>
    `<details class="ef-details"><summary class="ef-summary">${summary}</summary>${content}</details>`

const listItems = (items) => items.map((item) => `<li>${escapeHtml(item)}</li>`).join('')

// The popup's per-entry blocks for one field: only entries carrying content
// render (no dangling label over nothing), each wrapped with its
// "WORD — pos" label when the payload has 2+ entries — a single-entry word
// gets the popup's simple unlabeled case.
const entrySections = (entries, headword, labeled, render) => entries
    .map((entry) => {
        const section = render(entry)
        if (section === null) {
            return null
        }
        const label = labeled ? entryLabel(entry, headword) : null
        return label
            ? `<div class="ef-entry"><span class="ef-entry-label">${label}</span>${section}</div>`
            : section
    })
    .filter(Boolean)
    .join('\n')

// The localized "form of" label is part of the VALUE, so the template needs
// no literal (and no conditional): an empty list yields an empty value and
// the meta line disappears whole.
const formOfValue = (forms, formOf) => {
    const list = forms.filter(Boolean).map(escapeHtml).join(', ')
    return list === '' ? '' : `${formOf} ${list}`
}

// Build the complete 12-field note from a word payload (the GET /words/{word}
// shape) — exactly what the send-to-Anki paths put on the wire. Every field
// gets a value, toggles or not: hide-class semantics mean the template,
// never an omission, hides content. `nativeLanguage` (a language code) narrows
// Translations to the user's native language, falling back to all
// translations when none match (or nothing was passed). `labels` carries the
// sender's UI-language summary/expander/form-of strings (English defaults).
export function buildNoteFields(data, {familiarity = null, tags = [], nativeLanguage = null, labels = {}} = {}) {
    const entries = data?.entries ?? []
    const word = data?.word ?? ''
    const wording = {...DEFAULT_LABELS, ...labels}

    // 2+ dictionary entries get the popup's per-entry section labels.
    const labeled = entries.length >= 2

    const translations = entries.flatMap((entry) => entry.translations ?? [])
    const native = nativeLanguage
        ? translations.filter((translation) => translation.language_code === nativeLanguage)
        : []
    const words = (native.length ? native : translations).map((translation) => translation.word)
    const previewed = words.slice(0, TRANSLATIONS_PREVIEW).map(escapeHtml).join(', ')
    const rest = words.slice(TRANSLATIONS_PREVIEW)

    return {
        Word: escapeHtml(word),
        Transcription: distinct(entries.flatMap((entry) => (entry.transcriptions ?? []).map((t) => t.value)))
            .map(escapeHtml).join(' · '),
        PartOfSpeech: inlineValue(distinct(entries.map((entry) => entry.word_class))),
        Frequency: data?.frequency == null ? '' : escapeHtml(data.frequency),
        Definitions: entrySections(entries, word, labeled, (entry) => {
            const items = entry.definitions ?? []
            return items.length === 0 ? null : countedSection(
                wording.definitions(items.length),
                `<ol class="ef-definitions-list">${listItems(items)}</ol>`,
            )
        }),
        Translations: rest.length === 0
            ? previewed
            : `${previewed}<details class="ef-more"><summary class="ef-more-summary">${wording.more(rest.length)}</summary>${rest.map(escapeHtml).join(', ')}</details>`,
        Examples: entrySections(entries, word, labeled, (entry) => {
            const items = entry.examples ?? []
            return items.length === 0 ? null : countedSection(
                wording.examples(items.length),
                `<ul class="ef-examples-list">${listItems(items)}</ul>`,
            )
        }),
        Etymology: entrySections(entries, word, labeled, (entry) => {
            const items = entry.etymologies ?? []
            return items.length === 0 ? null : countedSection(
                wording.etymology(items.length),
                items.map((item) => `<p class="ef-etymology-item">${escapeHtml(item)}</p>`).join(''),
            )
        }),
        WordFamily: formOfValue(data?.form_of ?? [], wording.formOf),
        Tags: inlineValue(tags),
        Familiarity: familiarity == null ? '' : escapeHtml(familiarity),
        Language: escapeHtml(data?.language_code ?? ''),
    }
}

// Substitute note values for Anki's field syntax — the {{Field}} placeholder
// and the conditional block {{#Field}}…{{/Field}} — so the tab's preview
// renders exactly what Anki renders at review time. Conditional blocks
// resolve one nesting level per pass: a block whose field carries a value is
// replaced by its content, an empty field drops the whole block (a
// literal-prefixed meta line disappears entire), and an unknown field name
// is left untouched like an unknown placeholder. Values are substituted
// only after the conditionals have resolved, so a value carrying {{…}} text
// is never re-parsed as template syntax — the same order Anki uses.
export function renderCardHtml(templateHtml, noteFields = {}) {
    const known = (name) => Object.prototype.hasOwnProperty.call(noteFields, name)
    const isEmpty = (value) => value == null || String(value) === ''
    const conditional = /\{\{#(\w+)\}\}([\s\S]*?)\{\{\/\1\}\}/g

    let html = templateHtml
    let previous = null
    while (html !== previous) {
        previous = html
        html = html.replace(conditional, (block, name, content) => (
            known(name) ? (isEmpty(noteFields[name]) ? '' : content) : block
        ))
    }

    return html.replace(/\{\{(\w+)\}\}/g, (placeholder, name) => (
        known(name) ? String(noteFields[name] ?? '') : placeholder
    ))
}
