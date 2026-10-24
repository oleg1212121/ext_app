import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';

// The popup's on-open reachability probe wraps the version action in a
// page-session SUCCESS ledger, a 30s per-endpoint failure memo and an
// in-flight share: a gated call ({onlyIfKnown: true}) answers null without
// a request while no success is on record (the browser logs every refused
// fetch — a cold page browsing with Anki down must stay silent), and once
// anything succeeds (a probe, a completed send via markAnkiReachable) the
// gated calls probe for real again. A failure (or unmarkAnkiReachable)
// drops the ledger entry and re-silences the gate; failures also memoize
// 30s so ungated retries stay rare. Each test uses its own port so the
// module state's cross-test keys stay apart.
import {
    ANKI_DEFAULT_PORT,
    ANKI_DEFAULT_URL,
    AnkiConnectError,
    addNote,
    addTags,
    ankiConnectEndpoint,
    ankiConnectVersion,
    createDeck,
    createModel,
    deckNames,
    findNotes,
    getNoteTags,
    invoke,
    markAnkiReachable,
    modelFieldNames,
    modelNames,
    modelTemplates,
    notesInfo,
    probeReachable,
    removeTags,
    requestPermission,
    unmarkAnkiReachable,
    updateModelStyling,
    updateModelTemplates,
    updateNote,
    updateNoteFields,
} from './ankiConnect';

const CONFIG = {url: 'http://127.0.0.1', port: 8765};

let fetchMock;

beforeEach(() => {
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

// AnkiConnect answers {result, error} inside HTTP 200 on success.
const okAnswer = (result) => ({ok: true, status: 200, json: async () => ({result, error: null})});

const sentBody = (call = 0) => JSON.parse(fetchMock.mock.calls[call][1].body);

describe('ankiConnectEndpoint', () => {
    it('joins url and port from the config', () => {
        expect(ankiConnectEndpoint({url: 'http://192.168.1.10', port: 8765})).toBe('http://192.168.1.10:8765');
    });

    it('falls back to the exported defaults', () => {
        expect(ANKI_DEFAULT_URL).toBe('http://127.0.0.1');
        expect(ANKI_DEFAULT_PORT).toBe(8765);
        expect(ankiConnectEndpoint()).toBe('http://127.0.0.1:8765');
        expect(ankiConnectEndpoint({})).toBe('http://127.0.0.1:8765');
        expect(ankiConnectEndpoint({url: null, port: 0})).toBe('http://127.0.0.1:8765');
    });

    it('trims trailing slashes and accepts numeric-string ports', () => {
        expect(ankiConnectEndpoint({url: 'http://127.0.0.1///', port: '9000'})).toBe('http://127.0.0.1:9000');
    });
});

describe('invoke', () => {
    it('posts {action, version, params} as JSON to the endpoint and returns the result', async () => {
        fetchMock.mockResolvedValue(okAnswer([1714055939624]));
        const result = await invoke('findNotes', {query: 'deck:"ExtApp"'}, CONFIG);
        expect(result).toEqual([1714055939624]);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('http://127.0.0.1:8765');
        expect(init.method).toBe('POST');
        // No Content-Type header: a string body defaults to text/plain, a
        // CORS-simple request — the browser never fires the OPTIONS
        // preflight (one refused request instead of two when Anki is down),
        // and AnkiConnect parses the raw body as JSON either way.
        expect(init.headers).toBeUndefined();
        expect(JSON.parse(init.body)).toEqual({
            action: 'findNotes',
            version: 6,
            params: {query: 'deck:"ExtApp"'},
        });
    });

    it('omits the params key when the action takes none', async () => {
        fetchMock.mockResolvedValue(okAnswer('1.0'));
        await ankiConnectVersion(CONFIG);
        expect(sentBody()).toEqual({action: 'version', version: 6});
    });

    it('rejects with kind anki when AnkiConnect reports an error inside HTTP 200', async () => {
        fetchMock.mockResolvedValue({ok: true, status: 200, json: async () => ({result: null, error: 'deck was not found'})});
        const error = await invoke('createDeck', {deck: 'ExtApp'}, CONFIG).catch((caught) => caught);
        expect(error).toBeInstanceOf(AnkiConnectError);
        expect(error.name).toBe('AnkiConnectError');
        expect(error.kind).toBe('anki');
        expect(error.message).toBe('deck was not found');
    });

    it('rejects with kind network when fetch itself rejects', async () => {
        fetchMock.mockRejectedValue(new TypeError('fetch failed'));
        const error = await invoke('version', null, CONFIG).catch((caught) => caught);
        expect(error).toBeInstanceOf(AnkiConnectError);
        expect(error.kind).toBe('network');
        expect(error.message).toContain('http://127.0.0.1:8765');
    });

    it('normalizes non-2xx answers and non-JSON bodies into kind network', async () => {
        fetchMock.mockResolvedValue({ok: false, status: 500, json: async () => ({})});
        const httpError = await invoke('version', null, CONFIG).catch((caught) => caught);
        expect(httpError.kind).toBe('network');
        expect(httpError.message).toBe('AnkiConnect answered HTTP 500');

        fetchMock.mockResolvedValue({ok: true, status: 200, json: async () => {
            throw new Error('Unexpected token');
        }});
        const jsonError = await invoke('version', null, CONFIG).catch((caught) => caught);
        expect(jsonError.kind).toBe('network');
        expect(jsonError.message).toBe('AnkiConnect answer was not JSON');
    });
});

// The failure memo (and the in-flight share) on their own — the ungated
// probe's padding. Each test uses its own port so the module state's
// cross-test keys stay apart.
describe('probeReachable', () => {
    it('passes the version answer through', async () => {
        vi.useFakeTimers();
        try {
            vi.setSystemTime(0);
            fetchMock.mockResolvedValue(okAnswer('1.0'));
            await expect(probeReachable({port: 8801})).resolves.toBe('1.0');
        } finally {
            vi.useRealTimers();
        }
    });

    it('memoizes a failure for 30s — a retry inside the window never fetches', async () => {
        vi.useFakeTimers();
        try {
            vi.setSystemTime(0);
            fetchMock.mockRejectedValue(new TypeError('fetch failed'));
            const first = await probeReachable({port: 8802}).catch((caught) => caught);
            expect(first).toBeInstanceOf(AnkiConnectError);
            expect(first.kind).toBe('network');

            const second = await probeReachable({port: 8802}).catch((caught) => caught);
            expect(second).toBe(first);
            expect(fetchMock).toHaveBeenCalledTimes(1);
        } finally {
            vi.useRealTimers();
        }
    });

    it('the memo expires after 30s — a later retry probes for real', async () => {
        vi.useFakeTimers();
        try {
            vi.setSystemTime(0);
            fetchMock.mockRejectedValue(new TypeError('fetch failed'));
            await probeReachable({port: 8803}).catch(() => null);
            expect(fetchMock).toHaveBeenCalledTimes(1);

            vi.advanceTimersByTime(30_000);
            await probeReachable({port: 8803}).catch(() => null);
            expect(fetchMock).toHaveBeenCalledTimes(2);
        } finally {
            vi.useRealTimers();
        }
    });

    it('a success clears the memo — the next failure fetches again', async () => {
        vi.useFakeTimers();
        try {
            vi.setSystemTime(0);
            fetchMock.mockRejectedValueOnce(new TypeError('fetch failed'));
            await probeReachable({port: 8804}).catch(() => null);
            // Past the window the retry reaches the network and succeeds —
            // which clears the memoized failure.
            vi.advanceTimersByTime(30_000);
            fetchMock.mockResolvedValue(okAnswer('1.0'));
            await expect(probeReachable({port: 8804})).resolves.toBe('1.0');

            // A fresh failure right after is NOT answered from the memo —
            // it fetches (and re-memoizes).
            fetchMock.mockRejectedValueOnce(new TypeError('fetch failed'));
            const again = await probeReachable({port: 8804}).catch((caught) => caught);
            expect(again.kind).toBe('network');
            expect(fetchMock).toHaveBeenCalledTimes(3);
        } finally {
            vi.useRealTimers();
        }
    });

    it('endpoints memoize separately', async () => {
        vi.useFakeTimers();
        try {
            vi.setSystemTime(0);
            fetchMock.mockRejectedValue(new TypeError('fetch failed'));
            await probeReachable({port: 8805}).catch(() => null);
            await probeReachable({port: 8806}).catch(() => null);
            expect(fetchMock).toHaveBeenCalledTimes(2);
        } finally {
            vi.useRealTimers();
        }
    });

    it('concurrent probes share one request', async () => {
        let resolveFetch;
        fetchMock.mockReturnValue(new Promise((resolve) => {
            resolveFetch = resolve;
        }));
        const first = probeReachable({port: 8807});
        const second = probeReachable({port: 8807});
        resolveFetch(okAnswer('1.0'));
        await expect(first).resolves.toBe('1.0');
        await expect(second).resolves.toBe('1.0');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});

// The quiet gate (2026-10-24): the popup's on-open probe passes
// {onlyIfKnown: true} — while the page session holds no successful Anki
// answer, the gated call answers null WITHOUT touching the network, so a
// cold page browsed with Anki down logs zero console errors. Engaging Anki
// for real (an ungated probe — the reader's hover/click — or a completed
// send via markAnkiReachable) lights the gate; a failure or
// unmarkAnkiReachable (a send that found Anki gone) extinguishes it.
describe('probeReachable — the session success ledger gates onlyIfKnown', () => {
    it('a cold endpoint answers null to the gated probe without a request', async () => {
        await expect(probeReachable({port: 8811}, {onlyIfKnown: true})).resolves.toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('an ungated probe always fetches — and its success opens the gate', async () => {
        fetchMock.mockResolvedValue(okAnswer('1.0'));
        await expect(probeReachable({port: 8812})).resolves.toBe('1.0');

        fetchMock.mockClear();
        await expect(probeReachable({port: 8812}, {onlyIfKnown: true})).resolves.toBe('1.0');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('markAnkiReachable opens the gate — a send-completed success counts', async () => {
        markAnkiReachable({port: 8813});
        fetchMock.mockResolvedValue(okAnswer('1.0'));
        await expect(probeReachable({port: 8813}, {onlyIfKnown: true})).resolves.toBe('1.0');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('a failed probe closes the gate again — the next open goes quiet', async () => {
        markAnkiReachable({port: 8814});
        fetchMock.mockRejectedValue(new TypeError('fetch failed'));
        await probeReachable({port: 8814}).catch(() => null);

        fetchMock.mockClear();
        await expect(probeReachable({port: 8814}, {onlyIfKnown: true})).resolves.toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('unmarkAnkiReachable closes the gate — a network-failed send re-silences', async () => {
        markAnkiReachable({port: 8815});
        unmarkAnkiReachable({port: 8815});
        await expect(probeReachable({port: 8815}, {onlyIfKnown: true})).resolves.toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});

// One representative assert per wrapper — the wire shapes are the contract
// ankiSync.js builds on (the nested model objects corrected in
// anki-connection ticket 03 are pinned exactly).
describe('action wrappers', () => {
    it('deckNames posts the bare action and resolves the result', async () => {
        fetchMock.mockResolvedValue(okAnswer(['ExtApp']));
        await expect(deckNames(CONFIG)).resolves.toEqual(['ExtApp']);
        expect(sentBody()).toEqual({action: 'deckNames', version: 6});
    });

    it('createDeck posts {deck}', async () => {
        fetchMock.mockResolvedValue(okAnswer(null));
        await createDeck(CONFIG, 'ExtApp');
        expect(sentBody().action).toBe('createDeck');
        expect(sentBody().params).toEqual({deck: 'ExtApp'});
    });

    it('modelNames posts the bare action', async () => {
        fetchMock.mockResolvedValue(okAnswer([]));
        await modelNames(CONFIG);
        expect(sentBody().action).toBe('modelNames');
        expect(sentBody().params).toBeUndefined();
    });

    it('modelFieldNames posts {modelName}', async () => {
        fetchMock.mockResolvedValue(okAnswer(['Word']));
        await modelFieldNames(CONFIG, 'ExtApp Vocabulary');
        expect(sentBody().action).toBe('modelFieldNames');
        expect(sentBody().params).toEqual({modelName: 'ExtApp Vocabulary'});
    });

    it('modelTemplates posts {modelName}', async () => {
        fetchMock.mockResolvedValue(okAnswer({}));
        await modelTemplates(CONFIG, 'ExtApp Vocabulary');
        expect(sentBody().action).toBe('modelTemplates');
        expect(sentBody().params).toEqual({modelName: 'ExtApp Vocabulary'});
    });

    it('createModel posts the full model definition', async () => {
        fetchMock.mockResolvedValue(okAnswer(null));
        const definition = {
            modelName: 'ExtApp Vocabulary',
            inOrderFields: ['Word', 'Transcription'],
            cardTemplates: [{Name: 'Recognition', Front: 'f', Back: 'b'}],
            css: '.card {}',
        };
        await createModel(CONFIG, definition);
        expect(sentBody().action).toBe('createModel');
        expect(sentBody().params).toEqual(definition);
    });

    it('updateModelTemplates nests under model:{name, templates:{card:{Front, Back}}}', async () => {
        fetchMock.mockResolvedValue(okAnswer(null));
        await updateModelTemplates(CONFIG, 'ExtApp Vocabulary', 'Recognition', {front: 'F', back: 'B'});
        expect(sentBody().action).toBe('updateModelTemplates');
        expect(sentBody().params).toEqual({
            model: {name: 'ExtApp Vocabulary', templates: {Recognition: {Front: 'F', Back: 'B'}}},
        });
    });

    it('updateModelStyling nests under model:{name, css}', async () => {
        fetchMock.mockResolvedValue(okAnswer(null));
        await updateModelStyling(CONFIG, 'ExtApp Vocabulary', '.card {}');
        expect(sentBody().action).toBe('updateModelStyling');
        expect(sentBody().params).toEqual({model: {name: 'ExtApp Vocabulary', css: '.card {}'}});
    });

    it('findNotes posts {query} and notesInfo posts {notes}', async () => {
        fetchMock.mockResolvedValue(okAnswer([]));
        await findNotes(CONFIG, 'deck:"ExtApp" Word:"melted"');
        expect(sentBody(0).action).toBe('findNotes');
        expect(sentBody(0).params).toEqual({query: 'deck:"ExtApp" Word:"melted"'});

        await notesInfo(CONFIG, [1714055939624]);
        expect(sentBody(1).action).toBe('notesInfo');
        expect(sentBody(1).params).toEqual({notes: [1714055939624]});
    });

    it('addNote wraps the note payload in {note}', async () => {
        fetchMock.mockResolvedValue(okAnswer(1714055939624));
        const note = {deckName: 'ExtApp', modelName: 'ExtApp Vocabulary'};
        await expect(addNote(CONFIG, note)).resolves.toBe(1714055939624);
        expect(sentBody().action).toBe('addNote');
        expect(sentBody().params).toEqual({note});
    });

    // The note-update actions, verified against the plugin source (release
    // 24.7.25.0 / 25.11.9.0): updateNote applies fields and tags in one call
    // with supplied tags REPLACING the set — no "updateNotes" (plural)
    // action exists in any release. updateNoteFields touches fields only
    // (unknown field names are ignored). Tag actions take a note-id array
    // plus a space-joined tag string; getNoteTags takes a single id.
    describe('note-update actions', () => {
        it('updateNote wraps {note: {id, fields, tags}}', async () => {
            fetchMock.mockResolvedValue(okAnswer(null));
            await updateNote(CONFIG, {id: 1714055939624, fields: {Word: 'melted'}, tags: ['A2', 'top-3000']});
            expect(sentBody().action).toBe('updateNote');
            expect(sentBody().params).toEqual({
                note: {id: 1714055939624, fields: {Word: 'melted'}, tags: ['A2', 'top-3000']},
            });
        });

        it('updateNoteFields wraps {note: {id, fields}} — no tags key', async () => {
            fetchMock.mockResolvedValue(okAnswer(null));
            await updateNoteFields(CONFIG, {id: 1714055939624, fields: {Word: 'melted'}});
            expect(sentBody().action).toBe('updateNoteFields');
            expect(sentBody().params).toEqual({note: {id: 1714055939624, fields: {Word: 'melted'}}});
            expect(sentBody().params.note).not.toHaveProperty('tags');
        });

        it('getNoteTags posts {note: <single id>} and resolves the tag array', async () => {
            fetchMock.mockResolvedValue(okAnswer(['A2', 'top-3000']));
            await expect(getNoteTags(CONFIG, 1714055939624)).resolves.toEqual(['A2', 'top-3000']);
            expect(sentBody().action).toBe('getNoteTags');
            expect(sentBody().params).toEqual({note: 1714055939624});
        });

        it('addTags and removeTags post {notes, tags} with the tag array space-joined', async () => {
            fetchMock.mockResolvedValue(okAnswer(null));

            await addTags(CONFIG, [1714055939624], ['A2', 'top-3000']);
            expect(sentBody().action).toBe('addTags');
            expect(sentBody().params).toEqual({notes: [1714055939624], tags: 'A2 top-3000'});

            await removeTags(CONFIG, [1714055939624, 1714055939625], ['legacy']);
            expect(sentBody(1).action).toBe('removeTags');
            expect(sentBody(1).params).toEqual({notes: [1714055939624, 1714055939625], tags: 'legacy'});
        });
    });

    it('requestPermission posts the bare action', async () => {
        fetchMock.mockResolvedValue(okAnswer({permission: 'granted', requireApiKey: false}));
        await expect(requestPermission(CONFIG)).resolves.toEqual({permission: 'granted', requireApiKey: false});
        expect(sentBody().action).toBe('requestPermission');
    });
});
