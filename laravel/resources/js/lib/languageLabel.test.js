import {describe, expect, it} from 'vitest';
import {pendingLanguageLabel} from './languageLabel';

const LANGUAGES = [
    {code: 'en', name: 'English', native_name: 'English'},
    {code: 'ru', name: 'Russian', native_name: 'Русский'},
    {code: 'be', name: 'Belarusian', native_name: 'Беларуская'},
];

const t = (key) => `t:${key}`;

describe('pendingLanguageLabel', () => {
    it('keeps english on its ui-string key', () => {
        expect(pendingLanguageLabel('en', LANGUAGES, t)).toBe('t:reader.english');
    });

    it('keeps russian on its ui-string key', () => {
        expect(pendingLanguageLabel('ru', LANGUAGES, t)).toBe('t:reader.russian');
    });

    it('names any other language from the languages list', () => {
        expect(pendingLanguageLabel('be', LANGUAGES, t)).toBe('Беларуская');
    });

    it('prefers the native name over the registry name', () => {
        const languages = [{code: 'de', name: 'German', native_name: 'Deutsch'}];

        expect(pendingLanguageLabel('de', languages, t)).toBe('Deutsch');
    });

    it('falls back to the code for a language missing from the list', () => {
        expect(pendingLanguageLabel('uk', LANGUAGES, t)).toBe('uk');
    });
});
