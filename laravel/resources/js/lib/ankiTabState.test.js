import {describe, expect, it} from 'vitest';
import {
    ankiTabDefaultCode,
    ankiTabDefaultEntry,
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
    it('builds the whole persisted section: connection + per-language entries', () => {
        const state = ankiTabState(SAVED, CODES);

        expect(state).toEqual({
            url: 'http://localhost',
            port: 8766,
            languages: {
                en: expect.objectContaining({deck: 'English deck'}),
                ru: expect.objectContaining({deck: 'My deck'}),
            },
        });
    });

    it('keeps the legacy global base out of the persisted section — it is read-only', () => {
        // The endpoint strips incoming deck/fields and preserves the saved
        // pair server-side, so the autosave payload must not carry them (a
        // save rewrites the whole section, which would otherwise rewrite the
        // base on every tab save).
        const state = ankiTabState(SAVED, CODES);

        expect(state).not.toHaveProperty('deck');
        expect(state).not.toHaveProperty('fields');
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

        expect(state).toEqual({
            url: 'http://127.0.0.1',
            port: 8765,
            languages: {},
        });
    });

    it('edits one language without disturbing the connection or other languages', () => {
        const state = ankiTabState(SAVED, CODES);
        const next = ankiTabSetDeck(state, 'ru', ' Russian deck ');

        expect(next.languages.ru.deck).toBe('Russian deck');
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
    });

    it('is unwritable without a selection — the legacy base is read-only', () => {
        const state = ankiTabState(SAVED, CODES);

        expect(ankiTabSetDeck(state, null, 'Global deck')).toEqual(state);
        expect(ankiTabToggleField(state, null, 'tags')).toEqual(state);
    });

    it('serves the selected language\'s entry, or null without a selection', () => {
        const state = ankiTabState(SAVED, CODES);

        expect(ankiTabEntry(state, 'en')).toEqual({deck: 'English deck', fields: state.languages.en.fields});
        expect(ankiTabEntry(state, null)).toBeNull();
    });
});

describe('ankiTabDefaultEntry', () => {
    it('serves the resolved `default` entry — the read-only view of the legacy base', () => {
        expect(ankiTabDefaultEntry(SAVED)).toEqual({deck: 'My deck', fields: SAVED.languages.default.fields});
    });

    it('falls back to the sparse saved base and then the defaults', () => {
        const legacy = {deck: 'My deck', fields: {etymology: false}};
        expect(ankiTabDefaultEntry(legacy).deck).toBe('My deck');
        expect(ankiTabDefaultEntry(legacy).fields.etymology).toBe(false);
        expect(ankiTabDefaultEntry(legacy).fields.word).toBe(true);

        expect(ankiTabDefaultEntry(null)).toEqual({
            deck: 'ExtApp',
            fields: expect.objectContaining({word: true, body: true}),
        });
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

    it('targets the read-only base entry without a selection — base-type behavior', () => {
        const state = ankiTabState(SAVED, CODES);

        expect(ankiTabSyncConfig(state, null, ankiTabDefaultEntry(SAVED))).toEqual({
            url: 'http://localhost',
            port: 8766,
            deck: 'My deck',
            fields: SAVED.languages.default.fields,
        });
    });

    it('refuses to build a config with neither a selection nor a base entry', () => {
        const state = ankiTabState(SAVED, CODES);

        expect(() => ankiTabSyncConfig(state, null)).toThrow();
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
