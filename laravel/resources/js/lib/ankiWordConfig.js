// The word popup's word-level config resolution (anki-popup-refresh): the
// sync-layer config for ONE word payload, resolved from the shared
// ankiSettings prop (the shape App\Support\AnkiSettings::for shares) and the
// payload's language_code. The word's language entry
// (languages[code] ?? languages[ANKI_DEFAULT_LANGUAGE_KEY]) carries deck +
// field toggles — the server resolved each entry over the legacy global base
// already — while the connection (url/port) stays global and the note type
// follows the code (lib/ankiTemplate.js's noteTypeForLanguage, the JS twin
// of App\Support\AnkiSettings::noteTypeForLanguage; a missing/unknown code
// yields the unsuffixed base type, matching the `default` entry). The legacy
// top-level deck/fields stay the last resort for a prop without a languages
// map. Both popup callers — on-open duplicate detection and the send — go
// through this one helper, so they can never target different decks for the
// same word.

import {ANKI_DEFAULT_LANGUAGE_KEY, noteTypeForLanguage} from './ankiTemplate'

export function ankiWordSyncConfig(settings, code) {
    const entry = settings?.languages?.[code]
    const resolved = entry ?? settings?.languages?.[ANKI_DEFAULT_LANGUAGE_KEY] ?? {}

    return {
        url: settings?.url,
        port: settings?.port,
        deck: resolved.deck ?? settings?.deck,
        fields: resolved.fields ?? settings?.fields,
        // The note type follows the resolved entry: a code the settings
        // actually carry gets its per-language type, one that fell through
        // to `default` (missing or not an enabled language) keeps the base —
        // a send must never mint a "⋅ <code>" type the user never enabled.
        noteType: noteTypeForLanguage(entry ? code : null),
    }
}
