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

// A two-entry payload exercising the popup's per-entry labels: a base-word
// entry ("MELT — verb") plus the headword's own ("MELTED — adjective") —
// the shared sample fixture's shape, kept minimal here.
const MULTI = {
    word: 'melted',
    language_code: 'en',
    form_of: ['melt'],
    entries: [
        {
            word: 'melt',
            word_class: 'Verb',
            transcriptions: [{value: 'melt'}, {value: 'MELT'}],
            definitions: ['To change from a solid to a liquid state.', 'To dissolve.'],
            translations: [
                {word: 'таять', language_code: 'ru'},
                {word: 'плавить', language_code: 'ru'},
            ],
            examples: ['The snow melted in the sun.'],
            etymologies: ['From Old English meltan.'],
        },
        {
            word: 'melted',
            word_class: 'Adjective',
            transcriptions: [],
            definitions: ['Changed into liquid form.'],
            translations: [],
            examples: [],
            etymologies: [],
        },
    ],
};

// One past the popup's 8-line translations preview cap.
const TEN_TRANSLATIONS = [...Array(10).keys()].map((index) => ({word: `t${index}`, language_code: 'ru'}));

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
            '<div class="ef-field ef-transcription">{{#Transcription}}[{{Transcription}}]{{/Transcription}}</div>',
        );
        // The form-of label lives IN the value (buildNoteFields bakes the
        // sender's UI language into it at send time), so WordFamily is a
        // plain placeholder — no template-side literal to double up, and an
        // empty value leaves nothing dangling.
        expect(back).toContain('<div class="ef-field ef-word_family">{{WordFamily}}</div>');
        expect(back).not.toContain('{{#WordFamily}}');
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
        expect(note.Definitions).toContain('<details class="ef-details">');
        expect(note.Definitions).toContain('To change from a solid to a liquid state.');
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
        // The value-side structure's rules: the popup's hairline separators
        // between per-entry blocks, the section labels and summaries, and
        // the translations expander opening inline into its own line.
        expect(css).toContain('.ef-entry + .ef-entry');
        expect(css).toContain('.ef-entry-label');
        expect(css).toContain('.ef-summary');
        expect(css).toContain('.ef-more { display: inline; }');
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
        expect(note.WordFamily).toBe('form of melt');
        expect(note.Tags).toBe('A2, top-3000');
        expect(note.Familiarity).toBe('40');
        expect(note.Language).toBe('en');
        expect(note[ANKI_CARD_NAME]).toBeUndefined();
        expect(ANKI_NOTE_TYPE).toBe('ExtApp Vocabulary');
    });

    it('renders Definitions/Examples/Etymology as the popup\'s counted collapsed sections', () => {
        const note = buildNoteFields(DATA);
        expect(note.Definitions).toBe(
            '<details class="ef-details"><summary class="ef-summary">Definitions (1)</summary>'
            + '<ol class="ef-definitions-list"><li>To change from a solid to a liquid state.</li></ol></details>',
        );
        expect(note.Examples).toBe(
            '<details class="ef-details"><summary class="ef-summary">Examples (1)</summary>'
            + '<ul class="ef-examples-list"><li>The snow melted in the sun.</li></ul></details>',
        );
        expect(note.Etymology).toBe(
            '<details class="ef-details"><summary class="ef-summary">Etymology (1)</summary>'
            + '<p class="ef-etymology-item">From Old English meltan.</p></details>',
        );
    });

    it('leaves a structured section empty when no entry has content for it', () => {
        const note = buildNoteFields({word: 'melt', language_code: 'en', entries: [{word_class: 'Verb'}]});
        expect(note.Definitions).toBe('');
        expect(note.Translations).toBe('');
        expect(note.Examples).toBe('');
        expect(note.Etymology).toBe('');
        expect(note.WordFamily).toBe('');
    });

    it('labels each entry\'s sections "WORD — pos" only when the payload has 2+ entries', () => {
        const note = buildNoteFields(MULTI);

        expect(note.Definitions).toContain('<span class="ef-entry-label">MELT — verb</span>');
        expect(note.Definitions).toContain('<span class="ef-entry-label">MELTED — adjective</span>');
        expect(note.Definitions.match(/<div class="ef-entry">/g)).toHaveLength(2);

        // Only the base-word entry has examples: exactly one labeled block,
        // and no dangling label for the entry with nothing under it.
        expect(note.Examples.match(/<div class="ef-entry">/g)).toHaveLength(1);
        expect(note.Examples).toContain('MELT — verb');
        expect(note.Examples).not.toContain('MELTED — adjective');

        // A single-entry word is the popup's simple case: no labels at all.
        const single = buildNoteFields(DATA);
        expect(single.Definitions).not.toContain('ef-entry');
        expect(single.Examples).not.toContain('ef-entry');
        expect(single.Etymology).not.toContain('ef-entry');
    });

    it('matches an entry\'s word to the headword case-insensitively when labeling', () => {
        const note = buildNoteFields({
            word: 'melted',
            entries: [
                {word: 'MELTED', word_class: 'Noun', definitions: ['a']},
                {word: 'melt', word_class: 'Verb', definitions: ['b']},
            ],
        });
        expect(note.Definitions).toContain('<span class="ef-entry-label">MELTED — noun</span>');
        expect(note.Definitions).toContain('<span class="ef-entry-label">MELT — verb</span>');
    });

    it('previews the first 8 translations, expanding the rest behind "+N more…"', () => {
        const note = buildNoteFields({word: 'melt', entries: [{translations: TEN_TRANSLATIONS}]});
        expect(note.Translations).toBe(
            't0, t1, t2, t3, t4, t5, t6, t7'
            + '<details class="ef-more"><summary class="ef-more-summary">+2 more…</summary>t8, t9</details>',
        );

        // At the cap the value stays a plain joined line.
        const atCap = buildNoteFields({word: 'melt', entries: [{translations: TEN_TRANSLATIONS.slice(0, 8)}]});
        expect(atCap.Translations).toBe('t0, t1, t2, t3, t4, t5, t6, t7');
    });

    it('joins transcriptions with the popup\'s " · " separator', () => {
        expect(buildNoteFields(MULTI).Transcription).toBe('melt · MELT');
        expect(buildNoteFields(DATA).Transcription).toBe('melt');
    });

    it('narrows translations to the native language code', () => {
        expect(buildNoteFields(DATA, {nativeLanguage: 'ru'}).Translations).toBe('таять');
        expect(buildNoteFields(DATA, {nativeLanguage: 'be'}).Translations).toBe('таяць');
    });

    it('falls back to all translations when no translation matches the code', () => {
        expect(buildNoteFields(DATA, {nativeLanguage: 'de'}).Translations).toBe('таять, таяць');
        expect(buildNoteFields(DATA, {}).Translations).toBe('таять, таяць');
    });

    it('compares language codes — a numeric native_language_id matches nothing (ADR 0077)', () => {
        // Settings store user_settings.native_language_id; passing the id
        // where the code belongs silently degrades to the all-translations
        // fallback instead of erroring — convert at the boundary.
        expect(buildNoteFields(DATA, {nativeLanguage: 1}).Translations).toBe('таять, таяць');
    });

    it('renders an empty Tags field for an empty tags payload', () => {
        expect(buildNoteFields(DATA, {tags: []}).Tags).toBe('');
        expect(buildNoteFields(DATA).Tags).toBe('');
    });

    it('takes summary, expander, and form-of wording from the labels parameter', () => {
        const labels = {
            definitions: (count) => `Определения (${count})`,
            examples: (count) => `Примеры (${count})`,
            etymology: (count) => `Этимология (${count})`,
            more: (count) => `ещё ${count}…`,
            formOf: 'форма слова',
        };
        const note = buildNoteFields(MULTI, {labels});
        expect(note.Definitions).toContain('<summary class="ef-summary">Определения (2)</summary>');
        expect(note.Examples).toContain('<summary class="ef-summary">Примеры (1)</summary>');
        expect(note.Etymology).toContain('<summary class="ef-summary">Этимология (1)</summary>');
        expect(note.WordFamily).toBe('форма слова melt');
        expect(buildNoteFields({word: 'melt', entries: [{translations: TEN_TRANSLATIONS}]}, {labels}).Translations)
            .toContain('<summary class="ef-more-summary">ещё 2…</summary>');

        // English defaults when the sender passes nothing.
        const plain = buildNoteFields(DATA);
        expect(plain.Definitions).toContain('Definitions (1)');
        expect(plain.WordFamily).toBe('form of melt');
    });

    it('escapes HTML in field values — payload text, never the trusted label wording', () => {
        const note = buildNoteFields({
            word: '<b>melted</b>',
            language_code: 'en',
            form_of: ['m&elt'],
            entries: [
                {
                    word: '<i>melt</i>',
                    word_class: 'Verb',
                    definitions: ['a & "b" <c>'],
                    translations: [{word: '<таять>'}],
                },
                {word: 'other', word_class: 'Noun', definitions: ['d']},
            ],
        });
        expect(note.Word).toBe('&lt;b&gt;melted&lt;/b&gt;');
        expect(note.Definitions).toContain('<li>a &amp; &quot;b&quot; &lt;c&gt;</li>');
        expect(note.Definitions).toContain('<span class="ef-entry-label">&lt;I&gt;MELT&lt;/I&gt; — verb</span>');
        expect(note.Translations).toContain('&lt;таять&gt;');
        expect(note.WordFamily).toBe('form of m&amp;elt');
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

    it('renders the structured values untouched — labels, details, and expander survive substitution', () => {
        const {back} = generateCardTemplate({});
        const html = renderCardHtml(back, buildNoteFields(MULTI, {familiarity: 40, tags: ['A2']}));
        expect(html).toContain('<span class="ef-entry-label">MELT — verb</span>');
        expect(html).toContain('<details class="ef-details"><summary class="ef-summary">Definitions (2)</summary>');
        expect(html).toContain('[melt · MELT]');

        const expanded = renderCardHtml(
            back,
            buildNoteFields({word: 'melt', entries: [{translations: TEN_TRANSLATIONS}]}, {tags: ['A2']}),
        );
        expect(expanded)
            .toContain('<details class="ef-more"><summary class="ef-more-summary">+2 more…</summary>t8, t9</details>');
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
