import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {resolveWordId} from './wordLookup';

// ai-answer-words ticket 02 — the missing-mode lookup POST moved out of
// WordPopup.jsx into this pure lib module (ADR 0066: lib modules test in
// Node, fetch stubbed — the ankiSync.test.js pattern). getCsrfToken reads
// the meta tag off the document, so the test stubs a minimal one and every
// pinned request must carry the signed header. The review pass unexported
// the single-code lookupWordId (no production caller — both popup sites go
// through the resolver), so the public surface here is resolveWordId alone;
// the single-code behavior pins run against one-code lists, which is how
// the no-prop popup calls it.

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

// A /words/lookup answer: {data: {word_id: int|null}} — the id ALONE; the
// full payload comes from the separate GET /words/{word} fetch.
const lookupResponse = (wordId) => ({
    ok: true,
    status: 200,
    json: async () => ({data: {word_id: wordId}}),
});

const failedResponse = (status) => ({
    ok: false,
    status,
    json: async () => null,
});

const requestBodyOf = (call) => JSON.parse(call[1].body);

describe('resolveWordId: the ordered-language resolver', () => {
    it('runs the codes in list order, each request carrying its own code', async () => {
        fetchMock
            .mockImplementationOnce(async () => lookupResponse(null))
            .mockImplementationOnce(async () => lookupResponse(11));
        await expect(resolveWordId('melted', ['ru', 'en'])).resolves.toBe(11);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(requestBodyOf(fetchMock.mock.calls[0])).toEqual({
            surface: 'melted',
            language_code: 'ru',
        });
        expect(requestBodyOf(fetchMock.mock.calls[1])).toEqual({
            surface: 'melted',
            language_code: 'en',
        });
    });

    it('short-circuits: a hit on the first code never fires the next request', async () => {
        fetchMock.mockImplementationOnce(async () => lookupResponse(3));
        await expect(resolveWordId('melted', ['en', 'ru'])).resolves.toBe(3);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(requestBodyOf(fetchMock.mock.calls[0])).toEqual({
            surface: 'melted',
            language_code: 'en',
        });
    });

    it('falls through on a miss: first null, second non-null resolves the second', async () => {
        fetchMock
            .mockImplementationOnce(async () => lookupResponse(null))
            .mockImplementationOnce(async () => lookupResponse(11));
        await expect(resolveWordId('melted', ['ru', 'en'])).resolves.toBe(11);
    });

    it('an all-miss resolves null after every code had its chance', async () => {
        fetchMock.mockImplementation(async () => lookupResponse(null));
        await expect(resolveWordId('melted', ['ru', 'en', 'de'])).resolves.toBeNull();
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('a network failure on any code collapses the whole resolution to null — later codes never fire', async () => {
        fetchMock
            .mockImplementationOnce(async () => {
                throw new TypeError('Failed to fetch');
            })
            .mockImplementationOnce(async () => lookupResponse(11));
        await expect(resolveWordId('melted', ['ru', 'en'])).resolves.toBeNull();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('an HTTP failure on any code collapses the whole resolution to null — later codes never fire', async () => {
        fetchMock
            .mockImplementationOnce(async () => failedResponse(500))
            .mockImplementationOnce(async () => lookupResponse(11));
        await expect(resolveWordId('melted', ['ru', 'en'])).resolves.toBeNull();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('a single-code list is the exact single-code lookup contract', async () => {
        // The no-prop popup path: the resolve effect calls the resolver with
        // [language.code] — the request, headers, and null-collapse of the
        // old postLookup, byte for byte.
        fetchMock.mockImplementationOnce(async () => lookupResponse(7));
        await expect(resolveWordId('melted', ['en'])).resolves.toBe(7);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/words/lookup');
        expect(init.method).toBe('POST');
        expect(init.headers['Content-Type']).toBe('application/json');
        expect(init.headers.Accept).toBe('application/json');
        expect(init.headers['X-CSRF-TOKEN']).toBe('csrf-token-value');
        expect(requestBodyOf(fetchMock.mock.calls[0])).toEqual({
            surface: 'melted',
            language_code: 'en',
        });
    });

    it('a single-code list collapses a miss, an HTTP failure and a network failure into one null', async () => {
        // A genuine miss: the server answers 200 with a null word id.
        fetchMock.mockImplementationOnce(async () => lookupResponse(null));
        await expect(resolveWordId('zzz', ['en'])).resolves.toBeNull();
        // A non-2xx status (throttle, auth, server error).
        fetchMock.mockImplementationOnce(async () => failedResponse(500));
        await expect(resolveWordId('zzz', ['en'])).resolves.toBeNull();
        // A refused request — the browser logs it, the caller never sees it.
        fetchMock.mockImplementationOnce(async () => {
            throw new TypeError('Failed to fetch');
        });
        await expect(resolveWordId('zzz', ['en'])).resolves.toBeNull();
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });
});
