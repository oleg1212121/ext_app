import {beforeEach, describe, expect, it} from 'vitest';
import {loadPositions, writeCurrentText, writePosition} from './simulatorPosition';

// The module reads/writes localStorage directly; in the Node test environment
// a minimal in-memory stub stands in for the browser's store.
function stubLocalStorage() {
    const store = new Map();
    globalThis.localStorage = {
        getItem: (key) => store.has(key) ? store.get(key) : null,
        setItem: (key, value) => store.set(key, String(value)),
        removeItem: (key) => store.delete(key),
    };
}

describe('writePosition', () => {
    beforeEach(stubLocalStorage);

    it('creates the entry with the given fields and seeds currentText', () => {
        const positions = writePosition(12, {page: 3});
        expect(positions.currentText).toBe('12');
        expect(positions.alignments['12']).toEqual({page: 3});
        expect(loadPositions().alignments['12']).toEqual({page: 3});
    });

    it('merges into an existing entry without losing other fields', () => {
        writePosition(12, {page: 1});
        const positions = writePosition(12, {row: {n: 5, target: true, base: false}});
        expect(positions.alignments['12']).toEqual({page: 1, row: {n: 5, target: true, base: false}});
    });

    it('deletes the legacy flipped key from an older build\'s store', () => {
        localStorage.setItem('ext_app.simulator.position.v1', JSON.stringify({
            currentText: '7',
            alignments: {7: {page: 2, flipped: true, row: null}},
        }));

        const positions = writePosition(7, {page: 4});
        expect(positions.alignments['7']).toEqual({page: 4, row: null});
    });

    it('keys entries by string id regardless of the id type passed in', () => {
        writePosition('9', {page: 1});
        expect(loadPositions().alignments['9']).toEqual({page: 1});
    });
});

describe('writeCurrentText', () => {
    beforeEach(stubLocalStorage);

    it('records the open match without touching per-match entries', () => {
        writePosition(12, {page: 3});
        writeCurrentText(13);
        const positions = loadPositions();
        expect(positions.currentText).toBe('13');
        expect(positions.alignments['12']).toEqual({page: 3});
    });
});
