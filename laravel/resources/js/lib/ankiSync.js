// The template-push + note-send layer on top of lib/ankiConnect.js: makes the
// target deck, the app-owned "ExtApp Vocabulary" note type and the generated
// card template exist in Anki, then adds notes with a find-before-add
// duplicate check. Used by the Anki tab's "Create / update template in Anki"
// and "Send sample card" actions and, from the next ticket on, by the word
// popup's per-word send.
//
// Spec decisions encoded here: values always carry every field (hide-class
// semantics — the pushed template, not an omission, hides content); a
// same-name note type whose fields don't match the app-owned thirteen is
// never silently rewritten — the caller surfaces a warning instead;
// duplicates are skipped, foreign notes are never updated.

import {
    ANKI_CARD_NAME,
    ANKI_FIELDS,
    ANKI_NOTE_TYPE,
    generateCardTemplate,
} from './ankiTemplate'
import {
    AnkiConnectError,
    addNote,
    ankiConnectEndpoint,
    createDeck,
    createModel,
    deckNames,
    findNotes,
    modelFieldNames,
    modelNames,
    modelTemplates,
    notesInfo,
    updateModelStyling,
    updateModelTemplates,
} from './ankiConnect'

// Anki already holds a note type named "ExtApp Vocabulary" that isn't the one
// this app manages: `kind` is 'fields' when the thirteen field names differ
// (including the schema-v2 upgrade case: AnkiConnect cannot add a field to an
// existing note type, so a pre-v2 twelve-field install mismatches and the
// caller's UI teaches the delete-note-type-and-resync fix — pushing our
// Front/Back onto a foreign-shaped model could break its cards) or 'card'
// when the field names match but no "Recognition" card exists (AnkiConnect's
// updateModelTemplates silently skips unknown card names, so the push would
// be a silent no-op). `expected`/`found` are name lists for the warning UI.
export class AnkiNoteTypeMismatchError extends Error {
    constructor(kind, expected, found) {
        super(`note type "${ANKI_NOTE_TYPE}" mismatch: ${kind}`)
        this.name = 'AnkiNoteTypeMismatchError'
        this.kind = kind
        this.expected = expected
        this.found = found
    }
}

// Field-set comparison is order-insensitive on purpose: the field NAMES the
// note type carries are what makes it ours (notes are addressed by name, and
// the template only references names); a user-reordered model is still safe
// to update, a renamed/extended one is not.
const sameFieldSet = (a, b) => (
    a.length === b.length && [...a].sort().join('\n') === [...b].sort().join('\n')
)

const fieldNames = () => ANKI_FIELDS.map(({name}) => name)

// Deck create-if-missing; note type create-if-missing, otherwise verify its
// field set and push the generated Front/Back + CSS. Every real pass re-pushes
// the template — that is what re-applies the toggled-off hide classes to all
// existing cards.
async function ensureOnce(config) {
    const {deck, fields} = config
    const expected = fieldNames()

    if (!(await deckNames(config)).includes(deck)) {
        await createDeck(config, deck)
    }

    if (!(await modelNames(config)).includes(ANKI_NOTE_TYPE)) {
        const template = generateCardTemplate(fields)
        await createModel(config, {
            modelName: ANKI_NOTE_TYPE,
            inOrderFields: expected,
            css: template.css,
            cardTemplates: [{Name: ANKI_CARD_NAME, Front: template.front, Back: template.back}],
        })
        return
    }

    const found = await modelFieldNames(config, ANKI_NOTE_TYPE)
    if (!sameFieldSet(expected, found)) {
        throw new AnkiNoteTypeMismatchError('fields', expected, found)
    }

    const templates = await modelTemplates(config, ANKI_NOTE_TYPE)
    if (!Object.prototype.hasOwnProperty.call(templates, ANKI_CARD_NAME)) {
        throw new AnkiNoteTypeMismatchError('card', [ANKI_CARD_NAME], Object.keys(templates))
    }

    const template = generateCardTemplate(fields)
    await updateModelTemplates(config, ANKI_NOTE_TYPE, ANKI_CARD_NAME, {
        front: template.front,
        back: template.back,
    })
    await updateModelStyling(config, ANKI_NOTE_TYPE, template.css)
}

// The ensure is memoized once per page session per endpoint+deck: after the
// first success every send skips straight to the duplicate check. The
// explicit "Create / update template in Anki" button passes force: true —
// that fresh pass is what re-applies toggled-off hide classes. Changing the
// endpoint or deck misses the memo naturally; toggling fields does not,
// which is safe because note values always carry every field and the
// template only controls hiding.
const ensured = new Set()

const ensureKey = (config) => `${ankiConnectEndpoint(config)}|${config.deck}`

export async function ensureAnkiTarget(config, {force = false} = {}) {
    const key = ensureKey(config)
    if (!force && ensured.has(key)) {
        return
    }
    await ensureOnce(config)
    ensured.add(key)
}

// Quotes would terminate the search term early; nothing else needs escaping
// for Anki's query syntax.
const searchEscape = (value) => String(value).replaceAll('"', '')

// Add a note to the configured deck with a find-before-add duplicate check
// scoped to deck + Word + Language. `word` is REQUIRED and RAW — the caller
// has it before buildNoteFields HTML-escapes the field values, and it only
// serves the search. There is no fallback to noteFields.Word on purpose: an
// escaped (or missing) word would silently miss existing duplicates, so a
// call without it throws instead. Returns {added: true, noteId} or, on a
// duplicate, {added: false, noteId} — no existing note is ever modified, and
// addNote's allowDuplicate stays false as a last-resort net against races
// between the search and the add.
export async function sendAnkiNote(config, noteFields, {tags = [], word} = {}) {
    if (word == null || word === '') {
        throw new AnkiConnectError(
            'anki',
            'sendAnkiNote needs the raw word for the duplicate search (noteFields.Word is HTML-escaped)',
        )
    }

    await ensureAnkiTarget(config)

    const query = [
        `deck:"${searchEscape(config.deck)}"`,
        `Word:"${searchEscape(word)}"`,
        noteFields.Language ? `Language:"${searchEscape(noteFields.Language)}"` : null,
    ].filter(Boolean).join(' ')

    const existing = await findNotes(config, query)
    if (existing.length > 0) {
        // Prefer the id of a note of our own type for reporting; any other
        // model matching deck+word+language still counts as "already in
        // Anki" — same word, same deck, same language needs no second card.
        const infos = await notesInfo(config, existing)
        const noteId = infos.find((info) => info?.modelName === ANKI_NOTE_TYPE)?.noteId ?? existing[0]
        return {added: false, noteId}
    }

    const noteId = await addNote(config, {
        deckName: config.deck,
        modelName: ANKI_NOTE_TYPE,
        fields: noteFields,
        tags,
        options: {allowDuplicate: false},
    })

    return {added: true, noteId}
}
