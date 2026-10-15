// The profile Anki tab's per-language editing state (anki-popup-refresh):
// pure helpers over the resolved ankiSettings prop (the shape
// App\Support\AnkiSettings::for shares) plus the enabled-language list the
// profile page passes in. The tab autosaves the WHOLE anki section on every
// change — connection + legacy base + the complete per-language map —
// because the endpoint replaces the section wholesale: a languages-only
// payload would wipe the legacy base. The resolved `default` entry (words
// with unknown/missing language_code) is computed server-side, never
// persistable (the endpoint whitelists enabled codes only) and never
// selectable, so it never enters the state — the editable map carries the
// enabled codes alone, and with no selection (no enabled languages) the tab
// edits the legacy global base, the pre-per-language behavior.

import {ANKI_DEFAULT_DECK, ANKI_DEFAULT_PORT, ANKI_DEFAULT_URL} from './ankiConnect'
import {ANKI_FIELDS, noteTypeForLanguage} from './ankiTemplate'

// The full thirteen-key field map over any stack of sparse layers, absent
// key meaning on (the Popups tab's convention). Layers go base → entry, so
// a saved per-language toggle wins over the legacy global one.
const resolvedFields = (...layers) => {
    const merged = Object.assign({}, ...layers)
    return Object.fromEntries(ANKI_FIELDS.map(({key}) => [key, merged[key] ?? true]))
}

// The whole persisted section the tab keeps in state and autosaves: the
// global connection, the legacy global base (deck + field toggles, still
// the fallback for words with an unknown language) and one fully-resolved
// deck+fields entry per enabled code.
export function ankiTabState(saved, codes) {
    return {
        url: saved?.url ?? ANKI_DEFAULT_URL,
        port: saved?.port ?? ANKI_DEFAULT_PORT,
        deck: saved?.deck ?? ANKI_DEFAULT_DECK,
        fields: resolvedFields({}, saved?.fields),
        languages: Object.fromEntries(codes.map((code) => [code, {
            deck: saved?.languages?.[code]?.deck ?? saved?.deck ?? ANKI_DEFAULT_DECK,
            fields: resolvedFields(saved?.fields, saved?.languages?.[code]?.fields),
        }])),
    }
}

// The entry the tab's deck input, toggles, preview and write actions act
// on: the selected language's, or the legacy global base without a
// selection.
export function ankiTabEntry(state, code) {
    return code && state.languages[code]
        ? state.languages[code]
        : {deck: state.deck, fields: state.fields}
}

// Deck edit on the selected language — or on the legacy base when nothing
// is selected. The state comes back whole, so the autosave never drops the
// other languages or the connection.
export function ankiTabSetDeck(state, code, value) {
    const deck = value.trim()
    if (!code) {
        return {...state, deck}
    }
    const entry = ankiTabEntry(state, code)
    return {...state, languages: {...state.languages, [code]: {...entry, deck}}}
}

// Field-toggle flip, scoped exactly like ankiTabSetDeck.
export function ankiTabToggleField(state, code, key) {
    const entry = ankiTabEntry(state, code)
    const fields = {...entry.fields, [key]: !entry.fields[key]}
    if (!code) {
        return {...state, fields}
    }
    return {...state, languages: {...state.languages, [code]: {...entry, fields}}}
}

// The selector's initial language: the user's native language when it is
// among the enabled ones, else the first enabled one; no selection without
// enabled languages.
export function ankiTabDefaultCode(codes, nativeLanguage) {
    return codes.includes(nativeLanguage) ? nativeLanguage : codes[0] ?? null
}

// The sync-layer config for the selected language: its deck and resolved
// toggles, plus its per-language note type ("ExtApp Vocabulary ⋅ <code>",
// lib/ankiTemplate.js's noteTypeForLanguage). The noteType key is omitted
// entirely without a selection so lib/ankiSync.js keeps the unsuffixed
// base type — the pre-per-language behavior.
export function ankiTabSyncConfig(state, code) {
    const entry = ankiTabEntry(state, code)
    return {
        url: state.url,
        port: state.port,
        deck: entry.deck,
        fields: entry.fields,
        ...(code ? {noteType: noteTypeForLanguage(code)} : {}),
    }
}

// The sample-card payload stamped with the selected language: the note's
// Language field AND the duplicate search (deck + Word + Language) both
// derive from the payload's language_code, so one override here puts the
// whole send — and the preview's footer — on the selected language. The
// fixture stays untouched without a selection.
export function ankiTabSampleData(sample, code) {
    return code ? {...sample, language_code: code} : sample
}
