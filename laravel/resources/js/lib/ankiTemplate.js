// The Anki card-template generator (the profile's Anki tab). This module is
// the single JS mirror of App\Support\AnkiSettings::FIELDS — the field-key →
// Anki-field-name mapping lives HERE and nowhere else — and it generates the
// note type's Front/Back HTML and CSS deterministically from the scalar field
// toggles: the same toggles always produce byte-identical artifacts.
//
// Semantics (spec decisions, V2): values ALWAYS carry every field; a
// toggled-off scalar gets the `ef-hidden` class baked into the generated
// HTML, so re-syncing the template restyles all existing cards instead of
// dropping data. Front shows the word alone — the recognition cue and the
// duplicate-search key. The Back mirrors the reading popup's shape: a header
// (word title, then the Familiarity / Frequency / form-of / part-of-speech
// meta lines), the dictionary Body, and a muted Tags · Language footer. The
// dictionary itself is the 13th field's VALUE: Body carries the popup's
// per-entry sections — "WORD — pos" label, translations (preview cap,
// "+N more…" expander, the calm empty placeholder), bracketed transcriptions,
// counted collapsed Definitions/Examples/Etymology, hairline separators —
// interleaved in exactly the popup's order (flat fields can never express
// that ordering). The body section toggles (translations, transcription,
// definitions, examples, etymology) plus the `body` master toggle are
// SEND-TIME: senders pass the resolved fields map and the value is shaped
// accordingly, so they affect newly sent cards only. Meta lines whose value
// carries literal decoration sit inside Anki-native `{{#Field}}…{{/Field}}`
// conditionals, so an empty field drops the whole line (renderCardHtml does
// the same for the tab's preview); the form-of line needs none — its
// localized label is part of the value. UI-language wording for the value's
// labels is baked in at send time via the labels option (English defaults).
// One rule breaks the hide symmetry: the Front NEVER hides Word — hiding it
// would blank the card's face — and Word's toggle keeps hiding its Back
// wrapper. Nothing in this module touches Anki — the template push and
// sample card reuse these artifacts from lib/ankiSync.js.

// Settings key → Anki note-type field name, in the note type's fixed field
// order (must stay in lockstep with App\Support\AnkiSettings::FIELDS).
// Schema v2: `body` is the 13th field, last — the value-carrier of the
// dictionary body.
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
    {key: 'body', name: 'Body'},
]

export const ANKI_NOTE_TYPE = 'ExtApp Vocabulary'
export const ANKI_CARD_NAME = 'Recognition'

// The join between ANKI_NOTE_TYPE and a language code in a per-language note
// type name — exported for the sync layer's ownership check (a note type is
// ours when it wears the app prefix, i.e. starts with base + separator).
// The PHP twin is App\Support\AnkiSettings::NOTE_TYPE_SEPARATOR, pinned by
// tests/Unit/AnkiSettingsMirrorTest.php.
export const ANKI_NOTE_TYPE_SEPARATOR = ' ⋅ '

// The resolved-entry key for words whose language_code is unknown or
// missing — a member of the settings' `languages` map, never a persistable
// language code. The PHP twin is App\Support\AnkiSettings::DEFAULT_LANGUAGE_KEY,
// pinned by tests/Unit/AnkiSettingsMirrorTest.php.
export const ANKI_DEFAULT_LANGUAGE_KEY = 'default'

// Per-language note type name (anki-popup-refresh): "ExtApp Vocabulary ⋅ en"
// — one template per language, identical today, free to diverge later. An
// empty or missing code (a word with unknown/missing language_code resolves
// to the settings' `default` entry, whose note type is the base name) falls
// back to the unsuffixed legacy name, whose existing notes stay found +
// updatable. The PHP twin is App\Support\AnkiSettings::noteTypeForLanguage
// over NOTE_TYPE_BASE + NOTE_TYPE_SEPARATOR, pinned by
// tests/Unit/AnkiSettingsMirrorTest.php; the behavior here is pinned by
// ankiTemplate.test.js.
export function noteTypeForLanguage(code) {
    return code ? `${ANKI_NOTE_TYPE}${ANKI_NOTE_TYPE_SEPARATOR}${code}` : ANKI_NOTE_TYPE
}

const HIDDEN_CLASS = 'ef-hidden'

// Meta lines whose value carries literal decoration (a prefix, a suffix)
// wrap the whole line in an Anki-native conditional block: an empty field
// drops the line entire instead of leaving a dangling "Frequency: #" on the
// card. The wording mirrors the popup's English strings
// (database/seeders/ui-strings/words.php). WordFamily needs no conditional:
// its localized "form of" label is part of the VALUE (buildNoteFields bakes
// it at send time), so an empty field renders an empty placeholder — nothing
// dangles. The V1 bracketed-transcription header line is gone (schema v2):
// transcriptions live per-entry in the Body value.
const META_LINES = {
    familiarity: (name) => `{{#${name}}}Familiarity: {{${name}}}/100{{/${name}}}`,
    frequency: (name) => `{{#${name}}}Frequency: #{{${name}}}{{/${name}}}`,
}

// The footer's " · " join renders only when BOTH footer fields carry values
// — nested conditionals, exactly Anki's own semantics.
const FOOTER_SEPARATOR = '{{#Tags}}{{#Language}}<span class="ef-footer-sep"> · </span>{{/Language}}{{/Tags}}'

// The Body slot: Anki's conditional drops it whole when the value is empty
// (the `body` master toggle off, or a word with no dictionary content) so
// the header meets the footer with nothing dangling between.
const BODY_SLOT = '{{#Body}}<div class="ef-dictionary">{{Body}}</div>{{/Body}}'

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
   by the popup's hairline rules (its toolbar/header hairlines above, its
   footer below). */
.ef-header {
    padding: 14px 14px 10px;
    border-bottom: 1px solid var(--ef-hairline);
}
.ef-dictionary {
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

/* Dictionary body: the Body value's per-entry sections (buildNoteFields
   bakes these classes into the field value). Translations are the popup's
   primary verdigris line; the popup's hairline rules separate the entry
   blocks; a small even rhythm between an entry's lines stands in for its
   mt-0.5/mt-1/mt-2 mix. */
.ef-translations {
    font-size: 0.929em;
    color: var(--ef-verdigris);
}
.ef-transcriptions {
    font-size: 0.857em;
    opacity: 0.8;
}
.ef-entry + .ef-entry {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid var(--ef-hairline);
}
.ef-entry > * + * { margin-top: 4px; }
.ef-entry-label {
    display: block;
    margin-bottom: 4px;
    /* The popup sizes section labels at 0.786em of the card; the entry sits
       at the card's base size (each line carries its own em), so the
       label's size is the popup's own. */
    font-size: 0.786em;
    letter-spacing: 0.05em;
    opacity: 0.6;
}
/* The counted sections carry the popup's dictionary size (0.857em) on the
   <details> itself; the definitions list inside compounds one step like the
   popup's text-[0.929em] does. Both summary flavors — the counted sections'
   ef-summary and the expander's ef-more-summary — share the tap affordance.
   The expander's summary is its own class (the baked values carry
   ef-more-summary, so the selectors must name that class: values are frozen
   into already-sent notes and a .ef-more .ef-summary descendant would match
   nothing), styled by the later rule. */
.ef-details {
    margin-top: 2px;
    font-size: 0.857em;
}
.ef-summary, .ef-more-summary {
    cursor: pointer;
    list-style: none;
    opacity: 0.7;
}
.ef-summary::-webkit-details-marker, .ef-more-summary::-webkit-details-marker { display: none; }
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
/* The calm empty-state lines (the popup's "No translations yet" /
   "No definitions yet"). */
.ef-empty {
    margin: 0;
    font-size: 0.857em;
    opacity: 0.6;
}
.ef-more { display: inline; }
.ef-more-summary {
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

/* The hide class the scalar field toggles bake into the wrappers. */
.ef-hidden { display: none; }`

// Generate the note type's card template from the scalar field toggles.
// `fields` maps field key → boolean; an absent key means on (the settings
// blob is sparse and the defaults are all-on). The body toggles are not
// template concerns: the Body value is shaped at send time.
export function generateCardTemplate(fields = {}) {
    const byKey = new Map(ANKI_FIELDS.map((field) => [field.key, field]))

    // `side` exists for one exception: the Front face never hides `word` —
    // it is the recognition cue and the duplicate-search key (sendAnkiNote
    // queries Word:"…"), so hiding it would blank the card's face. Word's
    // toggle keeps hiding its Back wrapper; every other scalar hides through
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

    // The Back mirrors the popup's shape: header meta, the Body slot (the
    // whole dictionary, rendered from the send-time Body value), the muted
    // footer. Wrapper order here is the card's visual order; the note type's
    // field order (ANKI_FIELDS) is untouched.
    const back = [
        '<div class="ef-header">',
        wrapper('word', 'back'),
        wrapper('familiarity', 'back'),
        wrapper('frequency', 'back'),
        wrapper('word_family', 'back'),
        wrapper('part_of_speech', 'back'),
        '</div>',
        BODY_SLOT,
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

// The popup's preview caps (WordPopup's TRANSLATIONS_PREVIEW /
// TRANSCRIPTIONS_PREVIEW).
const TRANSLATIONS_PREVIEW = 8
const TRANSCRIPTIONS_PREVIEW = 1

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
    noTranslations: 'No translations yet — automatic translation may still be in progress.',
    noDefinitions: 'No definitions yet.',
}

// The localized counterpart of the defaults: the same seven keys pulled from
// the app's own ui-strings (database/seeders/ui-strings/words.php). Both
// senders (the word popup's sendToAnki, the Anki tab's sampleNoteFields)
// call this with their `useI18n` t — one definition of the mapping, so the
// senders cannot drift apart and a new key lands everywhere at once. The
// key names here are the single JS statement of which ui-strings feed the
// labels option.
export function uiLabels(t) {
    return {
        definitions: (count) => t('word.definitions', {count}),
        examples: (count) => t('word.examples', {count}),
        etymology: (count) => t('word.etymology', {count}),
        more: (count) => t('word.more', {count}),
        formOf: t('word.form_of'),
        noTranslations: t('word.no_translations'),
        noDefinitions: t('word.no_definitions'),
    }
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

// A definition arrives as the popup payload's {text, labels} object
// (popup-usage-labels ticket 01); plain strings (older payloads, hand
// fixtures) normalize through the same door. The labels ride for the
// popup's chips only — at this boundary a definition is its text, full
// stop (ticket 03 adds the plain-text prefix).
const definitionText = (definition) => (typeof definition === 'string' ? definition : definition.text)

// The three counted collapsed sections — Definitions, Examples, Etymology —
// in the popup's shape. Shared by the flat fields (entrySections: an empty
// entry renders nothing) and the Body's per-entry sections (entryBody:
// Definitions swaps in the popup's calm placeholder, the other two drop
// whole), so the summary-wording + list-markup composition lives here once
// and the two contexts cannot drift. Null on empty: each caller tells its
// own empty story.
const definitionsSection = (items, wording) => items.length === 0 ? null
    : countedSection(wording.definitions(items.length), `<ol class="ef-definitions-list">${listItems(items.map(definitionText))}</ol>`)

const examplesSection = (items, wording) => items.length === 0 ? null
    : countedSection(wording.examples(items.length), `<ul class="ef-examples-list">${listItems(items)}</ul>`)

const etymologySection = (items, wording) => items.length === 0 ? null
    : countedSection(wording.etymology(items.length), items.map((item) => `<p class="ef-etymology-item">${escapeHtml(item)}</p>`).join(''))

// The translations line, shared by the flat field and the Body's per-entry
// line: the popup's preview cap, the rest behind a "+N more…" expander —
// an inline <details> so it opens into the same line instead of breaking
// the joined list.
const translationsLine = (words, wording) => {
    const previewed = words.slice(0, TRANSLATIONS_PREVIEW).map(escapeHtml).join(', ')
    const rest = words.slice(TRANSLATIONS_PREVIEW)
    return rest.length === 0
        ? previewed
        : `${previewed}<details class="ef-more"><summary class="ef-more-summary">${wording.more(rest.length)}</summary>${rest.map(escapeHtml).join(', ')}</details>`
}

// The bracketed transcriptions line, the Body's per-entry counterpart of
// translationsLine: the popup's first reading, the rest behind a "+N
// more…" expander whose content leads with the " · " separator.
const transcriptionsLine = (values, wording) => {
    const rest = values.slice(TRANSCRIPTIONS_PREVIEW)
    const more = rest.length === 0
        ? ''
        : `<details class="ef-more"><summary class="ef-more-summary">${wording.more(rest.length)}</summary> · ${rest.map(escapeHtml).join(' · ')}</details>`
    return `[${escapeHtml(values[0])}]${more}`
}

// The popup's per-entry blocks for one flat field: only entries carrying
// content render (no dangling label over nothing), each wrapped with its
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

// The send-time toggles arrive as the sparse settings map (the resolved
// `fields` option): an absent key means on — the Popups tab's convention —
// and `false` means the user turned the section off. One reader for the six
// decision points (entryBody's five sections, buildNoteFields' Body master)
// so the convention is stated once and cannot drift apart between them.
const isOn = (fields, key) => fields?.[key] !== false

// One entry's Body section — the popup's dictionary pane, top to bottom:
// the "WORD — pos" label, the translations line (or its calm placeholder),
// the bracketed transcriptions (first, the rest behind the expander), the
// counted collapsed Definitions ("No definitions yet." when empty, exactly
// the popup), Examples and Etymologies (hidden whole when empty). The
// send-time section toggles drop their lines entire — placeholder included,
// exactly like the popup's section gates. Null when nothing renders, so a
// fully-suppressed entry leaves no empty block behind.
const entryBody = (entry, headword, wording, fields) => {
    const parts = []

    const label = entryLabel(entry, headword)
    if (label !== '') {
        parts.push(`<span class="ef-entry-label">${label}</span>`)
    }

    if (isOn(fields, 'translations')) {
        const words = (entry.translations ?? []).map((translation) => translation.word)
        parts.push(words.length === 0
            ? `<p class="ef-empty">${wording.noTranslations}</p>`
            : `<span class="ef-translations">${translationsLine(words, wording)}</span>`)
    }

    const transcriptions = (entry.transcriptions ?? []).map((item) => item.value)
    if (isOn(fields, 'transcription') && transcriptions.length > 0) {
        parts.push(`<span class="ef-transcriptions">${transcriptionsLine(transcriptions, wording)}</span>`)
    }

    if (isOn(fields, 'definitions')) {
        const items = entry.definitions ?? []
        parts.push(definitionsSection(items, wording) ?? `<p class="ef-empty">${wording.noDefinitions}</p>`)
    }

    if (isOn(fields, 'examples')) {
        const section = examplesSection(entry.examples ?? [], wording)
        if (section !== null) {
            parts.push(section)
        }
    }

    if (isOn(fields, 'etymology')) {
        const section = etymologySection(entry.etymologies ?? [], wording)
        if (section !== null) {
            parts.push(section)
        }
    }

    return parts.length === 0 ? null : parts.join('\n')
}

// The Body value: one hairline-separated section per entry, in the popup's
// order. Every entry gets its label (the popup renders sectionLabel for the
// headword's own single entry too); the separator between sections is CSS
// (.ef-entry + .ef-entry), so a single-entry word is one unlabeled-by-rules
// block — the popup's simple case.
const bodyValue = (entries, headword, wording, fields) => entries
    .map((entry) => {
        const section = entryBody(entry, headword, wording, fields)
        return section === null ? null : `<div class="ef-entry">${section}</div>`
    })
    .filter(Boolean)
    .join('\n')

// Build the complete 13-field note from a word payload (the GET
// /words/{word} shape) — exactly what the send-to-Anki paths put on the
// wire. Every field gets a value, toggles or not: hide-class semantics mean
// the template, never an omission, hides the scalars. `nativeLanguage` (a
// language code) narrows the flat Translations field to the user's native
// language, falling back to all translations when none match (or nothing
// was passed) — the Body copies the popup instead: per-entry translations
// as the payload carries them, native-first. `labels` carries the sender's
// UI-language summary/expander/form-of/placeholder strings (English
// defaults). `fields` is the resolved Anki settings map: the body section
// toggles (translations, transcription, definitions, examples, etymology)
// plus the `body` master toggle shape the Body value at SEND TIME — they
// affect newly sent cards only; an absent key means on.
export function buildNoteFields(data, {familiarity = null, tags = [], nativeLanguage = null, labels = {}, fields = {}} = {}) {
    const entries = data?.entries ?? []
    const word = data?.word ?? ''
    const wording = {...DEFAULT_LABELS, ...labels}

    // 2+ dictionary entries get the popup's per-entry section labels — in
    // the flat fields, where a label disambiguates stacked entries. The Body
    // labels every section, like the popup.
    const labeled = entries.length >= 2

    const translations = entries.flatMap((entry) => entry.translations ?? [])
    const native = nativeLanguage
        ? translations.filter((translation) => translation.language_code === nativeLanguage)
        : []
    const words = (native.length ? native : translations).map((translation) => translation.word)

    return {
        Word: escapeHtml(word),
        Transcription: distinct(entries.flatMap((entry) => (entry.transcriptions ?? []).map((t) => t.value)))
            .map(escapeHtml).join(' · '),
        PartOfSpeech: inlineValue(distinct(entries.map((entry) => entry.word_class))),
        Frequency: data?.frequency == null ? '' : escapeHtml(data.frequency),
        Definitions: entrySections(entries, word, labeled, (entry) => definitionsSection(entry.definitions ?? [], wording)),
        Translations: translationsLine(words, wording),
        Examples: entrySections(entries, word, labeled, (entry) => examplesSection(entry.examples ?? [], wording)),
        Etymology: entrySections(entries, word, labeled, (entry) => etymologySection(entry.etymologies ?? [], wording)),
        WordFamily: formOfValue(data?.form_of ?? [], wording.formOf),
        Tags: inlineValue(tags),
        Familiarity: familiarity == null ? '' : escapeHtml(familiarity),
        Language: escapeHtml(data?.language_code ?? ''),
        Body: isOn(fields, 'body') ? bodyValue(entries, word, wording, fields) : '',
    }
}

// Substitute note values for Anki's field syntax — the {{Field}} placeholder
// and the conditional block {{#Field}}…{{/Field}} — so the tab's preview
// renders exactly what Anki renders at review time. Conditional blocks
// resolve one nesting level per pass: a block whose field carries a value is
// replaced by its content, an empty field drops the whole block (a
// literal-prefixed meta line disappears entire, and an empty Body drops the
// dictionary slot), and an unknown field name is left untouched like an
// unknown placeholder. Values are substituted only after the conditionals
// have resolved, so a value carrying {{…}} text is never re-parsed as
// template syntax — the same order Anki uses.
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
