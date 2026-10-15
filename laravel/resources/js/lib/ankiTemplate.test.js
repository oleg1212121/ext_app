import {describe, expect, it} from 'vitest';
import {
    ANKI_CARD_NAME,
    ANKI_FIELDS,
    ANKI_NOTE_TYPE,
    buildNoteFields,
    generateCardTemplate,
    renderCardHtml,
} from './ankiTemplate';

// Minimal word payload with the shape the word popup receives (GET
// /words/{word}); two translations in different languages exercise the
// native-language filter without dragging in the full sample fixture.
const DATA = {
    word: 'melted',
    language_code: 'en',
    frequency: 2143,
    form_of: ['melt'],
    entries: [
        {
            word_class: 'Verb',
            transcriptions: [{value: 'melt'}],
            definitions: ['To change from a solid to a liquid state.'],
            translations: [
                {word: 'таять', language_code: 'ru'},
                {word: 'таяць', language_code: 'be'},
            ],
            examples: ['The snow melted in the sun.'],
            etymologies: ['From Old English meltan.'],
        },
    ],
};

const NAMES = ANKI_FIELDS.map(({name}) => name);

describe('generateCardTemplate', () => {
    it('is byte-deterministic — same toggles always produce identical artifacts', () => {
        const first = generateCardTemplate({tags: false});
        const second = generateCardTemplate({tags: false});
        expect(second).toEqual(first);
        expect(second.front).toBe(first.front);
        expect(second.back).toBe(first.back);
        expect(second.css).toBe(first.css);
        expect(first.css).toContain('.card {');
        expect(first.css).toContain('.ef-hidden { display: none; }');
    });

    it('front carries only Word and Transcription', () => {
        const {front} = generateCardTemplate({});
        const lines = front.split('\n');
        expect(lines).toHaveLength(2);
        expect(lines[0]).toBe('<div class="ef-field ef-word">{{Word}}</div>');
        expect(lines[1]).toBe('<div class="ef-field ef-transcription">{{Transcription}}</div>');
        for (const name of NAMES.filter((name) => name !== 'Word' && name !== 'Transcription')) {
            expect(front).not.toContain(`{{${name}}}`);
        }
    });

    it('bakes ef-hidden into toggled-off back wrappers while the note still carries all twelve values', () => {
        const {back} = generateCardTemplate({definitions: false, examples: false});
        expect(back).toContain('class="ef-field ef-definitions ef-hidden"');
        expect(back).toContain('class="ef-field ef-examples ef-hidden"');
        expect(back).toContain('class="ef-field ef-word"');
        expect(back).not.toContain('class="ef-field ef-word ef-hidden"');

        // Hide-class semantics (ADR 0077): the toggle is a template class,
        // never a note omission — values always carry every field.
        const note = buildNoteFields(DATA, {tags: ['A2']});
        expect(Object.keys(note)).toEqual(NAMES);
        expect(note.Definitions).toBe('To change from a solid to a liquid state.');
    });

    it('never hides Word on the front, even toggled off — other front fields hide as configured', () => {
        const {front, back} = generateCardTemplate({word: false, transcription: false});
        expect(front).toContain('<div class="ef-field ef-word">{{Word}}</div>');
        expect(front).toContain('<div class="ef-field ef-transcription ef-hidden">{{Transcription}}</div>');
        expect(back).toContain('<div class="ef-field ef-word ef-hidden">{{Word}}</div>');
    });
});

describe('buildNoteFields', () => {
    it('builds exactly the twelve Anki fields in fixed note-type order', () => {
        const note = buildNoteFields(DATA, {familiarity: 40, tags: ['A2', 'top-3000'], nativeLanguage: 'ru'});
        expect(Object.keys(note)).toEqual(NAMES);
        expect(note.Word).toBe('melted');
        expect(note.Transcription).toBe('melt');
        expect(note.PartOfSpeech).toBe('Verb');
        expect(note.Frequency).toBe('2143');
        expect(note.Examples).toBe('The snow melted in the sun.');
        expect(note.Etymology).toBe('From Old English meltan.');
        expect(note.WordFamily).toBe('melt');
        expect(note.Tags).toBe('A2, top-3000');
        expect(note.Familiarity).toBe('40');
        expect(note.Language).toBe('en');
        expect(note[ANKI_CARD_NAME]).toBeUndefined();
        expect(ANKI_NOTE_TYPE).toBe('ExtApp Vocabulary');
    });

    it('narrows translations to the native language code', () => {
        expect(buildNoteFields(DATA, {nativeLanguage: 'ru'}).Translations).toBe('таять');
        expect(buildNoteFields(DATA, {nativeLanguage: 'be'}).Translations).toBe('таяць');
    });

    it('falls back to all translations when no translation matches the code', () => {
        expect(buildNoteFields(DATA, {nativeLanguage: 'de'}).Translations).toBe('таять<br>таяць');
        expect(buildNoteFields(DATA, {}).Translations).toBe('таять<br>таяць');
    });

    it('compares language codes — a numeric native_language_id matches nothing (ADR 0077)', () => {
        // Settings store user_settings.native_language_id; passing the id
        // where the code belongs silently degrades to the all-translations
        // fallback instead of erroring — convert at the boundary.
        expect(buildNoteFields(DATA, {nativeLanguage: 1}).Translations).toBe('таять<br>таяць');
    });

    it('renders an empty Tags field for an empty tags payload', () => {
        expect(buildNoteFields(DATA, {tags: []}).Tags).toBe('');
        expect(buildNoteFields(DATA).Tags).toBe('');
    });

    it('escapes HTML in field values', () => {
        const note = buildNoteFields({
            word: '<b>melted</b>',
            language_code: 'en',
            entries: [{definitions: ['a & "b" <c>']}],
        });
        expect(note.Word).toBe('&lt;b&gt;melted&lt;/b&gt;');
        expect(note.Definitions).toBe('a &amp; &quot;b&quot; &lt;c&gt;');
    });
});

describe('renderCardHtml', () => {
    it('substitutes note values for field placeholders the way Anki does at review time', () => {
        const {front} = generateCardTemplate({});
        const html = renderCardHtml(front, buildNoteFields(DATA));
        expect(html).toContain('<div class="ef-field ef-word">melted</div>');
        expect(html).toContain('<div class="ef-field ef-transcription">melt</div>');
    });

    it('leaves unknown placeholders untouched', () => {
        expect(renderCardHtml('{{Unknown}}', {Word: 'melted'})).toBe('{{Unknown}}');
    });
});
