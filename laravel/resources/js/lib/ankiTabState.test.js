import {describe, expect, it} from 'vitest';
import {
    ankiTabDefaultCode,
    ankiTabEntry,
    ankiTabSampleData,
    ankiTabSetDeck,
    ankiTabState,
    ankiTabSyncConfig,
    ankiTabToggleField,
} from './ankiTabState';

// The resolved ankiSettings prop the profile page shares (App\Support\AnkiSettings::for):
// the connection, the legacy global base and one fully-resolved deck+fields
// entry per enabled language plus `default` (which is never persistable and
// never selectable).
const SAVED = {
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

const CODES = ['en', 'ru'];

describe('ankiTabState', () => {
    it('builds the whole persisted section: connection + legacy base + per-language entries', () => {
        const state = ankiTabState(SAVED, CODES);

        expect(state).toEqual({
            url: 'http://localhost',
            port: 8766,
            deck: 'My deck',
            fields: expect.objectContaining({etymology: false}),
            languages: {
                en: expect.objectContaining({deck: 'English deck'}),
                ru: expect.objectContaining({deck: 'My deck'}),
            },
        });
    });

    it('never carries the resolved `default` entry — it is not persistable', () => {
        expect(Object.keys(ankiTabState(SAVED, CODES).languages)).toEqual(['en', 'ru']);
    });

    it('resolves every field key per language over the legacy base, absent means on', () => {
        const state = ankiTabState(SAVED, CODES);

        // en resolved: saved per-language wins, keys it never saved walk the chain.
        expect(state.languages.en.fields.etymology).toBe(true);
        expect(state.languages.en.fields.tags).toBe(false);
        expect(state.languages.en.fields.word).toBe(true);
        // ru falls back to the legacy base.
        expect(state.languages.ru.fields.etymology).toBe(false);
        expect(state.languages.ru.fields.word).toBe(true);
    });

    it('falls back to defaults without a saved blob and without languages', () => {
        const state = ankiTabState(null, []);

        expect(state).toMatchObject({
            url: 'http://127.0.0.1',
            port: 8765,
            deck: 'ExtApp',
            languages: {},
        });
        // Every canonical field key present and on — the autosave round-trips
        // the full map, not the sparse saved one.
        expect(Object.keys(state.fields)).toContain('body');
        expect(state.fields.body).toBe(true);
    });

    it('edits one language without disturbing the legacy base, the connection or other languages', () => {
        const state = ankiTabState(SAVED, CODES);
        const next = ankiTabSetDeck(state, 'ru', ' Russian deck ');

        expect(next.languages.ru.deck).toBe('Russian deck');
        // The whole section still rides along — a languages-only payload
        // would wipe the legacy base via wholesale section replace.
        expect(next.deck).toBe('My deck');
        expect(next.url).toBe('http://localhost');
        expect(next.port).toBe(8766);
        expect(next.languages.en).toEqual(state.languages.en);
        expect(state.languages.ru.deck).toBe('My deck');
    });

    it('toggles a field on the selected language only', () => {
        const state = ankiTabState(SAVED, CODES);
        const next = ankiTabToggleField(state, 'en', 'etymology');

        expect(next.languages.en.fields.etymology).toBe(false);
        expect(next.languages.en.fields.tags).toBe(false);
        expect(next.languages.ru.fields.etymology).toBe(false);
        expect(next.deck).toBe('My deck');
    });

    it('edits the legacy base when no language is selected', () => {
        const state = ankiTabState(SAVED, CODES);

        expect(ankiTabSetDeck(state, null, 'Global deck').deck).toBe('Global deck');
        expect(ankiTabToggleField(state, null, 'tags').fields.tags).toBe(false);
    });

    it('serves the active entry: the selected language, or the legacy base', () => {
        const state = ankiTabState(SAVED, CODES);

        expect(ankiTabEntry(state, 'en')).toEqual({deck: 'English deck', fields: state.languages.en.fields});
        expect(ankiTabEntry(state, null).deck).toBe('My deck');
    });
});

describe('ankiTabDefaultCode', () => {
    it('prefers the user native language when it is enabled', () => {
        expect(ankiTabDefaultCode(CODES, 'ru')).toBe('ru');
    });

    it('falls back to the first enabled language otherwise', () => {
        expect(ankiTabDefaultCode(CODES, 'de')).toBe('en');
        expect(ankiTabDefaultCode(CODES, null)).toBe('en');
    });

    it('has no selection without enabled languages', () => {
        expect(ankiTabDefaultCode([], 'en')).toBeNull();
    });
});

describe('ankiTabSyncConfig', () => {
    it('targets the selected language note type and deck', () => {
        const state = ankiTabState(SAVED, CODES);

        expect(ankiTabSyncConfig(state, 'ru')).toEqual({
            url: 'http://localhost',
            port: 8766,
            deck: 'My deck',
            fields: state.languages.ru.fields,
            noteType: 'ExtApp Vocabulary ⋅ ru',
        });
    });

    it('omits noteType without a selection — base-type behavior', () => {
        const state = ankiTabState(SAVED, CODES);

        expect(ankiTabSyncConfig(state, null)).toEqual({
            url: 'http://localhost',
            port: 8766,
            deck: 'My deck',
            fields: state.fields,
        });
    });
});

describe('ankiTabSampleData', () => {
    it('stamps the selected language onto the sample payload', () => {
        expect(ankiTabSampleData({word: 'melted', language_code: 'en'}, 'ru'))
            .toEqual({word: 'melted', language_code: 'ru'});
    });

    it('keeps the sample payload as-is without a selection', () => {
        const sample = {word: 'melted', language_code: 'en'};
        expect(ankiTabSampleData(sample, null)).toEqual(sample);
    });
});
