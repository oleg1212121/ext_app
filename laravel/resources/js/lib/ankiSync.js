// The template-push + note-send layer on top of lib/ankiConnect.js: makes the
// target deck, the app-owned note type (the unsuffixed "ExtApp Vocabulary", or
// the per-language "ExtApp Vocabulary ⋅ <code>" via config.noteType — naming
// from lib/ankiTemplate.js's noteTypeForLanguage) and the generated card
// template exist in Anki, then either adds notes with a find-before-add
// duplicate check or, when the found duplicate is one of our own notes,
// updates it in place. Used by the Anki tab's "Create / update template in
// Anki" and "Send sample card" actions, by the word popup's per-word send,
// and — from anki-popup-refresh on — by the popup's on-open detection
// (findExistingNote).
//
// Spec decisions encoded here: values always carry every field (hide-class
// semantics — the pushed template, not an omission, hides content); a
// same-name note type whose fields don't match the app-owned thirteen is
// never silently rewritten — the caller surfaces a warning instead;
// config without a noteType (no language) keeps the base note type and the
// configured deck. ADR 0077 decision 5 said "sends only add — nothing is
// ever modified"; anki-popup-refresh REVERSES that for our own notes only
// (the ADR amendment itself lands in ticket 06): a found duplicate of OUR
// note types gets all thirteen field values rebuilt and its tag set
// replaced, addressing the note by id alone so its deck and review
// scheduling cannot be touched. Foreign-model notes are still never
// modified — they only mark the word as already in Anki.

import {
    ANKI_CARD_NAME,
    ANKI_FIELDS,
    ANKI_NOTE_TYPE,
    ANKI_NOTE_TYPE_SEPARATOR,
    generateCardTemplate,
} from './ankiTemplate'
import {
    AnkiConnectError,
    addNote,
    addTags,
    ankiConnectEndpoint,
    createDeck,
    createModel,
    deckNames,
    findNotes,
    getNoteTags,
    modelFieldNames,
    modelNames,
    modelTemplates,
    notesInfo,
    removeTags,
    updateModelStyling,
    updateModelTemplates,
    updateNote,
    updateNoteFields,
} from './ankiConnect'

// Anki already holds a note type named like ours that isn't the one this app
// manages: `kind` is 'fields' when the thirteen field names differ (including
// the schema-v2 upgrade case: AnkiConnect cannot add a field to an existing
// note type, so a pre-v2 twelve-field install mismatches and the caller's UI
// teaches the delete-note-type-and-resync fix — pushing our Front/Back onto a
// foreign-shaped model could break its cards) or 'card' when the field names
// match but no "Recognition" card exists (AnkiConnect's updateModelTemplates
// silently skips unknown card names, so the push would be a silent no-op).
// `modelName` names the model actually inspected — the per-language note
// type when the caller passed one. `expected`/`found` are name lists for the
// warning UI.
export class AnkiNoteTypeMismatchError extends Error {
    constructor(modelName, kind, expected, found) {
        super(`note type "${modelName}" mismatch: ${kind}`)
        this.name = 'AnkiNoteTypeMismatchError'
        this.modelName = modelName
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

// The note type this config targets: the per-language name when the caller
// put one on the config (noteTypeForLanguage's output), the unsuffixed base
// otherwise — callers that pass no language keep today's behavior exactly.
const noteTypeFor = (config) => config.noteType || ANKI_NOTE_TYPE

// Ownership test for a note found in the deck (the update branch in
// sendAnkiNote): a note is OURS when its model is the configured
// (per-language) type, the unsuffixed legacy base, or ANY name carrying the
// app prefix (base + separator) — a language disabled since the note was
// sent resolves its word to the `default` entry, so the send targets the
// base type while the note still wears its "⋅ <code>" name. The prefix ends
// at the separator, so a user model named "ExtApp Vocabulary (copy)" stays
// foreign. Foreign models are never modified — they only mark the word as
// already in Anki.
const isOwnNoteType = (modelName, config) =>
    modelName === noteTypeFor(config)
    || modelName === ANKI_NOTE_TYPE
    || String(modelName ?? '').startsWith(`${ANKI_NOTE_TYPE}${ANKI_NOTE_TYPE_SEPARATOR}`)

// Deck create-if-missing; note type create-if-missing, otherwise verify its
// field set and push the generated Front/Back + CSS. Every real pass re-pushes
// the template — that is what re-applies the toggled-off hide classes to all
// existing cards.
async function ensureOnce(config) {
    const {deck, fields} = config
    const modelName = noteTypeFor(config)
    const expected = fieldNames()

    if (!(await deckNames(config)).includes(deck)) {
        await createDeck(config, deck)
    }

    if (!(await modelNames(config)).includes(modelName)) {
        const template = generateCardTemplate(fields)
        await createModel(config, {
            modelName,
            inOrderFields: expected,
            css: template.css,
            cardTemplates: [{Name: ANKI_CARD_NAME, Front: template.front, Back: template.back}],
        })
        return
    }

    const found = await modelFieldNames(config, modelName)
    if (!sameFieldSet(expected, found)) {
        throw new AnkiNoteTypeMismatchError(modelName, 'fields', expected, found)
    }

    const templates = await modelTemplates(config, modelName)
    if (!Object.prototype.hasOwnProperty.call(templates, ANKI_CARD_NAME)) {
        throw new AnkiNoteTypeMismatchError(modelName, 'card', [ANKI_CARD_NAME], Object.keys(templates))
    }

    const template = generateCardTemplate(fields)
    await updateModelTemplates(config, modelName, ANKI_CARD_NAME, {
        front: template.front,
        back: template.back,
    })
    await updateModelStyling(config, modelName, template.css)
}

// The ensure is memoized once per page session per endpoint+deck+note type:
// after the first success every send skips straight to the duplicate check.
// The explicit "Create / update template in Anki" button passes force: true —
// that fresh pass is what re-applies toggled-off hide classes. Changing the
// endpoint, deck, or note type misses the memo naturally; toggling fields
// does not, which is safe because note values always carry every field and
// the template only controls hiding.
const ensured = new Set()

const ensureKey = (config) => `${ankiConnectEndpoint(config)}|${config.deck}|${noteTypeFor(config)}`

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

// The duplicate search both the send and the on-open detection run, scoped
// to deck + Word + Language (note-type-agnostic: a word saved under an
// earlier config — the legacy base type, another language's type — is still
// found, and per-language decks naturally narrow the search).
const duplicateQuery = (config, word, language) => [
    `deck:"${searchEscape(config.deck)}"`,
    `Word:"${searchEscape(word)}"`,
    language ? `Language:"${searchEscape(language)}"` : null,
].filter(Boolean).join(' ')

// Update one of OUR notes in place: all thirteen field values rebuilt and
// overwritten, the tag set REPLACED. The payload addresses the note by id
// alone — deck and review scheduling are not part of the wire, so they
// cannot be touched (spec: full value refresh, everything else untouched).
// ADR 0077 decision 5 said sends never modify anything; this is the
// anki-popup-refresh reversal for our own notes (the ADR amendment is
// ticket 06) — foreign notes never reach this helper.
//
// Primary path: AnkiConnect's combined updateNote ({note: {id, fields,
// tags}}, tags replacing the set — verified against the plugin source,
// present since at least 24.7, July 2024; no plural "updateNotes" action
// exists in any release, unlike the ticket text first assumed). Fallback for
// older builds: updateNoteFields + an explicit tag replacement
// (getNoteTags → removeTags → addTags — addTags alone is additive and
// could not replace). Only an "unsupported action"-style error falls back;
// anything else is a real failure and propagates.
const updateAnkiNote = async (config, noteId, {fields, tags}) => {
    try {
        await updateNote(config, {id: noteId, fields, tags})
        return
    } catch (error) {
        if (!(error instanceof AnkiConnectError) || !/unsupported action/i.test(error.message)) {
            throw error
        }
    }

    await updateNoteFields(config, {id: noteId, fields})
    const oldTags = await getNoteTags(config, noteId)
    if (oldTags.length > 0) {
        await removeTags(config, [noteId], oldTags)
    }
    if (tags.length > 0) {
        await addTags(config, [noteId], tags)
    }
}

// On-open detection (anki-popup-refresh): is this word already in the
// configured deck? Memoized per page session per endpoint + deck + word +
// language; never runs the ensure (detection must not create decks or note
// types — Anki may not even be running) and resolves null on ANY failure
// (Anki off, CORS, Anki-side error) so callers fall back to the add-form
// icon. Failures are not memoized: a later open retries once Anki is back.
// The send results seed this memo (sendAnkiNote), so a popup reopened after
// a send shows the saved icon without re-querying. Returns the first
// matching note id regardless of model — existence is the question here;
// ownership (our note vs a foreign one) is re-resolved by the send path.
const detected = new Map()

const detectionKey = (config, {word, language}) =>
    `${ankiConnectEndpoint(config)}|${config.deck}|${word}|${language ?? ''}`

export async function findExistingNote(config, {word, language} = {}) {
    if (word == null || word === '') {
        return null
    }

    const key = detectionKey(config, {word, language})
    if (detected.has(key)) {
        return detected.get(key)
    }

    try {
        const existing = await findNotes(config, duplicateQuery(config, word, language))
        const noteId = existing.length > 0 ? existing[0] : null
        detected.set(key, noteId)
        return noteId
    } catch {
        return null
    }
}

const seedDetection = (config, {word, language}, noteId) => {
    detected.set(detectionKey(config, {word, language}), noteId)
}

// Add a note to the configured deck with a find-before-add duplicate check
// scoped to deck + Word + Language. `word` is REQUIRED and RAW — the caller
// has it before buildNoteFields HTML-escapes the field values, and it only
// serves the search. There is no fallback to noteFields.Word on purpose: an
// escaped (or missing) word would silently miss existing duplicates, so a
// call without it throws instead.
//
// Outcomes: {added: true, updated: false, noteId} for a fresh note,
// {added: false, updated: true, noteId} when one of OUR notes was found and
// updated in place (see updateAnkiNote), and {added: false, updated: false,
// noteId} when only a FOREIGN-model note matches — same word, same deck,
// same language needs no second card, and a note we do not own is never
// rewritten. addNote's allowDuplicate stays false as a last-resort net
// against races between the search and the add.
export async function sendAnkiNote(config, noteFields, {tags = [], word} = {}) {
    if (word == null || word === '') {
        throw new AnkiConnectError(
            'anki',
            'sendAnkiNote needs the raw word for the duplicate search (noteFields.Word is HTML-escaped)',
        )
    }

    await ensureAnkiTarget(config)

    const language = noteFields.Language
    const existing = await findNotes(config, duplicateQuery(config, word, language))
    if (existing.length > 0) {
        const infos = await notesInfo(config, existing)
        // A note of our own types — the configured (per-language) model, the
        // unsuffixed legacy one, or any name with the app prefix (see
        // isOwnNoteType) — gets the in-place refresh. Any other model
        // matching deck+word+language only marks the word as taken.
        const own = infos.find((info) => isOwnNoteType(info?.modelName, config))
        if (own) {
            await updateAnkiNote(config, own.noteId, {fields: noteFields, tags})
            seedDetection(config, {word, language}, own.noteId)
            return {added: false, updated: true, noteId: own.noteId}
        }
        seedDetection(config, {word, language}, existing[0])
        return {added: false, updated: false, noteId: existing[0]}
    }

    const noteId = await addNote(config, {
        deckName: config.deck,
        modelName: noteTypeFor(config),
        fields: noteFields,
        tags,
        options: {allowDuplicate: false},
    })
    seedDetection(config, {word, language}, noteId)

    return {added: true, updated: false, noteId}
}
