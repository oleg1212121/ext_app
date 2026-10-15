import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {AnkiConnectError} from './ankiConnect';
import {
    ANKI_CARD_NAME,
    ANKI_FIELDS,
    ANKI_NOTE_TYPE,
    buildNoteFields,
    generateCardTemplate,
} from './ankiTemplate';
import {
    AnkiNoteTypeMismatchError,
    ensureAnkiTarget,
    sendAnkiNote,
} from './ankiSync';

// ankiSync.js memoizes ensures in a module-level Set keyed endpoint|deck, so
// every config() call mints a fresh port — a distinct memo key, i.e. a cold
// page session — from a counter that never resets across the file. A test
// that needs a MEMOIZED session (second ensure, forced re-push, multi-word
// sends) reuses one cfg object and gets the shared-session behavior; a test
// that calls config() twice gets two independent sessions. Resetting the
// counter per test was tried and is wrong: every test then re-mints the same
// endpoint|deck key the first test already memoized, and the ensures silently
// become memo hits. (vi.resetModules() + dynamic import was also tried and
// rejected: each re-evaluation of the module chain mints NEW
// AnkiConnectError / AnkiNoteTypeMismatchError classes, so instanceof against
// the test file's static imports fails.)

let fetchMock;
let portCounter = 8899;

beforeEach(() => {
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

const config = (overrides = {}) => ({
    url: 'http://127.0.0.1',
    port: (portCounter += 1),
    deck: 'ExtApp',
    fields: {},
    ...overrides,
});

const NAMES = ANKI_FIELDS.map(({name}) => name);

// Minimal word payload (GET /words/{word} shape) — two languages exercise
// the native-language filter, matching the per-word send's data path.
const WORD_DATA = {
    word: 'melted',
    language_code: 'en',
    entries: [{
        word_class: 'Verb',
        transcriptions: [{value: 'melt'}],
        definitions: ['To change from a solid to a liquid state.'],
        translations: [
            {word: 'таять', language_code: 'ru'},
            {word: 'таяць', language_code: 'be'},
        ],
    }],
};
const SAMPLE_TAGS = ['A2', 'top-3000'];

// Route stubbed fetch by AnkiConnect action; any action without a handler
// fails the test, so a call sequence can only pass by matching exactly.
function stubAnki(handlers) {
    fetchMock.mockImplementation(async (_url, init) => {
        const {action, params} = JSON.parse(init.body);
        if (!Object.hasOwn(handlers, action)) {
            throw new Error(`unexpected AnkiConnect action: ${action}`);
        }
        return {ok: true, status: 200, json: async () => ({result: await handlers[action](params), error: null})};
    });
}

// The "deck + note type + card all exist" path: a full ensure pass pushes
// templates + styling and creates nothing. createDeck is stubbed anyway so
// tests that point a second session at a fresh deck (memo-miss check) can
// take the create-if-missing branch.
const existingHandlers = (overrides = {}) => ({
    deckNames: () => ['ExtApp'],
    createDeck: () => null,
    modelNames: () => [ANKI_NOTE_TYPE],
    modelFieldNames: () => NAMES,
    modelTemplates: () => ({[ANKI_CARD_NAME]: {Front: 'old front', Back: 'old back'}}),
    updateModelTemplates: () => null,
    updateModelStyling: () => null,
    ...overrides,
});

const actionsOf = (from = 0) => fetchMock.mock.calls
    .slice(from)
    .map(([, init]) => JSON.parse(init.body).action);

const paramsOf = (action, occurrence = 0) => JSON.parse(
    fetchMock.mock.calls.filter(([, init]) => JSON.parse(init.body).action === action)[occurrence][1].body,
).params;

describe('ensureAnkiTarget', () => {
    it('creates deck + note type on a cold session: 13 ordered fields with Body last, Recognition card, CSS with the hide class', async () => {
        stubAnki({
            deckNames: () => [],
            createDeck: () => null,
            modelNames: () => [],
            createModel: () => 1714055939624,
        });
        const cfg = config();

        await ensureAnkiTarget(cfg);

        expect(actionsOf()).toEqual(['deckNames', 'createDeck', 'modelNames', 'createModel']);
        expect(paramsOf('createDeck')).toEqual({deck: 'ExtApp'});
        // Schema v2: the note type is created with all thirteen fields,
        // the Body value-carrier last.
        expect(NAMES).toHaveLength(13);
        expect(NAMES[NAMES.length - 1]).toBe('Body');
        const model = paramsOf('createModel');
        expect(model.modelName).toBe(ANKI_NOTE_TYPE);
        expect(model.inOrderFields).toEqual(NAMES);
        expect(model.css).toContain('.ef-hidden');
        const template = generateCardTemplate(cfg.fields);
        expect(model.cardTemplates).toEqual([
            {Name: ANKI_CARD_NAME, Front: template.front, Back: template.back},
        ]);
    });

    it('memoizes per endpoint|deck: the second ensure for the same session makes zero fetches', async () => {
        stubAnki(existingHandlers());
        const cfg = config();

        await ensureAnkiTarget(cfg);
        const afterFirst = fetchMock.mock.calls.length;
        expect(afterFirst).toBeGreaterThan(0);

        await ensureAnkiTarget(cfg);
        expect(fetchMock).toHaveBeenCalledTimes(afterFirst);
    });

    it('misses the memo when the deck differs (key is endpoint|deck)', async () => {
        stubAnki(existingHandlers());

        await ensureAnkiTarget(config());
        const afterFirst = fetchMock.mock.calls.length;

        await ensureAnkiTarget(config({deck: 'Other'}));
        expect(actionsOf(afterFirst)).toEqual([
            'deckNames', 'createDeck', 'modelNames', 'modelFieldNames', 'modelTemplates', 'updateModelTemplates', 'updateModelStyling',
        ]);
        expect(paramsOf('createDeck')).toEqual({deck: 'Other'});
    });

    it('{force: true} re-ensures a memoized target and pushes nested model objects with hide classes baked', async () => {
        stubAnki(existingHandlers());
        const cfg = config();

        await ensureAnkiTarget(cfg);
        const afterFirst = fetchMock.mock.calls.length;

        cfg.fields = {familiarity: false};
        await ensureAnkiTarget(cfg, {force: true});

        expect(actionsOf(afterFirst)).toEqual([
            'deckNames', 'modelNames', 'modelFieldNames', 'modelTemplates', 'updateModelTemplates', 'updateModelStyling',
        ]);
        const template = generateCardTemplate(cfg.fields);
        expect(paramsOf('updateModelTemplates', 1)).toEqual({
            model: {name: ANKI_NOTE_TYPE, templates: {[ANKI_CARD_NAME]: {Front: template.front, Back: template.back}}},
        });
        expect(paramsOf('updateModelStyling', 1)).toEqual({
            model: {name: ANKI_NOTE_TYPE, css: template.css},
        });
        // Hide-class semantics: the toggled-off SCALAR hides through the
        // template while the Back still carries every rendered placeholder —
        // the seven scalars plus the Body slot's (the body toggles are
        // send-time and never touch the template; the flat dictionary
        // fields keep their values but no longer render).
        expect(template.back).toContain('class="ef-field ef-familiarity ef-hidden"');
        const rendered = NAMES.filter((name) => !['Transcription', 'Translations', 'Definitions', 'Examples', 'Etymology'].includes(name));
        for (const name of rendered) {
            expect(template.back).toContain(`{{${name}}}`);
        }
        expect(rendered).toHaveLength(8);
    });

    it('throws a fields mismatch after exactly the probe calls and never memoizes it', async () => {
        const found = [...NAMES];
        found[5] = 'Translation';
        stubAnki(existingHandlers({modelFieldNames: () => found}));
        const cfg = config();

        const error = await ensureAnkiTarget(cfg).catch((caught) => caught);
        expect(error).toBeInstanceOf(AnkiNoteTypeMismatchError);
        expect(error.name).toBe('AnkiNoteTypeMismatchError');
        expect(error.kind).toBe('fields');
        expect(error.expected).toEqual(NAMES);
        expect(error.found).toEqual(found);
        expect(actionsOf()).toEqual(['deckNames', 'modelNames', 'modelFieldNames']);

        // A failed ensure is not a successful one — the next attempt retries.
        const afterFirstAttempt = fetchMock.mock.calls.length;
        await expect(ensureAnkiTarget(cfg)).rejects.toThrow(AnkiNoteTypeMismatchError);
        expect(fetchMock.mock.calls.length).toBeGreaterThan(afterFirstAttempt);
    });

    it('raises the fields mismatch against a pre-v2 12-field install — AnkiConnect cannot add a field', async () => {
        // The migration case: Anki already holds the V1 note type (twelve
        // fields, no Body). The order-insensitive set check must reject it —
        // createModel is create-only, so the 13th field can only land after
        // the user deletes the note type and the next sync recreates it.
        const v1 = NAMES.filter((name) => name !== 'Body');
        expect(v1).toHaveLength(12);
        stubAnki(existingHandlers({modelFieldNames: () => v1}));
        const cfg = config();

        const error = await ensureAnkiTarget(cfg).catch((caught) => caught);
        expect(error).toBeInstanceOf(AnkiNoteTypeMismatchError);
        expect(error.kind).toBe('fields');
        expect(error.expected).toEqual(NAMES);
        expect(error.found).toEqual(v1);
        expect(actionsOf()).toEqual(['deckNames', 'modelNames', 'modelFieldNames']);

        // The failed ensure never memoizes: the retry after the user's
        // delete-and-resync re-probes and recreates the note type.
        const afterFirstAttempt = fetchMock.mock.calls.length;
        await expect(ensureAnkiTarget(cfg)).rejects.toThrow(AnkiNoteTypeMismatchError);
        expect(fetchMock.mock.calls.length).toBeGreaterThan(afterFirstAttempt);
    });

    it('throws a card mismatch when the model matches but lacks the Recognition card', async () => {
        stubAnki(existingHandlers({
            modelTemplates: () => ({Cloze: {Front: 'x', Back: 'y'}}),
        }));

        const error = await ensureAnkiTarget(config()).catch((caught) => caught);
        expect(error).toBeInstanceOf(AnkiNoteTypeMismatchError);
        expect(error.kind).toBe('card');
        expect(error.expected).toEqual([ANKI_CARD_NAME]);
        expect(error.found).toEqual(['Cloze']);
        expect(actionsOf()).toEqual(['deckNames', 'modelNames', 'modelFieldNames', 'modelTemplates']);
    });
});

describe('sendAnkiNote', () => {
    it('first send: memoized ensure, find-before-add query, 13-field addNote with allowDuplicate false', async () => {
        stubAnki(existingHandlers({
            findNotes: () => [],
            addNote: () => 1714055939624,
        }));
        const cfg = config();
        const noteFields = buildNoteFields(WORD_DATA, {familiarity: 40, tags: SAMPLE_TAGS, nativeLanguage: 'ru'});

        await expect(sendAnkiNote(cfg, noteFields, {tags: SAMPLE_TAGS, word: 'melted'}))
            .resolves.toEqual({added: true, noteId: 1714055939624});

        expect(actionsOf()).toEqual([
            'deckNames', 'modelNames', 'modelFieldNames', 'modelTemplates', 'updateModelTemplates', 'updateModelStyling',
            'findNotes', 'addNote',
        ]);
        expect(paramsOf('findNotes').query).toBe('deck:"ExtApp" Word:"melted" Language:"en"');
        const note = paramsOf('addNote').note;
        expect(note.deckName).toBe('ExtApp');
        expect(note.modelName).toBe(ANKI_NOTE_TYPE);
        expect(Object.keys(note.fields)).toEqual(NAMES);
        expect(note.fields.Translations).toBe('таять');
        expect(note.fields.Tags).toBe('A2, top-3000');
        expect(note.tags).toEqual(SAMPLE_TAGS);
        expect(note.options).toEqual({allowDuplicate: false});
    });

    it('duplicate re-send only finds + inspects and resolves {added: false} without addNote', async () => {
        let searches = 0;
        stubAnki(existingHandlers({
            findNotes: () => {
                searches += 1;
                return searches === 1 ? [] : [1714055939624];
            },
            notesInfo: () => [{noteId: 1714055939624, modelName: ANKI_NOTE_TYPE}],
            addNote: () => 1714055939624,
        }));
        const cfg = config();
        const noteFields = buildNoteFields(WORD_DATA, {tags: SAMPLE_TAGS, nativeLanguage: 'ru'});

        await sendAnkiNote(cfg, noteFields, {tags: SAMPLE_TAGS, word: 'melted'});
        const afterFirst = fetchMock.mock.calls.length;

        await expect(sendAnkiNote(cfg, noteFields, {tags: SAMPLE_TAGS, word: 'melted'}))
            .resolves.toEqual({added: false, noteId: 1714055939624});
        expect(actionsOf(afterFirst)).toEqual(['findNotes', 'notesInfo']);
    });

    it('counts a foreign-model match as a duplicate too, preferring our own note type id', async () => {
        stubAnki(existingHandlers({
            findNotes: () => [555, 999],
            notesInfo: () => [
                {noteId: 555, modelName: 'Basic'},
                {noteId: 999, modelName: ANKI_NOTE_TYPE},
            ],
        }));
        const noteFields = buildNoteFields(WORD_DATA, {nativeLanguage: 'ru'});

        await expect(sendAnkiNote(config(), noteFields, {word: 'melted'}))
            .resolves.toEqual({added: false, noteId: 999});
    });

    it('sends a second word with find + add only (memoized ensure)', async () => {
        stubAnki(existingHandlers({
            findNotes: () => [],
            addNote: () => 1714055939625,
        }));
        const cfg = config();

        await sendAnkiNote(cfg, buildNoteFields(WORD_DATA, {tags: [], nativeLanguage: 'ru'}), {tags: [], word: 'melted'});
        const afterFirst = fetchMock.mock.calls.length;

        const other = buildNoteFields({...WORD_DATA, word: 'molt'}, {tags: [], nativeLanguage: 'ru'});
        await expect(sendAnkiNote(cfg, other, {tags: [], word: 'molt'}))
            .resolves.toEqual({added: true, noteId: 1714055939625});

        expect(actionsOf(afterFirst)).toEqual(['findNotes', 'addNote']);
        expect(JSON.parse(fetchMock.mock.calls[afterFirst][1].body).params.query).toBe('deck:"ExtApp" Word:"molt" Language:"en"');
    });

    it('refuses to send without the raw word — before any fetch', async () => {
        stubAnki(existingHandlers());

        const error = await sendAnkiNote(config(), {Language: 'en'}, {tags: []}).catch((caught) => caught);
        expect(error).toBeInstanceOf(AnkiConnectError);
        expect(error.kind).toBe('anki');
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
