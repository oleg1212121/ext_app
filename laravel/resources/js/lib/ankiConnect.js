// Browser-side AnkiConnect client (the profile's Anki tab). Only the browser
// ever talks to AnkiConnect — dev runs in Docker/WSL2 and prod is remote, so
// a PHP-side call could never reach a user's desktop Anki. Thin action
// wrappers over invoke only: the orchestration (ensure deck/model/template,
// duplicate checks) lives in lib/ankiSync.js.
//
// Wire format: POST {action, version: 6, params?} to {url}:{port}; the
// answer is {result, error}. No Content-Type header is set: a string body
// defaults to text/plain, a CORS-simple request, so the browser never fires
// an OPTIONS preflight — AnkiConnect parses the raw body as JSON either way,
// and when Anki is down a failed call costs one refused request instead of
// two (preflight + POST both logged). AnkiConnect reports failures INSIDE
// HTTP 200 (a non-null `error` string), while CORS blocks and Anki being
// down reject the fetch itself — both are normalized into AnkiConnectError
// so the tab can render one failure panel with exact fix instructions.
//
// Parameter shapes verified against the AnkiConnect plugin source
// (git.sr.ht/~foosoft/anki-connect, plugin/__init__.py, 2026-10):
// createModel takes {modelName, inOrderFields, cardTemplates: [{Name?, Front,
// Back}], css, isCloze}; updateModelTemplates and updateModelStyling take a
// model OBJECT — {name, templates: {[cardName]: {Front?, Back?}}} and
// {name, css} — where unknown card names are silently skipped (ankiSync.js
// guards against that). The note-update actions (anki-popup-refresh, re-checked
// at releases 24.7.25.0 and 25.11.9.0): updateNote takes a single note OBJECT
// {note: {id, fields?, tags?}} applying both in one call with supplied tags
// REPLACING the set (present since at least 24.7, July 2024; no plural
// "updateNotes" action exists in any release); updateNoteFields takes
// {note: {id, fields}} and silently ignores unknown field names (a
// foreign-shaped model would update only the matching fields — ankiSync.js
// only sends it to our own models); getNoteTags takes {note: <single id>}
// and answers a tag-string array; addTags/removeTags take a note-id array
// plus a SPACE-JOINED tag string — addTags is additive, so tag replacement
// without updateNote means removeTags-then-addTags.

export const ANKI_DEFAULT_URL = 'http://127.0.0.1'
export const ANKI_DEFAULT_PORT = 8765
export const ANKI_DEFAULT_DECK = 'ExtApp'

export class AnkiConnectError extends Error {
    constructor(kind, message) {
        super(message)
        this.name = 'AnkiConnectError'
        // 'network' = fetch rejection (CORS/refused), HTTP-level or non-JSON
        // answers; 'anki' = AnkiConnect's own error string inside HTTP 200.
        this.kind = kind
    }
}

export function ankiConnectEndpoint({url = ANKI_DEFAULT_URL, port = ANKI_DEFAULT_PORT} = {}) {
    const base = String(url ?? ANKI_DEFAULT_URL).trim().replace(/\/+$/, '')
    const portNumber = Number(port) || ANKI_DEFAULT_PORT
    return `${base}:${portNumber}`
}

export async function invoke(action, params, config) {
    let response
    try {
        response = await fetch(ankiConnectEndpoint(config), {
            method: 'POST',
            // Deliberately no Content-Type: the string body rides as
            // text/plain (CORS-safelisted), keeping every call preflight-free.
            body: JSON.stringify({action, version: 6, ...(params ? {params} : {})}),
        })
    } catch {
        throw new AnkiConnectError(
            'network',
            `Could not reach AnkiConnect at ${ankiConnectEndpoint(config)}`,
        )
    }

    if (!response.ok) {
        throw new AnkiConnectError('network', `AnkiConnect answered HTTP ${response.status}`)
    }

    const body = await response.json().catch(() => null)
    if (body === null || typeof body !== 'object') {
        throw new AnkiConnectError('network', 'AnkiConnect answer was not JSON')
    }
    if (body.error) {
        throw new AnkiConnectError('anki', String(body.error))
    }
    return body.result
}

export function ankiConnectVersion(config) {
    return invoke('version', null, config)
}

// The popup's on-open reachability probe (WordPopup.jsx): a FAILURE is
// memoized per endpoint for 30s — rejections inside the window are answered
// from the memo without touching the network, so browsing with Anki off
// costs at most one failed request per 30s instead of per popup open. Any
// resolve (Anki came up) clears the memo and the next open probes fresh.
// The profile tab's manual Test button keeps the unmemoized
// ankiConnectVersion — a settings change must always probe for real.
const PROBE_FAILURE_RETRY_AFTER_MS = 30_000
const failedProbes = new Map()

export function probeReachable(config) {
    const endpoint = ankiConnectEndpoint(config)
    const memo = failedProbes.get(endpoint)
    if (memo != null && Date.now() - memo.at < PROBE_FAILURE_RETRY_AFTER_MS) {
        return Promise.reject(memo.error)
    }
    return ankiConnectVersion(config).then(
        (version) => {
            failedProbes.delete(endpoint)
            return version
        },
        (error) => {
            failedProbes.set(endpoint, {at: Date.now(), error})
            throw error
        },
    )
}

export function requestPermission(config) {
    return invoke('requestPermission', null, config)
}

export function deckNames(config) {
    return invoke('deckNames', null, config)
}

export function createDeck(config, deck) {
    return invoke('createDeck', {deck}, config)
}

export function modelNames(config) {
    return invoke('modelNames', null, config)
}

export function modelFieldNames(config, modelName) {
    return invoke('modelFieldNames', {modelName}, config)
}

export function modelTemplates(config, modelName) {
    return invoke('modelTemplates', {modelName}, config)
}

export function createModel(config, {modelName, inOrderFields, cardTemplates, css}) {
    return invoke('createModel', {modelName, inOrderFields, cardTemplates, css}, config)
}

export function updateModelTemplates(config, modelName, cardName, {front, back}) {
    return invoke('updateModelTemplates', {
        model: {name: modelName, templates: {[cardName]: {Front: front, Back: back}}},
    }, config)
}

export function updateModelStyling(config, modelName, css) {
    return invoke('updateModelStyling', {model: {name: modelName, css}}, config)
}

export function findNotes(config, query) {
    return invoke('findNotes', {query}, config)
}

export function notesInfo(config, notes) {
    return invoke('notesInfo', {notes}, config)
}

export function addNote(config, note) {
    return invoke('addNote', {note}, config)
}

// The note-update group (anki-popup-refresh): updateNote applies fields and
// tags in one call, supplied tags replacing the set. updateNoteFields is the
// fields-only half of the fallback for AnkiConnect builds predating it; the
// tag half is a getNoteTags -> removeTags -> addTags replacement (addTags
// alone cannot replace). Tag arrays are sent space-joined — AnkiConnect's
// tag string convention.
export function updateNote(config, note) {
    return invoke('updateNote', {note}, config)
}

export function updateNoteFields(config, note) {
    return invoke('updateNoteFields', {note}, config)
}

export function getNoteTags(config, noteId) {
    return invoke('getNoteTags', {note: noteId}, config)
}

export function addTags(config, notes, tags) {
    return invoke('addTags', {notes, tags: tags.join(' ')}, config)
}

export function removeTags(config, notes, tags) {
    return invoke('removeTags', {notes, tags: tags.join(' ')}, config)
}
