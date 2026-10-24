import {describe, expect, it} from 'vitest';
import {
    ANKI_CARD_NAME,
    ANKI_FIELDS,
    ANKI_NOTE_TYPE,
    buildNoteFields,
    generateCardTemplate,
    noteTypeForLanguage,
    renderCardHtml,
    uiLabels,
} from './ankiTemplate';

// Minimal word payload with the shape the word popup receives (GET
// /words/{word}); two translations in different languages exercise the
// native-language filter without dragging in the full sample fixture.
// Definitions are the payload's {text, labels} objects (popup-usage-labels
// ticket 01) — the note fields below must come out byte-identical to the
// old string shape.
const DATA = {
    word: 'melted',
    language_code: 'en',
    frequency: 2143,
    form_of: ['melt'],
    entries: [
        {
            word_class: 'Verb',
            transcriptions: [{value: 'melt'}],
            definitions: [{text: 'To change from a solid to a liquid state.', labels: []}],
            translations: [
                {word: 'таять', language_code: 'ru'},
                {word: 'таяць', language_code: 'be'},
            ],
            examples: ['The snow melted in the sun.'],
            etymologies: ['From Old English meltan.'],
        },
    ],
};

// A two-entry payload exercising the popup's per-entry sections: a base-word
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
            definitions: [{text: 'To change from a solid to a liquid state.', labels: []}, {text: 'To dissolve.', labels: ['dated']}],
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
            definitions: [{text: 'Changed into liquid form.', labels: []}],
            translations: [],
            examples: [],
            etymologies: [],
        },
    ],
};

// One past the popup's 8-line translations preview cap.
const TEN_TRANSLATIONS = [...Array(10).keys()].map((index) => ({word: `t${index}`, language_code: 'ru'}));

const NAMES = ANKI_FIELDS.map(({name}) => name);

describe('noteTypeForLanguage', () => {
    it('suffixes the language code onto the base note type — one template per language', () => {
        expect(noteTypeForLanguage('en')).toBe('ExtApp Vocabulary ⋅ en');
        expect(noteTypeForLanguage('ru')).toBe('ExtApp Vocabulary ⋅ ru');
        expect(noteTypeForLanguage('be')).toBe('ExtApp Vocabulary ⋅ be');
    });

    it('falls back to the unsuffixed base name for an empty or missing code', () => {
        // Words with unknown/missing language_code resolve to the settings'
        // `default` entry, whose note type is the base name — the legacy
        // type, whose existing notes stay found + updatable.
        expect(noteTypeForLanguage('')).toBe(ANKI_NOTE_TYPE);
        expect(noteTypeForLanguage(null)).toBe(ANKI_NOTE_TYPE);
        expect(noteTypeForLanguage()).toBe(ANKI_NOTE_TYPE);
        expect(noteTypeForLanguage()).toBe('ExtApp Vocabulary');
    });
});

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

    it('carries the thirteen-field note type with Body last (schema v2)', () => {
        expect(NAMES).toHaveLength(13);
        expect(NAMES[NAMES.length - 1]).toBe('Body');
    });

    it('back is header + conditional Body slot + footer — the dictionary lives in the Body value', () => {
        const {back} = generateCardTemplate({});
        expect(back).toContain('<div class="ef-header">');
        expect(back).toContain('{{#Body}}<div class="ef-dictionary">{{Body}}</div>{{/Body}}');
        expect(back).toContain('<div class="ef-footer">');

        // Scalar placeholders only: the flat dictionary fields keep their
        // values (searchable in Anki's browser) but no longer render — the
        // per-entry Body value is the dictionary. The header transcription
        // line is gone with them: transcriptions live per-entry in the body.
        const order = ['Word', 'Familiarity', 'Frequency', 'WordFamily', 'PartOfSpeech', 'Tags', 'Language'];
        const positions = order.map((name) => back.indexOf(`{{${name}}}`));
        expect(positions.every((position) => position >= 0)).toBe(true);
        expect([...positions].sort((a, b) => a - b)).toEqual(positions);

        for (const name of ['Transcription', 'Translations', 'Definitions', 'Examples', 'Etymology']) {
            expect(back).not.toContain(`{{${name}}}`);
        }

        // The slot sits between the header and the footer.
        expect(back.indexOf('{{#Body}}')).toBeGreaterThan(back.indexOf('{{PartOfSpeech}}'));
        expect(back.indexOf('{{#Body}}')).toBeLessThan(back.indexOf('{{Tags}}'));
    });

    it('wraps the literal-prefixed meta lines in Anki-native conditionals', () => {
        const {back} = generateCardTemplate({});
        expect(back).toContain(
            '<div class="ef-field ef-familiarity">{{#Familiarity}}Familiarity: {{Familiarity}}/100{{/Familiarity}}</div>',
        );
        expect(back).toContain(
            '<div class="ef-field ef-frequency">{{#Frequency}}Frequency: #{{Frequency}}{{/Frequency}}</div>',
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

    it('bakes ef-hidden into toggled-off scalar wrappers while the note still carries all thirteen values', () => {
        const {back} = generateCardTemplate({familiarity: false, tags: false});
        expect(back).toContain('class="ef-field ef-familiarity ef-hidden"');
        expect(back).toContain('class="ef-field ef-tags ef-hidden"');
        expect(back).toContain('class="ef-field ef-word"');
        expect(back).not.toContain('class="ef-field ef-word ef-hidden"');

        // The body toggles are SEND-TIME: the master toggle never bakes a
        // hide class — an empty Body value drops the slot instead.
        const bodyOff = generateCardTemplate({body: false});
        expect(bodyOff.back).not.toContain('ef-hidden');
        expect(bodyOff.back).toContain('{{#Body}}');

        // Hide-class semantics (ADR 0077): the scalar toggle is a template
        // class, never a note omission — values always carry every field.
        const note = buildNoteFields(DATA, {tags: ['A2']});
        expect(Object.keys(note)).toEqual(NAMES);
        expect(note.Body).toContain('Definitions (1)');
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
        // between per-entry sections, the section labels and summaries, and
        // the translations expander opening inline into its own line.
        expect(css).toContain('.ef-entry + .ef-entry');
        expect(css).toContain('.ef-entry-label');
        expect(css).toContain('.ef-summary');
        expect(css).toContain('.ef-more { display: inline; }');
        // The Body slot's container and the per-entry lines the value bakes.
        expect(css).toContain('.ef-dictionary');
        expect(css).toContain('.ef-transcriptions');
        expect(css).toContain('.ef-empty');
    });

    it('styles every class the template and the values emit — the CSS↔markup pairing cannot drift', () => {
        // The expander's summary class was once renamed in the values while
        // the CSS kept a `.ef-more .ef-summary` descendant selector: the rule
        // matched nothing and the "+N more…" affordance lost its cursor and
        // marker suppression. Every ef-* class the generated markup carries
        // (template HTML, hide classes, value-side structure — the Body
        // value included) must therefore appear as a class selector in
        // TEMPLATE_CSS.
        const toggled = generateCardTemplate({familiarity: false});
        const structured = buildNoteFields(MULTI, {familiarity: 40, tags: ['A2']});
        const expanded = buildNoteFields({word: 'melt', entries: [{translations: TEN_TRANSLATIONS}]});

        const sources = [toggled.front, toggled.back, ...Object.values(structured), expanded.Translations, expanded.Body];
        const emitted = new Set([...sources.join('\n').matchAll(/class="([^"]+)"/g)]
            .flatMap(([, classes]) => classes.split(/\s+/))
            .filter((cls) => cls.startsWith('ef-')));
        const styled = new Set([...toggled.css.matchAll(/\.([A-Za-z0-9_-]+)/g)].map((match) => match[1]));

        expect([...emitted].filter((cls) => !styled.has(cls))).toEqual([]);
    });
});

describe('buildNoteFields', () => {
    it('builds exactly the thirteen Anki fields in fixed note-type order, Body last', () => {
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

    it('prefixes a labelled sense with its plain-text labels — one label, several, both fields, both tiers', () => {
        // The popup ships {text, labels} objects (popup-usage-labels
        // ticket 01); the note renders the labels as a plain-text prefix
        // before the text (ticket 03) — "(label, label) text", localized
        // through the wording's label resolver. Both display tiers compose
        // identically here: the prefix is study information ("don't
        // memorize this sense unaware"), not a visual demotion, so
        // register-tier dated composes exactly like dimming-tier
        // obsolete/rare, in stored order. Unknown slugs drop — the set is
        // closed, like the popup's chips.
        const note = buildNoteFields({word: 'melt', entries: [{definitions: [
            {text: 'To dissolve.', labels: ['dated']},
            {text: 'To melt down.', labels: ['obsolete', 'rare']},
            {text: 'To fuse.', labels: ['no-such-slug']},
        ]}]});
        expect(note.Definitions).toContain('<li>(dated) To dissolve.</li>');
        expect(note.Definitions).toContain('<li>(obsolete, rare) To melt down.</li>');
        expect(note.Definitions).toContain('<li>To fuse.</li>');
        expect(note.Body).toContain('<li>(dated) To dissolve.</li>');
        expect(note.Body).toContain('<li>(obsolete, rare) To melt down.</li>');
        expect(note.Body).toContain('<li>To fuse.</li>');
    });

    it('leaves no-label lines byte-identical — empty lists, null labels, and plain strings through one door', () => {
        const objects = buildNoteFields({word: 'melt', entries: [{definitions: [
            {text: 'To change from a solid to a liquid state.', labels: []},
            {text: 'To dissolve.', labels: null},
        ]}]});
        const strings = buildNoteFields({word: 'melt', entries: [{definitions: [
            'To change from a solid to a liquid state.',
            'To dissolve.',
        ]}]});
        expect(objects.Definitions).toBe(strings.Definitions);
        expect(objects.Body).toBe(strings.Body);
        expect(objects.Definitions).toBe(
            '<details class="ef-details"><summary class="ef-summary">Definitions (2)</summary>'
            + '<ol class="ef-definitions-list"><li>To change from a solid to a liquid state.</li><li>To dissolve.</li></ol></details>',
        );
    });

    it('labels each flat section "WORD — pos" only when the payload has 2+ entries', () => {
        const note = buildNoteFields(MULTI);

        expect(note.Definitions).toContain('<span class="ef-entry-label">MELT — verb</span>');
        expect(note.Definitions).toContain('<span class="ef-entry-label">MELTED — adjective</span>');
        expect(note.Definitions.match(/<div class="ef-entry">/g)).toHaveLength(2);

        // Only the base-word entry has examples: exactly one labeled block,
        // and no dangling label for the entry with nothing under it.
        expect(note.Examples.match(/<div class="ef-entry">/g)).toHaveLength(1);
        expect(note.Examples).toContain('MELT — verb');
        expect(note.Examples).not.toContain('MELTED — adjective');

        // A single-entry flat section is the popup's simple case: no labels.
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
        // The Body value obeys the same rule: per-entry labels and payload
        // text escape, the trusted placeholder wording does not.
        expect(note.Body).toContain('<span class="ef-entry-label">&lt;I&gt;MELT&lt;/I&gt; — verb</span>');
        expect(note.Body).toContain('<span class="ef-translations">&lt;таять&gt;</span>');
        expect(note.Body).toContain('<li>a &amp; &quot;b&quot; &lt;c&gt;</li>');
    });
});

describe('buildNoteFields Body', () => {
    it('interleaves one section per entry in the popup\'s order — label, translations, transcriptions, sections', () => {
        const body = buildNoteFields(MULTI).Body;

        // Two hairline-separated sections (the CSS styles .ef-entry + .ef-entry).
        expect(body.match(/<div class="ef-entry">/g)).toHaveLength(2);

        const secondStart = body.lastIndexOf('<div class="ef-entry">');
        const first = body.slice(0, secondStart);
        const markers = [
            '<span class="ef-entry-label">MELT — verb</span>',
            '<span class="ef-translations">таять, плавить</span>',
            '<span class="ef-transcriptions">[melt]',
            'Definitions (2)',
            'Examples (1)',
            'Etymology (1)',
        ];
        const positions = markers.map((marker) => first.indexOf(marker));
        expect(positions.every((position) => position >= 0)).toBe(true);
        expect([...positions].sort((a, b) => a - b)).toEqual(positions);

        // The headword's own entry keeps the popup's per-entry order even
        // when part of it is empty: label, placeholder, counted section.
        const second = body.slice(secondStart);
        expect(second).toContain('<span class="ef-entry-label">MELTED — adjective</span>');
        const secondMarkers = [
            '<span class="ef-entry-label">MELTED — adjective</span>',
            'No translations yet',
            'Definitions (1)',
        ];
        const secondPositions = secondMarkers.map((marker) => second.indexOf(marker));
        expect(secondPositions.every((position) => position >= 0)).toBe(true);
        expect([...secondPositions].sort((a, b) => a - b)).toEqual(secondPositions);
    });

    it('gives a single-entry word the popup\'s simple case: one labeled section, no separators', () => {
        const body = buildNoteFields(DATA).Body;
        expect(body.match(/<div class="ef-entry">/g)).toHaveLength(1);
        expect(body).toContain('<span class="ef-entry-label">MELTED — verb</span>');
        expect(body).toContain('<span class="ef-translations">таять, таяць</span>');
        expect(body).toContain('<span class="ef-transcriptions">[melt]</span>');
        expect(body).toContain('<summary class="ef-summary">Definitions (1)</summary>');
        expect(body).toContain('<summary class="ef-summary">Examples (1)</summary>');
        expect(body).toContain('<summary class="ef-summary">Etymology (1)</summary>');
    });

    it('caps per-entry translations at 8 and expands the rest behind "+N more…"', () => {
        const body = buildNoteFields({word: 'melt', entries: [{translations: TEN_TRANSLATIONS}]}).Body;
        // No word_class on the entry: the popup's label is the bare headword.
        expect(body).toBe('<div class="ef-entry">'
            + '<span class="ef-entry-label">MELT</span>\n'
            + '<span class="ef-translations">t0, t1, t2, t3, t4, t5, t6, t7'
            + '<details class="ef-more"><summary class="ef-more-summary">+2 more…</summary>t8, t9</details></span>\n'
            + '<p class="ef-empty">No definitions yet.</p></div>');
    });

    it('brackets per-entry transcriptions and expands the rest behind "+N more…"', () => {
        const body = buildNoteFields(MULTI).Body;
        // The first is bracketed like the popup's collapsed line; the rest
        // open behind the expander, joined with the popup's " · ".
        expect(body)
            .toContain('<span class="ef-transcriptions">[melt]'
                + '<details class="ef-more"><summary class="ef-more-summary">+1 more…</summary> · MELT</details></span>');
        // An entry without transcriptions carries no line at all.
        expect(body.slice(body.lastIndexOf('<div class="ef-entry">'))).not.toContain('ef-transcriptions');
    });

    it('shows the calm placeholders when an entry has no translations or definitions', () => {
        const body = buildNoteFields({word: 'melt', entries: [{word_class: 'Verb'}]}).Body;
        expect(body).toBe('<div class="ef-entry">'
            + '<span class="ef-entry-label">MELT — verb</span>\n'
            + '<p class="ef-empty">No translations yet — automatic translation may still be in progress.</p>\n'
            + '<p class="ef-empty">No definitions yet.</p></div>');
    });

    it('omits a section entire when its send-time toggle is off — placeholder included', () => {
        const body = buildNoteFields(MULTI, {fields: {
            translations: false,
            transcription: false,
            definitions: false,
            examples: false,
            etymology: false,
        }}).Body;
        expect(body).not.toContain('ef-translations');
        expect(body).not.toContain('No translations yet');
        expect(body).not.toContain('ef-transcriptions');
        expect(body).not.toContain('ef-details');
        expect(body).not.toContain('No definitions yet');
        // The labels survive their empty sections — a section is never just
        // a dangling label.
        expect(body).toContain('<span class="ef-entry-label">MELT — verb</span>');
        expect(body).toContain('<span class="ef-entry-label">MELTED — adjective</span>');
    });

    it('shapes only the Body: the flat fields keep their full values and 13-key shape', () => {
        const note = buildNoteFields(MULTI, {fields: {translations: false, etymology: false}});
        expect(Object.keys(note)).toEqual(NAMES);
        expect(note.Translations).toBe('таять, плавить');
        expect(note.Etymology).toContain('Etymology (1)');
        expect(note.Body).not.toContain('ef-translations');
        expect(note.Body).not.toContain('Etymology (1)');
        expect(note.Body).toContain('Definitions (2)');
    });

    it('empties the Body when the master toggle is off — the flat fields still carry everything', () => {
        const note = buildNoteFields(DATA, {fields: {body: false}});
        expect(note.Body).toBe('');
        expect(Object.keys(note)).toEqual(NAMES);
        expect(note.Definitions).toContain('Definitions (1)');
    });

    it('leaves the Body empty when there is nothing to interleave', () => {
        expect(buildNoteFields({word: 'melt', entries: []}).Body).toBe('');
        expect(buildNoteFields({}).Body).toBe('');
    });

    it('takes the placeholder wording from the labels parameter', () => {
        const body = buildNoteFields(
            {word: 'melt', entries: [{word_class: 'Verb'}]},
            {labels: {noTranslations: 'Переводов пока нет.', noDefinitions: 'Определений пока нет.'}},
        ).Body;
        expect(body).toContain('<p class="ef-empty">Переводов пока нет.</p>');
        expect(body).toContain('<p class="ef-empty">Определений пока нет.</p>');
    });
});

describe('uiLabels', () => {
    // A fresh t-spy per probe: each label must reach the app's ui-strings
    // with exactly its key and, where the wording counts, the count.
    const probe = (invoke) => {
        const calls = [];
        const t = (key, params) => (calls.push({key, params}), key);
        const labels = uiLabels(t);
        calls.length = 0;
        invoke(labels);
        return calls;
    };

    it('resolves the value-strings eagerly — they are values, not count functions', () => {
        const calls = [];
        uiLabels((key, params) => (calls.push({key, params}), key));
        expect(calls).toEqual([
            {key: 'word.form_of', params: undefined},
            {key: 'word.no_translations', params: undefined},
            {key: 'word.no_definitions', params: undefined},
        ]);
    });

    it('maps the eight keys to the app\'s word.* ui-strings — the mapping both senders share', () => {
        expect(probe((labels) => labels.definitions(3))).toEqual([{key: 'word.definitions', params: {count: 3}}]);
        expect(probe((labels) => labels.examples(2))).toEqual([{key: 'word.examples', params: {count: 2}}]);
        expect(probe((labels) => labels.etymology(1))).toEqual([{key: 'word.etymology', params: {count: 1}}]);
        expect(probe((labels) => labels.more(5))).toEqual([{key: 'word.more', params: {count: 5}}]);
        expect(probe((labels) => labels.label('obsolete'))).toEqual([{key: 'word.label_obsolete', params: undefined}]);
        expect(probe((labels) => labels.noTranslations)).toEqual([]);
        expect(probe((labels) => labels.noDefinitions)).toEqual([]);
    });

    it('feeds buildNoteFields directly — a localized card without hand-rolled labels', () => {
        const t = (key, params) => (key === 'word.definitions'
            ? `Определения (${params.count})`
            : key === 'word.form_of' ? 'форма слова' : key);
        const note = buildNoteFields(MULTI, {labels: uiLabels(t)});
        expect(note.Definitions).toContain('<summary class="ef-summary">Определения (2)</summary>');
        expect(note.WordFamily).toBe('форма слова melt');
        expect(note.Body).toContain('Определения (2)');
    });

    it('resolves the definition label prefix through the same word.label_* strings the popup chips use', () => {
        const t = (key) => ({'word.label_obsolete': 'устар.', 'word.label_rare': 'редк.'}[key] ?? key);
        const note = buildNoteFields({
            word: 'жизнь',
            entries: [{definitions: [{text: 'Жизнь, быт.', labels: ['obsolete', 'rare']}]}],
        }, {labels: uiLabels(t)});
        expect(note.Definitions).toContain('<li>(устар., редк.) Жизнь, быт.</li>');
        expect(note.Body).toContain('<li>(устар., редк.) Жизнь, быт.</li>');
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
        expect(html).toContain('<span class="ef-footer-sep"> · </span>');
        expect(html).not.toContain('{{#');
        expect(html).not.toContain('{{/');
    });

    it('renders the structured values untouched — labels, details, and expander survive substitution', () => {
        const {back} = generateCardTemplate({});
        const html = renderCardHtml(back, buildNoteFields(MULTI, {familiarity: 40, tags: ['A2']}));
        expect(html).toContain('<span class="ef-entry-label">MELT — verb</span>');
        expect(html).toContain('<details class="ef-details"><summary class="ef-summary">Definitions (2)</summary>');

        const expanded = renderCardHtml(
            back,
            buildNoteFields({word: 'melt', entries: [{translations: TEN_TRANSLATIONS}]}, {tags: ['A2']}),
        );
        expect(expanded)
            .toContain('<details class="ef-more"><summary class="ef-more-summary">+2 more…</summary>t8, t9</details>');
    });

    it('renders a non-empty Body inside the ef-dictionary slot', () => {
        const {back} = generateCardTemplate({});
        const html = renderCardHtml(back, buildNoteFields(DATA));
        expect(html).toContain('<div class="ef-dictionary"><div class="ef-entry">');
        expect(html).toContain('MELTED — verb');
    });

    it('drops the Body slot entire when the body value is empty — header meets footer', () => {
        const {back} = generateCardTemplate({});
        const html = renderCardHtml(back, {...buildNoteFields(DATA), Body: ''});
        expect(html).not.toContain('ef-dictionary');
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
