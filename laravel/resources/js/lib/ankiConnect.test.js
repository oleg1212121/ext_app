import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {
    ANKI_DEFAULT_PORT,
    ANKI_DEFAULT_URL,
    AnkiConnectError,
    addNote,
    ankiConnectEndpoint,
    ankiConnectVersion,
    createDeck,
    createModel,
    deckNames,
    findNotes,
    invoke,
    modelFieldNames,
    modelNames,
    modelTemplates,
    notesInfo,
    requestPermission,
    updateModelStyling,
    updateModelTemplates,
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
        expect(init.headers).toEqual({'Content-Type': 'application/json'});
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

    it('requestPermission posts the bare action', async () => {
        fetchMock.mockResolvedValue(okAnswer({permission: 'granted', requireApiKey: false}));
        await expect(requestPermission(CONFIG)).resolves.toEqual({permission: 'granted', requireApiKey: false});
        expect(sentBody().action).toBe('requestPermission');
    });
});
