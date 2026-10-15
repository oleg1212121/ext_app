import {describe, expect, it} from 'vitest';
import {ANKI_NOTE_TYPE} from './ankiTemplate';
import {ankiWordSyncConfig} from './ankiWordConfig';

// The word popup's word-level counterpart of the Anki tab's
// ankiTabSyncConfig (lib/ankiTabState.js): the sync-layer config for ONE
// word payload, resolved from the shared ankiSettings prop (the shape
// App\Support\AnkiSettings::for shares) and the payload's language_code.
// The word's language entry (languages[code] ?? languages.default) carries
// deck + field toggles; the connection stays global; the note type follows
// the code (noteTypeForLanguage — the JS twin of
// App\Support\AnkiSettings::noteTypeForLanguage).
const SETTINGS = {
    url: 'http://localhost',
    port: 8766,
    deck: 'My deck',
    fields: {etymology: false},
    languages: {
        en: {deck: 'English deck', fields: {etymology: true, tags: false}},
        ru: {deck: 'My deck', fields: {etymology: false}},
        default: {deck: 'My deck', fields: {etymology: false}},
    },
};

describe('ankiWordSyncConfig', () => {
    it('targets the word language\'s deck and toggles over the global connection', () => {
        expect(ankiWordSyncConfig(SETTINGS, 'en')).toEqual({
            url: 'http://localhost',
            port: 8766,
            deck: 'English deck',
            fields: {etymology: true, tags: false},
            noteType: `${ANKI_NOTE_TYPE} ⋅ en`,
        });
    });

    it('falls back to the resolved `default` entry for a missing or unknown language_code', () => {
        for (const code of [null, undefined, '', 'klingon']) {
            expect(ankiWordSyncConfig(SETTINGS, code)).toEqual({
                url: 'http://localhost',
                port: 8766,
                deck: 'My deck',
                fields: {etymology: false},
                noteType: ANKI_NOTE_TYPE,
            });
        }
    });

    it('keeps the legacy global base as the last resort when the prop carries no languages map', () => {
        const legacy = {url: 'http://localhost', port: 8766, deck: 'My deck', fields: {etymology: false}};

        expect(ankiWordSyncConfig(legacy, 'en').deck).toBe('My deck');
        expect(ankiWordSyncConfig(legacy, 'en').fields).toEqual({etymology: false});
        expect(ankiWordSyncConfig(legacy, null).noteType).toBe(ANKI_NOTE_TYPE);
    });
});
