import {describe, expect, it} from 'vitest';
import {buildCardModel, cardStatus, entryMatchesHeadword, sectionLabel} from './popupModel';

// word-card ticket 01 — the word card's derived logic, behaviorally. Every
// derivation here used to live inside Components/WordPopup.jsx, where vitest
// could only lift-and-evaluate a regex-matched expression; buildCardModel
// takes one state object and `t`, so the states those pins approximated are
// asserted directly. Expected values come from the card's established
// behavior (the pre-word-card popup bodies — the loaded card, the missing
// card and the shell's inline loading / error card — that
// Components/WordCard.jsx replaced), not from the model's own code.

// A t() stand-in that keeps the key and the interpolation params visible.
const t = (key, params) => (params ? `t:${key}:${JSON.stringify(params)}` : `t:${key}`);

// The smallest loaded payload: a known headword, nothing optional set.
const DATA = {
    id: 7,
    word: 'melted',
    source: null,
    ai_asked: false,
    frequency: null,
    form_of: [],
    is_form: false,
    entries: [],
};

const loaded = (data = {}, state = {}) => buildCardModel({
    status: 'loaded',
    surface: 'melted',
    data: {...DATA, ...data},
    ...state,
}, t);

const missing = (state = {}) => buildCardModel({status: 'missing', surface: 'melteed', ...state}, t);

describe('the provenance badge (popup-provenance ticket 04)', () => {
    it('shows the importer verbatim', () => {
        expect(loaded({source: 'wiktionary'}).header.sourceBadge).toEqual(['wiktionary']);
    });

    it('trails the importer with an ai part once a succeeded ask exists', () => {
        expect(loaded({source: 'wiktionary', ai_asked: true}).header.sourceBadge).toEqual(['wiktionary', 'ai']);
    });

    it('shows ai alone for an enriched word no importer ever stamped', () => {
        expect(loaded({source: null, ai_asked: true}).header.sourceBadge).toEqual(['ai']);
    });

    it('dedupes an adopt-on-ask mint to one ai part — never "ai · ai"', () => {
        expect(loaded({source: 'ai', ai_asked: true}).header.sourceBadge).toEqual(['ai']);
    });

    it('keeps a foreign importer string honest instead of mapping or dropping it', () => {
        expect(loaded({source: 'some-foreign-importer'}).header.sourceBadge).toEqual(['some-foreign-importer']);
    });

    it('shows no parts at all when neither the importer nor an ask is known', () => {
        expect(loaded({source: null, ai_asked: false}).header.sourceBadge).toEqual([]);
        expect(loaded({source: '', ai_asked: false}).header.sourceBadge).toEqual([]);
    });

    it('never badges the missing card — there is no word behind it', () => {
        expect(missing().header.sourceBadge).toEqual([]);
    });
});

// The fallback order itself is lib/wordFamiliarity.js's effectiveFamiliarity
// (behavioral in lib/wordFamiliarity.test.js); here: the header line reads it.
describe('the header familiarity — the popup-open penalty response wins', () => {
    const line = (value) => ({text: `t:word.familiarity:{"value":${value},"max":100}`});

    it('takes the resolved (post-penalty) familiarity over the opening one', () => {
        expect(loaded({}, {familiarity: 40, resolvedFamiliarity: 35}).header.familiarity).toEqual(line(35));
    });

    it('falls back to the opening familiarity until the penalty answers', () => {
        expect(loaded({}, {familiarity: 40, resolvedFamiliarity: null}).header.familiarity).toEqual(line(40));
    });

    it('keeps a resolved 0 — nullish, not falsy, fallback', () => {
        expect(loaded({}, {familiarity: 40, resolvedFamiliarity: 0}).header.familiarity).toEqual(line(0));
    });

    it('reads 0 for an unmarked word', () => {
        expect(loaded({}, {familiarity: null, resolvedFamiliarity: null}).header.familiarity).toEqual(line(0));
    });
});

describe('the header model', () => {
    it('titles the loaded card with the headword and the missing card with the raw surface', () => {
        expect(loaded().header.title).toBe('melted');
        expect(missing().header.title).toBe('melteed');
    });

    it('renders the familiarity line from the effective value, 0 for an unmarked word', () => {
        expect(loaded({}, {familiarity: 40, resolvedFamiliarity: 35}).header.familiarity)
            .toEqual({text: 't:word.familiarity:{"value":35,"max":100}'});
        expect(loaded().header.familiarity).toEqual({text: 't:word.familiarity:{"value":0,"max":100}'});
    });

    it('drops the familiarity line under the familiarity visibility toggle', () => {
        expect(loaded({}, {sections: {familiarity: false}}).header.familiarity).toBeNull();
    });

    it('renders the frequency rank only when the payload carries one', () => {
        expect(loaded({frequency: 42}).header.frequency).toEqual({text: 't:word.frequency:{"rank":"42"}'});
        expect(loaded({frequency: 0}).header.frequency).toEqual({text: 't:word.frequency:{"rank":"0"}'});
        expect(loaded({frequency: null}).header.frequency).toBeNull();
        expect(loaded({frequency: 42}, {sections: {frequency: false}}).header.frequency).toBeNull();
    });

    it('lists the form-of headwords, falling back to the headword itself for a bare is_form', () => {
        expect(loaded({form_of: ['melt']}).header.formOf).toEqual({
            surface: 'melted',
            label: 't:word.form_of',
            headwords: ['melt'],
        });
        expect(loaded({form_of: [], is_form: true}).header.formOf.headwords).toEqual(['melted']);
    });

    it('drops the form-of line when the word is no form, or under its toggle', () => {
        expect(loaded({form_of: [], is_form: false}).header.formOf).toBeNull();
        expect(loaded({form_of: ['melt']}, {sections: {form_of: false}}).header.formOf).toBeNull();
    });

    it('gives the missing card a title-only header', () => {
        const {header} = missing({familiarity: 40});
        expect(header.familiarity).toBeNull();
        expect(header.frequency).toBeNull();
        expect(header.formOf).toBeNull();
    });
});

describe('the entry list (ADR 0045 sections)', () => {
    // The headword's own section, a base-word section of its family, and a
    // word-less entry (which belongs to the headword).
    const OWN = {id: 1, word: 'melted', word_class: 'Adjective'};
    const BASE = {id: 2, word: 'melt', word_class: 'Verb', labels: ['archaic']};
    const BARE = {id: 3, word: null, word_class: 'Noun'};

    it('labels each section WORD — pos: the headword for its own entries, the base word otherwise', () => {
        const {entries} = loaded({entries: [OWN, BASE, BARE]});
        expect(entries.map((item) => item.label)).toEqual([
            'MELTED — adjective',
            'MELT — verb',
            'MELTED — noun',
        ]);
    });

    it('matches the headword case-insensitively', () => {
        const {entries} = loaded({entries: [{id: 4, word: 'MELTED', word_class: 'Verb'}]});
        expect(entries[0].label).toBe('MELTED — verb');
    });

    it('drops the em dash when the entry has no part of speech', () => {
        const {entries} = loaded({entries: [{id: 5, word: 'melted'}]});
        expect(entries[0].label).toBe('MELTED');
    });

    it('keeps the whole word family in order by default', () => {
        const {entries} = loaded({entries: [OWN, BASE, BARE]});
        expect(entries.map((item) => item.entry)).toEqual([OWN, BASE, BARE]);
    });

    it('keeps only the headword\'s own sections under word_family: false', () => {
        const {entries} = loaded({entries: [OWN, BASE, BARE]}, {sections: {word_family: false}});
        expect(entries.map((item) => item.entry)).toEqual([OWN, BARE]);
    });

    it('carries the word-level usage chips and the dimming mark per section', () => {
        const {entries} = loaded({entries: [OWN, BASE]});
        expect(entries[0].chips).toEqual([]);
        expect(entries[0].dimmed).toBe(false);
        expect(entries[1].chips).toEqual([{text: 't:word.label_archaic', dimming: true}]);
        expect(entries[1].dimmed).toBe(true);
    });

    // The per-sense rows (popup-usage-labels ticket 01, the model's since the
    // word-card review pass): text, chips and the sense's own dim.
    const SENSES = [
        {text: 'to become liquid', labels: []},
        {text: 'to soften', labels: ['obsolete', 'colloquial']},
        {text: 'to disperse', labels: ['dated']},
        {text: 'to fade', labels: null},
    ];

    it('carries each sense\'s text and usage chips — dimming tier and register tier', () => {
        const {entries} = loaded({entries: [{...OWN, definitions: SENSES}]});
        expect(entries[0].definitions.map((definition) => definition.text))
            .toEqual(['to become liquid', 'to soften', 'to disperse', 'to fade']);
        expect(entries[0].definitions.map((definition) => definition.chips)).toEqual([
            [],
            [{text: 't:word.label_obsolete', dimming: true}, {text: 't:word.label_colloquial', dimming: false}],
            [{text: 't:word.label_dated', dimming: false}],
            [],
        ]);
    });

    it('dims a sense carrying any dimming label — never a register-only or unlabelled one', () => {
        const {entries} = loaded({entries: [{...OWN, definitions: SENSES}]});
        expect(entries[0].definitions.map((definition) => definition.dimmed)).toEqual([false, true, false, false]);
    });

    it('never stacks the sense dim inside an already-dimmed section — the section mark wins', () => {
        const {entries} = loaded({entries: [{...BASE, definitions: SENSES}]});
        expect(entries[0].dimmed).toBe(true);
        expect(entries[0].definitions.map((definition) => definition.dimmed)).toEqual([false, false, false, false]);
        // The chips still render — only the extra opacity is skipped.
        expect(entries[0].definitions[1].chips).toHaveLength(2);
    });

    it('lists no senses for an entry without definitions', () => {
        expect(loaded({entries: [OWN]}).entries[0].definitions).toEqual([]);
    });

    it('tolerates a payload without entries', () => {
        expect(loaded({entries: undefined}).entries).toEqual([]);
        expect(loaded({entries: undefined}, {sections: {word_family: false}}).entries).toEqual([]);
    });

    it('lists no sections on the missing card', () => {
        expect(missing().entries).toEqual([]);
    });
});

// word-card ticket 03: the section label is ONE definition — the card's
// entry list and the Anki note builder (lib/ankiTemplate.js, which escapes
// the result for its HTML field values) both read these two exports.
describe('sectionLabel / entryMatchesHeadword — the shared "WORD — pos" heading', () => {
    it('reads an entry as the headword\'s own when its word matches case-insensitively or is absent', () => {
        expect(entryMatchesHeadword({word: 'MELTED'}, 'melted')).toBe(true);
        expect(entryMatchesHeadword({word: null}, 'melted')).toBe(true);
        expect(entryMatchesHeadword({}, 'melted')).toBe(true);
        expect(entryMatchesHeadword({word: 'melt'}, 'melted')).toBe(false);
    });

    it('labels the headword\'s own entry with the headword, a base-word entry with its own word', () => {
        expect(sectionLabel({word: null, word_class: 'Noun'}, 'melted')).toBe('MELTED — noun');
        expect(sectionLabel({word: 'melt', word_class: 'Verb'}, 'melted')).toBe('MELT — verb');
        expect(sectionLabel({word: 'melted'}, 'melted')).toBe('MELTED');
    });

    it('returns the raw text — escaping is the HTML consumer\'s job, React escapes at render', () => {
        expect(sectionLabel({word: '<i>melt</i>', word_class: 'Verb'}, 'melted')).toBe('<I>MELT</I> — verb');
    });
});

// Handler presence is the card's capability set: the shell passes the
// handlers it has, and an absent one disables its icon.
const noop = () => {};
const HANDLERS = {onProgressAction: noop, onAnkiSend: noop, onAnkiProbe: noop, onAskAi: noop};

const button = (model, kind) => model.toolbar.find((descriptor) => descriptor.kind === kind);

describe('the Anki action (popup-provenance ticket 05, the quiet probe)', () => {
    const SETTINGS = {url: 'http://127.0.0.1', port: 8765};
    const anki = (state) => button(loaded({}, {handlers: HANDLERS, ankiSettings: SETTINGS, ...state}), 'anki');

    it('offers the add form, amber, while Anki is reachable and the note absent', () => {
        expect(anki({ankiReachable: true, ankiExisting: 'absent'})).toMatchObject({
            label: 't:word.anki_send',
            title: 't:word.anki_send',
            color: 'amber',
            icon: 'ankiAdd',
            disabled: false,
        });
    });

    it('keeps the add form while the on-open check is still running', () => {
        expect(anki({ankiReachable: true, ankiExisting: 'checking'})).toMatchObject({
            label: 't:word.anki_send',
            icon: 'ankiAdd',
        });
    });

    it('offers the update form once the note was found in the deck', () => {
        expect(anki({ankiReachable: true, ankiExisting: 'found'})).toMatchObject({
            label: 't:word.anki_update',
            color: 'amber',
            icon: 'ankiUpdate',
        });
    });

    it('names the endpoint and stays clickable while the session has heard nothing (unknown)', () => {
        expect(anki({ankiReachable: false, ankiUnknown: true})).toMatchObject({
            label: 't:word.anki_unknown:{"endpoint":"http://127.0.0.1:8765"}',
            color: 'neutral',
            disabled: false,
        });
    });

    it('normalizes the endpoint it names from the settings', () => {
        const settings = {url: 'http://localhost/', port: '9000'};
        expect(anki({ankiSettings: settings, ankiReachable: false, ankiUnknown: true}).label)
            .toBe('t:word.anki_unknown:{"endpoint":"http://localhost:9000"}');
    });

    it('carries the start-Anki guidance, grey but enabled, once a probe or send failed (unreachable)', () => {
        expect(anki({ankiReachable: false, ankiUnknown: false})).toMatchObject({
            label: 't:word.anki_unreachable',
            color: 'neutral',
            disabled: false,
        });
    });

    it('carries the start-Anki guidance with no settings at all, whatever the probe flags say', () => {
        expect(anki({ankiSettings: null, ankiReachable: false})).toMatchObject({
            label: 't:word.anki_unreachable',
            color: 'neutral',
        });
        expect(anki({ankiSettings: null, ankiReachable: true}).label).toBe('t:word.anki_unreachable');
    });

    it('lets a send in flight win the label and disable the icon', () => {
        expect(anki({ankiReachable: true, ankiExisting: 'found', ankiSend: {status: 'sending'}})).toMatchObject({
            label: 't:word.anki_sending',
            disabled: true,
        });
        expect(anki({ankiSettings: null, ankiSend: {status: 'sending'}}).label).toBe('t:word.anki_sending');
    });

    it('re-enables once the send settled, whatever its outcome', () => {
        expect(anki({ankiReachable: true, ankiSend: {status: 'added'}}).disabled).toBe(false);
        expect(anki({ankiReachable: true, ankiSend: {status: 'error', error: 'boom'}}).disabled).toBe(false);
    });

    it('shows the update glyph for a found note even while unreachable — the icon reads the deck state only', () => {
        expect(anki({ankiReachable: false, ankiUnknown: false, ankiExisting: 'found'}).icon).toBe('ankiUpdate');
    });

    it('is disabled without a send handler, and probes on hover only with a probe handler', () => {
        const bare = button(loaded({}, {ankiSettings: SETTINGS, ankiReachable: true}), 'anki');
        expect(bare.disabled).toBe(true);
        expect(bare.hoverProbe).toBe(false);
        expect(anki({ankiReachable: true}).hoverProbe).toBe(true);
    });

    it('defaults to the profile preview\'s look — grey, the no-settings guidance, inert', () => {
        expect(button(loaded(), 'anki')).toMatchObject({
            label: 't:word.anki_unreachable',
            color: 'neutral',
            icon: 'ankiAdd',
            disabled: true,
            hoverProbe: false,
        });
    });
});

const EXPLAIN = {sentenceId: 11, modelKey: 'model', enabled: true};

describe('the loaded toolbar', () => {
    const kinds = (model) => model.toolbar.map((descriptor) => descriptor.kind);
    const full = (data = {}, state = {}) => loaded(data, {handlers: HANDLERS, canAskAi: true, explainProps: EXPLAIN, ...state});

    it('orders the row: know, remove mark, then Anki, the Explanation toggle, Ask-AI, the robot', () => {
        expect(kinds(full())).toEqual(['know', 'remove-mark', 'anki', 'explain', 'ask-ai', 'models']);
    });

    it('drops only the two progress icons under progress_actions: false', () => {
        expect(kinds(full({}, {sections: {progress_actions: false}}))).toEqual(['anki', 'explain', 'ask-ai', 'models']);
    });

    describe('know / remove mark', () => {
        it('wears the verdigris check and the vermilion minus', () => {
            expect(button(full(), 'know')).toMatchObject({
                label: 't:word.i_know_this',
                title: 't:word.i_know_this',
                color: 'verdigris',
                icon: 'checkCircle',
            });
            expect(button(full(), 'remove-mark')).toMatchObject({
                label: 't:word.remove_mark',
                title: 't:word.remove_mark',
                color: 'vermilion',
                icon: 'minusCircle',
            });
        });

        it('enables both for a marked, not-yet-known word', () => {
            const model = full({}, {familiarity: 40});
            expect(button(model, 'know').disabled).toBe(false);
            expect(button(model, 'remove-mark').disabled).toBe(false);
        });

        it('disables know once familiarity is already max — read off the effective value', () => {
            expect(button(full({}, {familiarity: 100}), 'know').disabled).toBe(true);
            expect(button(full({}, {familiarity: 99}), 'know').disabled).toBe(false);
            expect(button(full({}, {familiarity: 40, resolvedFamiliarity: 100}), 'know').disabled).toBe(true);
        });

        it('disables remove mark for an unmarked word, but not for a mark at 0', () => {
            expect(button(full({}, {familiarity: null}), 'remove-mark').disabled).toBe(true);
            expect(button(full({}, {familiarity: 0}), 'remove-mark').disabled).toBe(false);
        });

        it('disables both while a progress action is in flight, or without the handler', () => {
            const busy = full({}, {familiarity: 40, progressBusy: true});
            expect(button(busy, 'know').disabled).toBe(true);
            expect(button(busy, 'remove-mark').disabled).toBe(true);

            const inert = full({}, {familiarity: 40, handlers: {}});
            expect(button(inert, 'know').disabled).toBe(true);
            expect(button(inert, 'remove-mark').disabled).toBe(true);
        });
    });

    describe('the Explanation toggle and the robot', () => {
        it('exist only with an explain payload', () => {
            const model = full({}, {explainProps: null});
            expect(button(model, 'explain')).toBeUndefined();
            expect(button(model, 'models')).toBeUndefined();
            expect(model.explain.visible).toBe(false);
        });

        it('vanish together under the explanation: false key', () => {
            const model = full({}, {sections: {explanation: false}});
            expect(button(model, 'explain')).toBeUndefined();
            expect(button(model, 'models')).toBeUndefined();
            expect(model.explain.visible).toBe(false);
        });

        it('render the toggle enabled and unpressed for a payload with sentence identity', () => {
            const model = full();
            expect(button(model, 'explain')).toMatchObject({
                label: 't:word.explain_toggle',
                title: 't:word.explain_toggle',
                color: 'neutral',
                icon: 'chatBubble',
                disabled: false,
                pressed: false,
            });
            expect(model.explain).toEqual({visible: true, disabled: false, pressed: false});
        });

        it('press the toggle while its pane is shown', () => {
            const model = full({}, {explainShown: true});
            expect(button(model, 'explain').pressed).toBe(true);
            expect(model.explain.pressed).toBe(true);
        });

        it('disable the toggle — but keep the robot live — for a payload without sentence identity', () => {
            const model = full({}, {explainProps: {...EXPLAIN, sentenceId: null}});
            expect(button(model, 'explain').disabled).toBe(true);
            expect(model.explain.disabled).toBe(true);
            expect(button(model, 'models').disabled).toBe(false);
        });

        it('make the robot a live dialog opener in the info accent', () => {
            expect(button(full(), 'models')).toMatchObject({
                label: 't:ai.models_icon',
                title: 't:ai.models_icon',
                color: 'info',
                icon: 'robot',
                disabled: false,
                hasPopup: 'dialog',
            });
        });
    });

    describe('Ask-AI', () => {
        it('is hidden — not disabled — for everyone but approved admins', () => {
            expect(button(full({}, {canAskAi: false}), 'ask-ai')).toBeUndefined();
        });

        it('reads "ask AI" with the plain sparkles by default', () => {
            const model = full();
            expect(button(model, 'ask-ai')).toMatchObject({
                label: 't:word.ask_ai',
                title: 't:word.ask_ai',
                color: 'neutral',
                icon: 'sparkles',
                disabled: false,
            });
        });

        it('reads "sending" and disables while an ask is in flight', () => {
            expect(button(full({}, {aiBusy: true}), 'ask-ai')).toMatchObject({
                label: 't:word.ask_ai_sending',
                disabled: true,
            });
        });

        it('reads "ask again" with the re-ask glyph for an already-enriched word — even mid-flight', () => {
            expect(button(full({ai_asked: true}), 'ask-ai')).toMatchObject({
                label: 't:word.ask_ai_again',
                icon: 'sparklesRefresh',
                disabled: false,
            });
            expect(button(full({ai_asked: true}, {aiBusy: true}), 'ask-ai')).toMatchObject({
                label: 't:word.ask_ai_again',
                disabled: true,
            });
        });

        it('is disabled without the handler', () => {
            expect(button(full({}, {handlers: {}}), 'ask-ai').disabled).toBe(true);
        });

        it('is available exactly when an approved admin has the handler — the loaded card ignores the language', () => {
            expect(full().askAi.available).toBe(true);
            expect(full({}, {languageCode: null}).askAi.available).toBe(true);
            expect(full({}, {canAskAi: false}).askAi.available).toBe(false);
            expect(full({}, {handlers: {}}).askAi.available).toBe(false);
        });
    });
});

describe('the missing card toolbar (deliberate differences, as outputs of data: null)', () => {
    const kinds = (model) => model.toolbar.map((descriptor) => descriptor.kind);
    // The live missing mount: every handler the card could bind, an approved
    // admin, a column language, the explain payload, the lookup settled.
    const live = (state = {}) => missing({
        handlers: HANDLERS,
        canAskAi: true,
        languageCode: 'en',
        explainProps: EXPLAIN,
        ...state,
    });

    it('mirrors the real row in full — the same icons, the same order', () => {
        expect(kinds(live())).toEqual(['know', 'remove-mark', 'anki', 'explain', 'ask-ai', 'models']);
    });

    it('disables the wordless icons — there is no word to act on, whatever handlers arrive', () => {
        const model = live({familiarity: 40});
        expect(button(model, 'know').disabled).toBe(true);
        expect(button(model, 'remove-mark').disabled).toBe(true);
        expect(button(model, 'anki').disabled).toBe(true);
    });

    it('keeps the progress icons\' look and drops them under progress_actions: false', () => {
        expect(button(live(), 'know')).toMatchObject({label: 't:word.i_know_this', color: 'verdigris', icon: 'checkCircle'});
        expect(button(live(), 'remove-mark')).toMatchObject({label: 't:word.remove_mark', color: 'vermilion', icon: 'minusCircle'});
        expect(kinds(live({sections: {progress_actions: false}}))).toEqual(['anki', 'explain', 'ask-ai', 'models']);
    });

    it('shows the Anki icon always neutral, in the add form, with the plain send label and no probe', () => {
        const model = live({
            ankiSettings: {url: 'http://127.0.0.1', port: 8765},
            ankiReachable: true,
            ankiExisting: 'found',
            ankiUnknown: false,
        });
        expect(button(model, 'anki')).toEqual({
            kind: 'anki',
            label: 't:word.anki_send',
            title: 't:word.anki_send',
            color: 'neutral',
            icon: 'ankiAdd',
            disabled: true,
            hoverProbe: false,
        });
    });

    it('renders the Explanation toggle disabled but present, never pressed', () => {
        const model = live({explainShown: true});
        expect(button(model, 'explain')).toMatchObject({disabled: true, pressed: false});
        expect(model.explain).toEqual({visible: true, disabled: true, pressed: false});
    });

    it('drops the toggle and the robot without an explain payload or under explanation: false', () => {
        expect(kinds(live({explainProps: null}))).toEqual(['know', 'remove-mark', 'anki', 'ask-ai']);
        expect(kinds(live({sections: {explanation: false}}))).toEqual(['know', 'remove-mark', 'anki', 'ask-ai']);
    });

    it('keeps the robot live — the Models used popup needs no word', () => {
        expect(button(live(), 'models')).toMatchObject({disabled: false, color: 'info', hasPopup: 'dialog'});
    });

    describe('Ask-AI — the only path from missing to populated', () => {
        it('is live for an approved admin with a column language once the lookup settled', () => {
            const model = live();
            expect(button(model, 'ask-ai')).toMatchObject({
                label: 't:word.ask_ai',
                icon: 'sparkles',
                disabled: false,
            });
            expect(model.askAi.available).toBe(true);
        });

        it('is hidden for everyone but approved admins', () => {
            expect(button(live({canAskAi: false}), 'ask-ai')).toBeUndefined();
            expect(live({canAskAi: false}).askAi.available).toBe(false);
        });

        it('is disabled for a column without a language descriptor', () => {
            const model = live({languageCode: null});
            expect(button(model, 'ask-ai').disabled).toBe(true);
            expect(model.askAi.available).toBe(false);
        });

        it('is disabled while the on-open lookup is still resolving', () => {
            const model = live({resolving: true});
            expect(button(model, 'ask-ai').disabled).toBe(true);
            expect(model.askAi.available).toBe(false);
        });

        it('is disabled without the handler', () => {
            expect(button(live({handlers: {}}), 'ask-ai').disabled).toBe(true);
        });

        it('reads "sending" and disables while an ask is in flight — but stays available for the retry', () => {
            const model = live({aiBusy: true});
            expect(button(model, 'ask-ai')).toMatchObject({label: 't:word.ask_ai_sending', disabled: true});
            expect(model.askAi.available).toBe(true);
        });

        it('never reads "ask again" — there is no payload to have been asked', () => {
            expect(button(live(), 'ask-ai').label).toBe('t:word.ask_ai');
            expect(button(live(), 'ask-ai').icon).toBe('sparkles');
        });
    });
});

describe('the card body line across the four statuses', () => {
    // Everything the shell might hold while the payload is in flight: the
    // pre-payload card must ignore all of it.
    const RICH = {handlers: HANDLERS, canAskAi: true, languageCode: 'en', explainProps: EXPLAIN};

    it('pronounces the missing verdict once the on-open resolve settled', () => {
        expect(missing().bodyLine).toEqual({kind: 'missing', resolving: false, text: 't:word.not_in_dictionary'});
    });

    it('holds the loading line on the missing card while the resolve is in flight', () => {
        expect(missing({resolving: true}).bodyLine).toEqual({kind: 'missing', resolving: true, text: 't:word.loading'});
    });

    it('renders the loading card bare — no toolbar, header, sections, robot or info popup', () => {
        const model = buildCardModel({status: 'loading', surface: 'melted', ...RICH}, t);
        expect(model.bodyLine).toEqual({kind: 'loading', text: 't:word.loading'});
        expect(model.toolbar).toEqual([]);
        expect(model.header).toBeNull();
        expect(model.entries).toEqual([]);
        expect(model.explain.visible).toBe(false);
        expect(model.askAi.available).toBe(false);
        expect(model.notices).toBeNull();
    });

    it('renders the load-error card bare, carrying the error message verbatim', () => {
        const model = buildCardModel({status: 'error', surface: 'melted', error: 'Request failed (HTTP 500)', ...RICH}, t);
        expect(model.bodyLine).toEqual({kind: 'error', text: 'Request failed (HTTP 500)'});
        expect(model.toolbar).toEqual([]);
        expect(model.header).toBeNull();
        expect(model.explain.visible).toBe(false);
        expect(model.notices).toBeNull();
    });

    it('has no body line on the loaded card — the sections are its body', () => {
        expect(loaded().bodyLine).toBeNull();
    });
});

describe('the info popup notices', () => {
    const MESSAGES = {
        ankiSend: {status: 'added'},
        aiStarted: true,
        aiError: 'Rate limited',
        aiErrorStatus: 429,
    };

    it('carry every transient message on the loaded card', () => {
        expect(loaded({}, MESSAGES).notices).toEqual(MESSAGES);
    });

    it('carry only the ask-AI messages on the missing card — it can send nothing to Anki', () => {
        expect(missing(MESSAGES).notices).toEqual({...MESSAGES, ankiSend: null});
    });

    it('default to a quiet layer', () => {
        expect(loaded().notices).toEqual({ankiSend: null, aiStarted: false, aiError: null, aiErrorStatus: null});
    });
});

describe('the Models used popup props — the robot\'s target', () => {
    const LABELLED = {...EXPLAIN, answerLabel: 'Answer model', modelLabel: 'Explain model', followsAnswer: true};

    it('names both models and the explain toggle state from the explain payload', () => {
        expect(loaded({}, {explainProps: LABELLED}).modelsUsed).toEqual({
            enabled: true,
            answerModel: {label: 'Answer model'},
            explanationModel: {label: 'Explain model', followsAnswer: true},
        });
    });

    it('names no model the payload carries no label for, and reads followsAnswer strictly', () => {
        expect(loaded({}, {explainProps: {...EXPLAIN, modelLabel: 'Explain model', followsAnswer: 'yes'}}).modelsUsed)
            .toEqual({enabled: true, answerModel: null, explanationModel: {label: 'Explain model', followsAnswer: false}});
        expect(loaded({}, {explainProps: EXPLAIN}).modelsUsed)
            .toEqual({enabled: true, answerModel: null, explanationModel: null});
    });

    it('reads the explain toggle as on unless explicitly false', () => {
        expect(loaded({}, {explainProps: {...EXPLAIN, enabled: false}}).modelsUsed.enabled).toBe(false);
        expect(loaded({}, {explainProps: {...EXPLAIN, enabled: undefined}}).modelsUsed.enabled).toBe(true);
    });

    it('rides the robot\'s own gate — none without an explain payload, under explanation: false, or on the bare card', () => {
        expect(loaded({}, {explainProps: null}).modelsUsed).toBeNull();
        expect(loaded({}, {explainProps: EXPLAIN, sections: {explanation: false}}).modelsUsed).toBeNull();
        expect(buildCardModel({status: 'loading', explainProps: EXPLAIN}, t).modelsUsed).toBeNull();
    });

    it('stays on the missing card — the Models used popup needs no word', () => {
        expect(missing({explainProps: LABELLED}).modelsUsed).toEqual(loaded({}, {explainProps: LABELLED}).modelsUsed);
    });
});

// word-card review pass: the shell's status selector, a pure lib function —
// the shell only calls it and mounts no card on null.
describe('cardStatus — which status the shell\'s fetch state reads as', () => {
    const PAYLOAD = {id: 7, word: 'melted'};

    it.each([
        // A loaded payload beats every other flag.
        ['loaded beats all', {data: PAYLOAD, wordId: 7, lookedUpId: 9, error: 'boom', loading: true}, 'loaded'],
        ['loaded without any id (the AI-answer mount)', {data: PAYLOAD}, 'loaded'],
        // No id at all — bound or looked up — is the missing card, whatever
        // the stale fetch flags say.
        ['missing', {data: null, wordId: null, lookedUpId: null}, 'missing'],
        ['missing ignores stale fetch flags', {wordId: null, lookedUpId: null, error: 'boom', loading: true}, 'missing'],
        // Once an id exists the pre-payload card shows: the error over a
        // stale loading flag, else the loading line.
        ['error over loading', {wordId: 7, error: 'Request failed', loading: true}, 'error'],
        ['error on a looked-up id', {lookedUpId: 9, error: 'Request failed'}, 'error'],
        ['loading', {wordId: 7, loading: true}, 'loading'],
        ['loading on a looked-up id', {lookedUpId: 9, loading: true}, 'loading'],
        // The one render between a lookup hit and the fetch effect's start.
        ['null gap: an id, nothing loading, no error', {wordId: null, lookedUpId: 9, error: null, loading: false}, null],
        ['null gap on a bound id', {wordId: 7}, null],
    ])('%s', (_name, state, expected) => {
        expect(cardStatus(state)).toBe(expected);
    });
});
