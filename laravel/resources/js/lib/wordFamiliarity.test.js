import {describe, expect, it} from 'vitest';
import {patchWordStatus} from './wordFamiliarity.js';

describe('patchWordStatus', () => {
    it('recolors the word on both canonical sides', () => {
        const maps = {
            a: {cat: {w: 3, s: 0}, dog: {w: 4, s: 50}},
            b: {кот: {w: 9, s: 0}, cat: {w: 3, s: 10}},
        };
        expect(patchWordStatus(maps, 'cat', 60)).toEqual({
            a: {cat: {w: 3, s: 60}, dog: {w: 4, s: 50}},
            b: {кот: {w: 9, s: 0}, cat: {w: 3, s: 60}},
        });
    });

    it('keeps entries the key does not touch', () => {
        const maps = {a: {dog: {w: 4, s: 50}}, b: {}};
        expect(patchWordStatus(maps, 'cat', 60)).toEqual({a: {dog: {w: 4, s: 50}}, b: {}});
    });

    it('passes null maps through (before the first page load)', () => {
        expect(patchWordStatus(null, 'cat', 60)).toBeNull();
    });
});
