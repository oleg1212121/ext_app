import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {patchWordStatus, recordWordOpens} from './wordFamiliarity.js';

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

// word-popup-overhaul ticket 01 — the popup-open penalty POST: every open
// of a mapped word costs −5, every time (no dedupe). Behavioral pins like
// wordLookup.test.js's (ADR 0066: lib modules test in Node, fetch stubbed,
// the document stubbed for getCsrfToken's meta-tag read).
describe('recordWordOpens', () => {
    let fetchMock;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('document', {
            querySelector: () => ({getAttribute: () => 'csrf-token-value'}),
        });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    const openResponse = (familiarity) => ({
        ok: true,
        status: 200,
        json: async () => ({data: {familiarity}}),
    });

    it('POSTs the word ids to /words/opens — CSRF-signed — and unwraps the familiarity map', async () => {
        fetchMock.mockImplementationOnce(async () => openResponse({7: 95}));

        await expect(recordWordOpens([7])).resolves.toEqual({7: 95});

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/words/opens');
        expect(init.method).toBe('POST');
        expect(init.headers.Accept).toBe('application/json');
        expect(init.headers['X-CSRF-TOKEN']).toBe('csrf-token-value');
        expect(JSON.parse(init.body)).toEqual({word_ids: [7]});
    });

    it('resolves {} on a non-ok response — the caller just does not recolor', async () => {
        fetchMock.mockImplementationOnce(async () => ({ok: false, status: 429, json: async () => null}));

        await expect(recordWordOpens([7])).resolves.toEqual({});
    });

    it('resolves {} on a transport failure — never throws, reading never blocks', async () => {
        fetchMock.mockImplementationOnce(async () => {
            throw new Error('network down');
        });

        await expect(recordWordOpens([7])).resolves.toEqual({});
    });
});
