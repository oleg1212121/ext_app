import {describe, expect, it} from 'vitest';
import {buildAskPayload, stripLegacyAsterisk} from './aiAsk.mjs';

describe('stripLegacyAsterisk', () => {
    it('trims whitespace', () => {
        expect(stripLegacyAsterisk('  hello  ')).toBe('hello');
    });

    it('strips only the first literal asterisk (legacy behavior, pinned)', () => {
        expect(stripLegacyAsterisk('*leading')).toBe('leading');
        expect(stripLegacyAsterisk('mid*dle')).toBe('middle');
        expect(stripLegacyAsterisk('a*b*c')).toBe('ab*c');
        expect(stripLegacyAsterisk('**')).toBe('*');
    });

    it('coerces non-strings', () => {
        expect(stripLegacyAsterisk(null)).toBe('');
        expect(stripLegacyAsterisk(undefined)).toBe('');
        expect(stripLegacyAsterisk(42)).toBe('42');
    });
});

describe('buildAskPayload', () => {
    it('joins cell and workplace text with a newline and carries tasks plus language codes', () => {
        expect(buildAskPayload({
            cellContent: 'He gave up.',
            workplaceText: 'Он сдался.',
            tasks: 'Assess the translation.',
            base: 'ru',
            learning: 'en',
        })).toEqual({
            data: 'He gave up.\nОн сдался.',
            tasks: 'Assess the translation.',
            base: 'ru',
            learning: 'en',
        });
    });

    it('normalizes missing language codes to null', () => {
        expect(buildAskPayload({cellContent: 'a', workplaceText: 'b', tasks: '', base: undefined, learning: null}))
            .toEqual({data: 'a\nb', tasks: '', base: null, learning: null});
    });
});
