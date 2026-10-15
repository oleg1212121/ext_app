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
    });

    it('front is exactly the word: a single never-hidden wrapper, no transcription', () => {
        expect(generateCardTemplate({}).front).toBe('<div class="ef-field ef-word">{{Word}}</div>');

        // Toggled-off fields still cannot hide the Front — the word is the
        // recognition cue and the duplicate-search key.
        expect(generateCardTemplate({word: false, transcription: false}).front)
            .toBe('<div class="ef-field ef-word">{{Word}}</div>');
        for (const name of NAMES.filter((name) => name !== 'Word')) {
            expect(generateCardTemplate({}).front).not.toContain(`{{${name}}}`);
        }
    });

    it('back lays the twelve fields out in the popup\'s header/body/footer structure', () => {
        const {back} = generateCardTemplate({});
        expect(back).toContain('<div class="ef-header">');
        expect(back).toContain('<div class="ef-body">');
        expect(back).toContain('<div class="ef-footer">');

        // Header: the word title, then the meta lines (Familiarity /
        // Frequency / form-of / part of speech / bracketed transcription).
        // Body: the dictionary sections, translations as the primary line.
        // Footer: the muted Tags · Language line.
        const order = [
            'Word', 'Familiarity', 'Frequency', 'WordFamily', 'PartOfSpeech', 'Transcription',
            'Translations', 'Definitions', 'Examples', 'Etymology',
            'Tags', 'Language',
        ];
        const positions = order.map((name) => back.indexOf(`{{${name}}}`));
        expect(positions.every((position) => position >= 0)).toBe(true);
        expect([...positions].sort((a, b) => a - b)).toEqual(positions);

        expect(back).toContain('<div class="ef-field ef-part_of_speech">{{PartOfSpeech}}</div>');
        expect(back).toContain('<div class="ef-field ef-translations">{{Translations}}</div>');
        expect(back).toContain('<div class="ef-field ef-definitions">{{Definitions}}</div>');
        expect(back).toContain('<div class="ef-field ef-examples">{{Examples}}</div>');
        expect(back).toContain('<div class="ef-field ef-etymology">{{Etymology}}</div>');
        expect(back).toContain('<div class="ef-field ef-tags">{{Tags}}</div>');
        expect(back).toContain('<div class="ef-field ef-language">{{Language}}</div>');
    });

    it('wraps the literal-prefixed meta lines in Anki-native conditionals', () => {
        const {back} = generateCardTemplate({});
        expect(back).toContain(
            '<div class="ef-field ef-familiarity">{{#Familiarity}}Familiarity: {{Familiarity}}/100{{/Familiarity}}</div>',
        );
        expect(back).toContain(
            '<div class="ef-field ef-frequency">{{#Frequency}}Frequency: #{{Frequency}}{{/Frequency}}</div>',
        );
        expect(back).toContain(
            '<div class="ef-field ef-word_family">{{#WordFamily}}form of {{WordFamily}}{{/WordFamily}}</div>',
        );
        expect(back).toContain(
            '<div class="ef-field ef-transcription">{{#Transcription}}[{{Transcription}}]{{/Transcription}}</div>',
        );
    });

    it('joins the footer\'s Tags · Language with a separator that needs both values', () => {
        const {back} = generateCardTemplate({});
        expect(back).toContain('{{#Tags}}{{#Language}}<span class="ef-footer-sep"> · </span>{{/Language}}{{/Tags}}');
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

    it('never hides Word on the front, even toggled off — its back wrapper hides as configured', () => {
        const {front, back} = generateCardTemplate({word: false});
        expect(front).toBe('<div class="ef-field ef-word">{{Word}}</div>');
        expect(back).toContain('<div class="ef-field ef-word ef-hidden">{{Word}}</div>');
    });

    it('styles the surface with the popup palette, type scale, width cap, and local font stacks only', () => {
        const {css} = generateCardTemplate({});
        // The popup's palette, light and Anki-night.
        expect(css).toContain('#FDFBF5');
        expect(css).toContain('#1C1915');
        // The popup's 560px study width, capped and centered.
        expect(css).toContain('max-width: 560px');
        // The serif headword with a local fallback — review must never fetch
        // webfonts.
        expect(css).toContain('"Source Serif 4", Georgia');
        expect(css).not.toMatch(/@import|url\(/);
        // The hide rule the toggles lean on, verbatim.
        expect(css).toContain('.card {');
        expect(css).toContain('.ef-hidden { display: none; }');
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
        expect(html).toBe('<div class="ef-field ef-word">melted</div>');
    });

    it('renders a conditional meta line with its literal prefix when the field carries a value', () => {
        const {back} = generateCardTemplate({});
        const html = renderCardHtml(back, buildNoteFields(DATA, {familiarity: 40, tags: ['A2']}));
        expect(html).toContain('Familiarity: 40/100');
        expect(html).toContain('Frequency: #2143');
        expect(html).toContain('form of melt');
        expect(html).toContain('[melt]');
        expect(html).toContain('<span class="ef-footer-sep"> · </span>');
        expect(html).not.toContain('{{#');
        expect(html).not.toContain('{{/');
    });

    it('drops a conditional line entirely when its field is empty — no dangling prefix', () => {
        const {back} = generateCardTemplate({});
        const note = {...buildNoteFields(DATA, {familiarity: 40, tags: ['A2']}), Frequency: '', WordFamily: ''};
        const html = renderCardHtml(back, note);
        expect(html).not.toContain('Frequency:');
        expect(html).not.toContain('form of');
        expect(html).toContain('Familiarity: 40/100');
    });

    it('keeps a conditional whose value is the string "0" — Anki drops only empty fields', () => {
        const {back} = generateCardTemplate({});
        const html = renderCardHtml(back, {...buildNoteFields(DATA), Familiarity: '0'});
        expect(html).toContain('Familiarity: 0/100');
    });

    it('drops the footer separator unless both Tags and Language carry values', () => {
        const {back} = generateCardTemplate({});

        const both = renderCardHtml(back, buildNoteFields(DATA, {tags: ['A2']}));
        expect(both).toContain('ef-footer-sep');

        const noTags = renderCardHtml(back, {...buildNoteFields(DATA), Tags: ''});
        expect(noTags).not.toContain('ef-footer-sep');
        expect(noTags).toContain('<div class="ef-field ef-language">en</div>');

        const noLanguage = renderCardHtml(back, {...buildNoteFields(DATA, {tags: ['A2']}), Language: ''});
        expect(noLanguage).not.toContain('ef-footer-sep');
        expect(noLanguage).toContain('<div class="ef-field ef-tags">A2</div>');
    });

    it('leaves unknown placeholders and unknown conditional blocks untouched', () => {
        expect(renderCardHtml('{{Unknown}}', {Word: 'melted'})).toBe('{{Unknown}}');
        expect(renderCardHtml('{{#Unknown}}x{{/Unknown}}', {})).toBe('{{#Unknown}}x{{/Unknown}}');
    });
});
