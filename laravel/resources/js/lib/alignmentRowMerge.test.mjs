import {describe, expect, it} from 'vitest';
import {mergeMutationRows} from './alignmentRowMerge.js';

const row = (id, similarity) => ({id, key: `mm-${id}`, similarity, a_sentences: [], b_sentences: []});

describe('mergeMutationRows', () => {
    it('replaces rows already on the page in place', () => {
        const base = [row(1, 0.4), row(2, 0.5), row(3, 0.9)];
        const merged = mergeMutationRows(base, [row(2, 1.0)], null);

        expect(merged.map((r) => r.id)).toEqual([1, 2, 3]);
        expect(merged[1].similarity).toBe(1.0);
        // inputs untouched
        expect(base[1].similarity).toBe(0.5);
    });

    it('skips payload rows that are not on the displayed page (approve from needs review)', () => {
        const base = [row(1, 0.4), row(2, 0.5)];
        const merged = mergeMutationRows(base, [row(99, 1.0)], null);

        expect(merged.map((r) => r.id)).toEqual([1, 2]);
    });

    it('splices a newly created row after its anchor row', () => {
        const base = [row(1, 0.4), row(2, 0.5)];
        const merged = mergeMutationRows(base, [row(7, 1.0)], 1);

        expect(merged.map((r) => r.id)).toEqual([1, 7, 2]);
    });

    it('falls back to appending when the anchor row is not on the page', () => {
        const base = [row(1, 0.4), row(2, 0.5)];
        const merged = mergeMutationRows(base, [row(7, 1.0)], 42);

        expect(merged.map((r) => r.id)).toEqual([1, 2, 7]);
    });

    it('drops optimistic temp rows from the base list', () => {
        const base = [row(1, 0.4), {id: 'tmp-123', key: 'mm-tmp-123', similarity: null, a_sentences: [], b_sentences: []}];
        const merged = mergeMutationRows(base, [row(1, 1.0)], null);

        expect(merged.map((r) => r.id)).toEqual([1]);
    });
});
