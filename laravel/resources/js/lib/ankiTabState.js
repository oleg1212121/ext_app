// The profile Anki tab's per-language editing state (anki-popup-refresh):
// pure helpers over the resolved ankiSettings prop (the shape
// App\Support\AnkiSettings::for shares) plus the enabled-language list the
// profile page passes in. The tab autosaves the WHOLE anki section on every
// change — connection + the complete per-language map — because the endpoint
// replaces the section wholesale. The legacy global base (top-level
// deck/fields) is NOT part of the state: it is read-only since the review
// fixes (the endpoint strips incoming deck/fields and preserves the saved
// pair server-side), so the state carries only what the endpoint accepts and
// the base survives every save untouched. The base is still READ as a
// resolution layer: per-language entries resolve over it exactly as before.
// The resolved `default` entry (words with unknown/missing language_code) is
// computed server-side, never persistable (the endpoint whitelists enabled
// codes only) and never selectable, so it never enters the state — the
// editable map carries the enabled codes alone. Without a selection (no
// enabled languages) nothing is writable: ankiTabDefaultEntry serves the
// resolved `default` entry as a READ-ONLY view for the preview and the
// sync/sample targets, and the setters are no-ops.

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
// global connection and one fully-resolved deck+fields entry per enabled
// code — no top-level deck/fields (the read-only legacy base never rides
// along; the endpoint preserves the saved one).
export function ankiTabState(saved, codes) {
    return {
        url: saved?.url ?? ANKI_DEFAULT_URL,
        port: saved?.port ?? ANKI_DEFAULT_PORT,
        languages: Object.fromEntries(codes.map((code) => [code, {
            deck: saved?.languages?.[code]?.deck ?? saved?.deck ?? ANKI_DEFAULT_DECK,
            fields: resolvedFields(saved?.fields, saved?.languages?.[code]?.fields),
        }])),
    }
}

// The entry the tab's deck input, toggles, preview and write actions act
// on: the selected language's — or null without a selection, when no
// language is enabled and nothing is editable (the legacy global base is
// read-only; ankiTabDefaultEntry serves its read-only view instead).
export function ankiTabEntry(state, code) {
    return (code && state.languages[code]) || null
}

// The no-selection view of the legacy global base: the resolved `default`
// entry (computed server-side in AnkiSettings::for), falling back to the
// sparse saved base and then the all-on defaults. Never persisted — this is
// the preview/sync target and the disabled controls' display values only.
export function ankiTabDefaultEntry(saved) {
    return saved?.languages?.default ?? {
        deck: saved?.deck ?? ANKI_DEFAULT_DECK,
        fields: resolvedFields({}, saved?.fields),
    }
}

// Deck edit on the selected language. The state comes back whole, so the
// autosave never drops the other languages or the connection; without a
// selection the state is returned untouched (the base is not writable).
export function ankiTabSetDeck(state, code, value) {
    const entry = ankiTabEntry(state, code)
    if (!entry) {
        return state
    }
    const deck = value.trim()
    return {...state, languages: {...state.languages, [code]: {...entry, deck}}}
}

// Field-toggle flip, scoped exactly like ankiTabSetDeck — a no-op without a
// selection.
export function ankiTabToggleField(state, code, key) {
    const entry = ankiTabEntry(state, code)
    if (!entry) {
        return state
    }
    const fields = {...entry.fields, [key]: !entry.fields[key]}
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
// lib/ankiTemplate.js's noteTypeForLanguage). `baseEntry` (the tab passes
// ankiTabDefaultEntry's read-only view) stands in without a selection, and
// the noteType key is omitted entirely then, so lib/ankiSync.js keeps the
// unsuffixed base type — the pre-per-language behavior. There is no config
// without either.
export function ankiTabSyncConfig(state, code, baseEntry = null) {
    const entry = ankiTabEntry(state, code) ?? baseEntry
    if (!entry) {
        throw new Error('ankiTabSyncConfig needs a selected language or the read-only base entry')
    }
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
