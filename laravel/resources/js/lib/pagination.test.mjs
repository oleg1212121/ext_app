import {describe, expect, it} from 'vitest';
import {clampPage} from './pagination.mjs';

describe('clampPage', () => {
    it('keeps in-range pages', () => {
        expect(clampPage(3, 5)).toBe(3);
        expect(clampPage(1, 5)).toBe(1);
        expect(clampPage(5, 5)).toBe(5);
    });

    it('clamps below 1 and beyond lastPage', () => {
        expect(clampPage(0, 5)).toBe(1);
        expect(clampPage(-2, 5)).toBe(1);
        expect(clampPage(9, 5)).toBe(5);
    });
});
