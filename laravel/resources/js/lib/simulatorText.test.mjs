import {describe, expect, it} from 'vitest';
import {clampPage, mapTextPayload} from './simulatorText.mjs';

describe('mapTextPayload', () => {
    it('renames the snake_case wire shape to the page shape (ADR 0060)', () => {
        const mapped = mapTextPayload({
            rows: [{key: 'mm:1'}],
            word_maps: {a: {cat: {w: 3}}},
            highlightable: {a: true, b: false},
            explainable: {a: false, b: true},
            languages: {a: {code: 'en', name: 'English'}},
            default_learning_side: 'a',
            meta: {current_page: 2, per_page: 50, total: 120, last_page: 3},
        }, {page: 2, perPage: 50});

        expect(mapped).toEqual({
            rows: [{key: 'mm:1'}],
            wordMaps: {a: {cat: {w: 3}}},
            highlightable: {a: true, b: false},
            explainable: {a: false, b: true},
            languages: {a: {code: 'en', name: 'English'}},
            defaultLearningSide: 'a',
            meta: {current_page: 2, per_page: 50, total: 120, last_page: 3},
        });
    });

    it('defaults missing fields, deriving meta from the requested page', () => {
        const mapped = mapTextPayload({rows: [{key: 'es:1'}, {key: 'es:2'}]}, {page: 4, perPage: 50});
        expect(mapped.rows).toHaveLength(2);
        expect(mapped.wordMaps).toEqual({});
        expect(mapped.highlightable).toEqual({a: false, b: false});
        expect(mapped.explainable).toEqual({a: false, b: false});
        expect(mapped.languages).toBeNull();
        expect(mapped.defaultLearningSide).toBeNull();
        expect(mapped.meta).toEqual({current_page: 4, per_page: 50, total: 2, last_page: 1});
    });
});

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
