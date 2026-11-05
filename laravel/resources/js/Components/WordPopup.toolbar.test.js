import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The word popup's action toolbar (ADR 0066 source pin): components sit
// outside vitest's behavioral reach, so the toolbar's structure is pinned
// the way WordPopup.ankiSend.test.js pins the send — assertions on the JSX
// source. What it locks (popup-ai-enrich ticket 01): NO tab strip — the
// dictionary content always renders; the toolbar's single row is "I know" /
// "Remove mark" on the left, then pushed right, in order, Anki, the
// Explanation on-demand toggle, the live Ask-AI icon, and the
// robot (models-used) icon rightmost — and it is ICONS ONLY: every message
// the icons trigger (Anki outcomes, the ask-AI error, the AI Context
// explanation) lives in the info popup, the absolutely positioned layer that
// floats above the card (word-popup-overhaul ticket 04, pinned in its own
// describe below) so the card's content is byte-stable while messages come
// and go. A hairline closes the header block (word / familiarity /
// frequency / form-of) — nothing loads until the Explanation icon's first
// click, the page-lifetime cache survives hide/re-show, and
// `explanation: false` removes the toggle (and the robot) entirely (ADR 0046
// keys keep their meaning). All icons disable rather than disappear when
// inapplicable (the `progress_actions` gate covers only the two progress
// icons), tooltips and aria-labels come from the word.* / ai.* ui-strings,
// and the glyphs come from the shared line-art set in Components/icons.jsx
// (currentColor, no own color) — their colors ride the shared
// toolbarButtonClass color tokens (popup-provenance ticket 01): the know
// icon verdigris, remove-mark vermilion, the robot info blue, the rest
// neutral grey, the pressed tint verdigris — and the Anki icon is the one
// conditional token: amber iff the on-open reachability probe answers
// (popup-provenance ticket 05, pinned in WordPopup.ankiReachable.test.js).
// The profile preview's inertness (no handlers reach PopupContent) is
// pinned on PopupsSettings.jsx.

const popupSource = readFileSync(new URL('./WordPopup.jsx', import.meta.url), 'utf8');
const iconsSource = readFileSync(new URL('./icons.jsx', import.meta.url), 'utf8');
const previewSource = readFileSync(new URL('../Pages/Profile/PopupsSettings.jsx', import.meta.url), 'utf8');

// PopupContent's body: the exported component the real portal and the
// profile preview share.
const contentStart = popupSource.indexOf('export function PopupContent');
const contentSource = popupSource.slice(contentStart);

// The toolbar block: from the strip's own class hook to the word header
// that follows it — everything between is the first-row JSX.
const toolbarStart = contentSource.indexOf('className="word-popup-actions');
const headerStart = contentSource.indexOf('className="word-popup-header');
const toolbar = contentSource.slice(toolbarStart, headerStart);

// The header block: from its class hook to the dictionary view that follows.
const dictionaryStart = contentSource.indexOf('className="word-popup-dictionary');
const header = contentSource.slice(headerStart, dictionaryStart);
const dictionary = contentSource.slice(dictionaryStart);

// The outer WordPopup's body: the message state (ankiSend, aiError) and the
// info popup's timers/dismiss wiring live there.
const popupBodySource = popupSource.slice(popupSource.indexOf('export default function WordPopup'));

// The info popup layer (word-popup-overhaul ticket 04): the shared render
// every transient message floats in — defined between the ask-AI error
// component and the missing card, so this slice captures the dismiss
// button + the layer itself.
const infoLayer = popupSource.slice(
    popupSource.indexOf('function InfoDismissButton'),
    popupSource.indexOf('export function MissingPopupContent'),
);

// The ask-AI error render proper — its mount moved into the info popup
// (both popup bodies), so the component is pinned on its own slice.
const askAiErrorComponent = popupSource.slice(
    popupSource.indexOf('function AskAiError'),
    popupSource.indexOf('function InfoDismissButton'),
);

// The auto-dismiss timer for the Anki success notices: an effect of the
// outer WordPopup, bounded by the fetch effect's dependency list and the
// resolve-list comment that follows it.
const noticeTimer = popupSource.slice(
    popupSource.indexOf('}, [wordId, lookedUpId, surface, t]);'),
    popupSource.indexOf('// The resolve list'),
);

// The full JSX of one toolbar button, located by its stable class hook.
const buttonIn = (hook, source = toolbar) => {
    const at = source.indexOf(hook);
    expect(at, `${hook} renders in the toolbar`).toBeGreaterThan(-1);
    const start = source.lastIndexOf('<button', at);
    return source.slice(start, source.indexOf('</button>', at));
};

// The hoisted label const the Anki button's title + aria-label share.
const labelConstSource = () => {
    const at = popupSource.indexOf('const ankiActionLabel');
    expect(at, 'ankiActionLabel is hoisted in PopupContent').toBeGreaterThan(-1);
    return popupSource.slice(at, popupSource.indexOf(';', at));
};

describe('WordPopup action toolbar', () => {
    it('is the popup’s first row — the toolbar renders above the word header', () => {
        expect(toolbarStart).toBeGreaterThan(-1);
        expect(headerStart).toBeGreaterThan(toolbarStart);
    });

    it('replaces the bottom footer — no footer row remains', () => {
        expect(popupSource).not.toContain('word-popup-footer');
    });

    it('has no tab strip anywhere — the dictionary content always renders', () => {
        expect(popupSource).not.toContain('word-popup-tabs');
        expect(popupSource).not.toContain('role="tablist"');
        expect(popupSource).not.toContain('tab_dictionary');
        expect(popupSource).not.toContain('tab_explanation');
        // No tab state, no gated dictionary: the old `tab`/`showTabs`/
        // `showDictionary` wiring is gone entirely, and no `&& (` conditional
        // wraps the dictionary div — it renders unconditionally.
        expect(popupSource).not.toMatch(/\bshowTabs\b|\bshowDictionary\b|\bsetTab\b|tab ===/);
        expect(popupSource).not.toMatch(/&& \(\s*<div className="word-popup-dictionary/);
    });

    it('carries the six actions on stable word-popup-* hooks', () => {
        expect(toolbar).toContain('word-popup-progress-known');
        expect(toolbar).toContain('word-popup-progress-reset');
        expect(toolbar).toContain('word-popup-anki-send');
        expect(toolbar).toContain('word-popup-explain-toggle');
        expect(toolbar).toContain('word-popup-ask-ai');
        expect(toolbar).toContain('word-popup-models-button');
    });

    it('orders the row: know, remove, … Anki, Explanation, Ask-AI, Robot — robot rightmost', () => {
        const order = [
            'word-popup-progress-known',
            'word-popup-progress-reset',
            'word-popup-anki-send',
            'word-popup-explain-toggle',
            'word-popup-ask-ai',
            'word-popup-models-button',
        ];
        const at = order.map((hook) => toolbar.indexOf(hook));
        at.forEach((position, index) => expect(position, order[index]).toBeGreaterThan(-1));
        expect([...at].sort((a, b) => a - b)).toEqual(at);
    });

    it('pushes Anki — the first of the right-hand icons — off the left pair with ml-auto', () => {
        expect(buttonIn('word-popup-anki-send')).toContain('ml-auto');
        expect(buttonIn('word-popup-progress-known')).not.toContain('ml-auto');
        expect(buttonIn('word-popup-progress-reset')).not.toContain('ml-auto');
        // Only the group leader carries the push.
        expect(toolbar.match(/ml-auto/g)).toHaveLength(1);
    });

    it('disables — never hides — a progress icon in its inapplicable state', () => {
        // know: the mark is already at the maximum; remove mark: no mark to
        // remove. A busy send or a missing handler (the preview) disables
        // both, exactly like the old footer buttons did.
        expect(buttonIn('word-popup-progress-known')).toMatch(
            /disabled=\{[^}]*familiarity === FAMILIARITY_MAX\}/,
        );
        expect(buttonIn('word-popup-progress-known')).toMatch(/progressBusy \|/);
        expect(buttonIn('word-popup-progress-known')).toMatch(/onProgressAction == null/);
        expect(buttonIn('word-popup-progress-reset')).toMatch(
            /disabled=\{[^}]*familiarity == null\}/,
        );
    });

    it('the progress_actions visibility gate hides only the two progress icons', () => {
        // One gate per progress icon, both inside the toolbar…
        expect(toolbar.match(/sections\.progress_actions !== false/g)).toHaveLength(2);
        // …and the four right-hand icons sit outside them: the Anki icon is
        // disabled only by its own state (no handler, send in flight — grey
        // since the 2026-10-24 quiet probes is COLOR, never a disable),
        // the other two never disable through a visibility toggle.
        const anki = buttonIn('word-popup-anki-send');
        expect(anki).toMatch(/disabled=\{onAnkiSend == null \|\| ankiSend\?\.status === 'sending'\}/);
        expect(anki).not.toContain('sections.');
        expect(buttonIn('word-popup-explain-toggle')).not.toContain('progress_actions');
        expect(buttonIn('word-popup-ask-ai')).not.toContain('sections.');
        expect(buttonIn('word-popup-models-button')).not.toContain('progress_actions');
    });

    it('labels every icon with tooltip + aria from the ui-strings', () => {
        expect(buttonIn('word-popup-progress-known')).toContain("t('word.i_know_this')");
        expect(buttonIn('word-popup-progress-reset')).toContain("t('word.remove_mark')");
        // The Anki button's title AND aria-label read one hoisted label const
        // — the sending/update/add ternary written once, above the toolbar.
        const anki = buttonIn('word-popup-anki-send');
        expect(anki).toContain('title={ankiActionLabel}');
        expect(anki).toContain('aria-label={ankiActionLabel}');
        const label = popupSource.match(/const ankiActionLabel =[\s\S]*?ankiExisting === 'found' \? t\('word\.anki_update'\) : t\('word\.anki_send'\)/);
        expect(label).toHaveLength(1);
        expect(label[0]).toContain("t('word.anki_sending')");
        // The new right-hand icons: the Explanation toggle, the Ask-AI
        // icon, and the robot keep the ai.* model-picker label.
        expect(buttonIn('word-popup-explain-toggle')).toContain("t('word.explain_toggle')");
        expect(buttonIn('word-popup-ask-ai')).toContain('title={askAiLabel}');
        expect(buttonIn('word-popup-ask-ai')).toContain('aria-label={askAiLabel}');
        expect(buttonIn('word-popup-models-button')).toContain("t('ai.models_icon')");
        expect(toolbar.match(/title=/g)).toHaveLength(6);
        expect(toolbar.match(/aria-label=/g)).toHaveLength(6);
    });

    it('draws the glyphs from the shared line-art set — no inline SVG', () => {
        expect(buttonIn('word-popup-progress-known')).toContain('<Icon name="checkCircle"');
        expect(buttonIn('word-popup-progress-reset')).toContain('<Icon name="minusCircle"');
        expect(buttonIn('word-popup-explain-toggle')).toContain('<Icon name="chatBubble"');
        // The ask icon flips between the plain sparkles and the re-ask
        // variant (sparkles inside the refresh ring) on the payload's
        // ai_asked.
        expect(buttonIn('word-popup-ask-ai')).toContain("<Icon name={data.ai_asked ? 'sparklesRefresh' : 'sparkles'}");
        for (const name of ['checkCircle', 'minusCircle', 'ankiAdd', 'chatBubble']) {
            expect(iconsSource).toMatch(new RegExp(`^    ${name}: '`, 'm'));
        }
        // ankiUpdate / sparkles alias their shared path consts (the re-ask
        // ring and glyph re-compose them).
        expect(iconsSource).toMatch(/^ {4}ankiUpdate: ARROW_PATH,/m);
        expect(iconsSource).toMatch(/^ {4}sparkles: SPARKLES_PATH,/m);
        // The re-ask glyph composes the shared sparkles with the shared
        // chasing-arrows ring — a multi-path descriptor value.
        expect(iconsSource).toMatch(/^ {4}sparklesRefresh: \[/m);
        expect(iconsSource).toContain('nonScalingStroke');
    });

    it('flips the Anki icon between its add and update forms on the detection outcome', () => {
        const anki = buttonIn('word-popup-anki-send');
        // found → the update glyph + the shared label const's update wording
        // ("Update in Anki"); checking / absent / unknown-Anki → the add
        // form stays (a silent detection failure renders no error of its
        // own, the spec's "icon stays add form").
        expect(anki).toContain("ankiExisting === 'found' ? 'ankiUpdate' : 'ankiAdd'");
        expect(labelConstSource()).toContain("ankiExisting === 'found' ? t('word.anki_update') : t('word.anki_send')");
    });

    it('asks live — the ticket 04 icon never hides and only disables per state; an asked word stays clickable', () => {
        // The live enrichment (popup-ai-enrich ticket 04, re-ask per the
        // ADR 0082 amendment): the icon renders always and disables when
        // inapplicable — no handler (the profile preview's inertness) or a
        // send in flight. A word already asked is NOT disabled: it shows
        // the re-ask variant and the click re-asks.
        const askAi = buttonIn('word-popup-ask-ai');
        expect(askAi).toContain('onClick={onAskAi}');
        expect(askAi).toMatch(/disabled=\{onAskAi == null \|\| aiBusy\}/);
        expect(askAi).not.toMatch(/\n\s+disabled\n/);
    });

    it('swaps the ask icon label between ask / ask again / sending', () => {
        // One hoisted label const drives tooltip + aria (the Anki pattern):
        // an already-enriched word reads "ask again" (the re-ask variant's
        // first-time hint), a send in flight reads "sending", the default
        // is the plain Ask AI.
        const askAi = buttonIn('word-popup-ask-ai');
        expect(askAi).toContain('title={askAiLabel}');
        expect(askAi).toContain('aria-label={askAiLabel}');
        const label = popupSource.match(/const askAiLabel =[\s\S]*?data\.ai_asked\s*\?\s*t\('word\.ask_ai_again'\)\s*:\s*\(aiBusy \? t\('word\.ask_ai_sending'\) : t\('word\.ask_ai'\)\)/);
        expect(label).toHaveLength(1);
    });

    it('floats a failed ask in the info popup — never silently, with the Retry control', () => {
        // The dictionary card mounts the shared info popup layer with the
        // ask handler (the stable word-popup-ask-ai-error hook lives on the
        // AskAiError component inside it, pinned below): the layer carries
        // the server's guidance verbatim (no key / no model / in progress /
        // throttled) plus the four-state contract's Retry control. The
        // missing card mounts the same layer — its wiring is pinned in
        // WordText.missing.test.js.
        const layerMount = contentSource.slice(
            contentSource.indexOf('<InfoPopup'),
            contentSource.indexOf('word-popup-card'),
        );
        expect(layerMount).toContain('onRetryAskAi={onAskAi}');
        expect(askAiErrorComponent).toContain('word-popup-ask-ai-retry');
        expect(askAiErrorComponent).toContain("t('word.ask_ai_retry')");
        expect(askAiErrorComponent).toContain('onClick={onRetry}');
        // The toolbar keeps only the icon row — nothing renders beneath it.
        expect(toolbar).not.toContain('<AskAiError');
        // The network-level fallback copy lives in the shared runEnrich.
        const askCallback = popupSource.slice(popupSource.indexOf('const runEnrich = (body, origin) => {'));
        expect(askCallback).toContain("t('word.ask_ai_failed'");
    });
    it('gates the Explanation toggle and the robot on the explain payload and ADR 0046 key', () => {
        // One shared gate: a popup without an explain payload renders without
        // both icons (as before), and `explanation: false` — the ADR 0046
        // section-visibility key — removes the toggle icon AND the robot,
        // exactly as it once dropped the whole tab strip.
        expect(contentSource).toMatch(
            /const showExplain = explainProps != null && sections\.explanation !== false;/,
        );
        expect(toolbar.match(/showExplain &&/g)).toHaveLength(2);
    });

    it('disables the toggle on a payload without sentence identity — the AI-answer mount (ADR 0096 amendment)', () => {
        // Toolbar parity without a false promise: the AI-answer mount passes
        // the columns' explain config but no sentence context exists to ask
        // about, so the toggle renders disabled — grey through the shared
        // class's disabled:opacity-40, non-responsive as a disabled button —
        // while the robot beside it stays live.
        expect(contentSource).toMatch(
            /const explainDisabled = explainProps != null && explainProps\.sentenceId == null;/,
        );
        expect(buttonIn('word-popup-explain-toggle')).toContain('disabled={explainDisabled}');
        expect(buttonIn('word-popup-models-button')).not.toContain('disabled=');
    });

    it('toggles the Explanation pane: pressed state, click to show, click to hide', () => {
        const toggle = buttonIn('word-popup-explain-toggle');
        // Pressed semantics travel to assistive tech…
        expect(toggle).toContain('aria-pressed={explainShown}');
        // …one click flips the flag either way.
        expect(toggle).toContain('onClick={() => setExplainShown((shown) => !shown)}');
        // While shown the icon reads as pressed: the shared button class's
        // pressed branch (verdigris accent + persistent tint, whatever token
        // the button passes — the toggle rests neutral).
        expect(toggle).toContain("toolbarButtonClass('neutral', explainShown)");
        const helper = popupSource.slice(popupSource.indexOf('const toolbarButtonClass'));
        const pressedBranch = helper.slice(helper.indexOf("pressed = false"), helper.indexOf("'disabled:opacity-40'"));
        expect(pressedBranch).toContain('TOOLBAR_ACCENTS.verdigris');
        expect(pressedBranch).toContain('bg-[var(--color-verdigris)]/10');
        expect(pressedBranch).toContain('dark:bg-[var(--color-verdigris-night)]/10');
    });

    it('paints the icons per color token — know verdigris, remove-mark vermilion, robot info, the rest neutral', () => {
        // popup-provenance ticket 01: the helper takes a color token, and the
        // token map carries the house hues. Its class strings are full
        // literals — Tailwind's scanner reads this file's text, so
        // concatenation-built names would never compile. "Remove mark" now
        // renders the destructive vermilion and the robot the info blue; the
        // Ask-AI icon keeps the neutral grey, and the Anki icon is the
        // conditional consumer — amber token iff the on-open probe says
        // Anki is reachable, neutral otherwise (ticket 05, its own suite).
        const map = popupSource.slice(popupSource.indexOf('const TOOLBAR_ACCENTS'));
        expect(map).toContain('opacity-80 hover:opacity-100');
        for (const [token, variable] of [
            ['verdigris', '--color-verdigris'],
            ['vermilion', '--color-vermilion'],
            ['amber', '--color-amber'],
            ['info', '--color-info'],
        ]) {
            expect(map).toMatch(new RegExp(`^    ${token}: `, 'm'));
            expect(map).toContain(`text-[var(${variable})]`);
            expect(map).toContain(`dark:text-[var(${variable}-night)]`);
        }
        expect(buttonIn('word-popup-progress-known')).toContain("toolbarButtonClass('verdigris')");
        expect(buttonIn('word-popup-progress-reset')).toContain("toolbarButtonClass('vermilion')");
        expect(buttonIn('word-popup-models-button')).toContain("toolbarButtonClass('info')");
        // Neutral is the signature's default: bare calls keep the old
        // reduced-opacity grey.
        expect(buttonIn('word-popup-ask-ai')).toContain('toolbarButtonClass()');
        expect(buttonIn('word-popup-explain-toggle')).toContain("toolbarButtonClass('neutral'");
        expect(buttonIn('word-popup-anki-send')).toContain("toolbarButtonClass(ankiReachable ? 'amber' : 'neutral')");
    });

    it('floats the Anki status lines in the info popup above the card', () => {
        expect(infoLayer).toContain('word-popup-anki-added');
        expect(infoLayer).toContain('word-popup-anki-updated');
        expect(infoLayer).toContain("t('word.anki_updated')");
        // The duplicate line survives ONLY for a foreign-model note (our own
        // notes update in place instead) — "Already in Anki." stays accurate
        // there.
        expect(infoLayer).toContain('word-popup-anki-duplicate');
        expect(infoLayer).toContain("t('word.anki_already')");
        expect(infoLayer).toContain('word-popup-anki-error');
        expect(infoLayer).toContain('/profile?tab=anki');
        // Gone from the toolbar strip — it keeps only the icon row (the
        // Anki button's own hook stays, of course).
        expect(toolbar).not.toContain('word-popup-anki-added');
        expect(toolbar).not.toContain('word-popup-anki-updated');
        expect(toolbar).not.toContain('word-popup-anki-duplicate');
        expect(toolbar).not.toContain('word-popup-anki-error');
    });

    it('keeps the robot a models-popup opener — never a toggle of its own', () => {
        const robot = buttonIn('word-popup-models-button');
        expect(robot).toContain('onClick={() => onModelsOpenChange?.(true)}');
        expect(robot).toContain('aria-haspopup="dialog"');
        // The modal still mounts behind the same gate.
        expect(contentSource).toMatch(/\{showExplain && \(\s*<ModelsUsedPopup/s);
    });
});

describe('WordPopup header hairline', () => {
    it('closes the header block with the POS sections’ hairline style', () => {
        expect(header).toContain('word-popup-header');
        expect(header).toContain('border-b border-[var(--color-hairline)]');
        expect(header).toContain('dark:border-[var(--color-hairline-night)]');
        // The POS sections keep their existing separators — the same style.
        expect(popupSource).toContain(
            'mt-2.5 border-t border-[var(--color-hairline)] pt-2.5 dark:border-[var(--color-hairline-night)]',
        );
    });
});

describe('The live ask-AI request (popup-ai-enrich tickets 04 + 05)', () => {
    // The outer WordPopup owns `data`; both ask callbacks post through one
    // runEnrich and swap the whole payload in — the enriched/adopted card
    // renders without a reopen.
    const popupBody = popupSource.slice(popupSource.indexOf('export default function WordPopup'));
    const runEnrich = popupBody.slice(
        popupBody.indexOf('const runEnrich = (body, origin) => {'),
        popupBody.indexOf('const askAi = () => {'),
    );
    const askKnown = popupBody.slice(popupBody.indexOf('const askAi = () => {'), popupBody.indexOf('const askAiMissing = () => {'));
    const askMissing = popupBody.slice(popupBody.indexOf('const askAiMissing = () => {'));

    it('POSTs /ai/word-enrich once, with CSRF, from the shared runEnrich', () => {
        expect(runEnrich.match(/fetch\('\/ai\/word-enrich'/g)).toHaveLength(1);
        // The click path carries CSRF exactly like the progress actions.
        expect(runEnrich).toContain('X-CSRF-TOKEN');
    });

    it('the known-word ask posts the bound word id and the popup’s current surface (ticket 04)', () => {
        expect(askKnown).toContain('word_id: data.id');
        // The surface rides along: the enrich response's payload resolves
        // from the same clicked surface the initial GET did, so is_form /
        // form_of survive the live swap.
        expect(askKnown).toMatch(/\{word_id: data\.id, surface\}/);
        // The only client-side guards: a loaded payload and no ask in
        // flight — an already-asked word re-asks (ADR 0082 amendment).
        expect(askKnown).toMatch(/if \(!data \|\| aiBusy\)/);
    });

    it('the missing-card ask posts the raw surface + the column language code (ticket 05)', () => {
        // The server derives the lookup key from the RAW surface — the
        // client never folds or strips.
        expect(askMissing).toContain('{surface, language_code: language.code}');
        // No column language, no ask (the icon disables through the null
        // handler); a popup that already swapped to the dictionary view
        // never re-asks the missing path either.
        expect(askMissing).toMatch(/if \(data != null \|\| aiBusy \|\| language\?\.code == null\)/);
    });

    it('swaps the response payload in like the initial fetch does — no reopen', () => {
        expect(runEnrich).toContain('setData(json.data)');
        // The render branch: the missing card holds until a payload exists,
        // then PopupContent renders it — the transition to the dictionary
        // view. (Ticket 07's lookedUpId leaves the branch the same way: a
        // resolved id loads the payload through the fetch effect.)
        expect(popupBody).toMatch(/wordId == null && lookedUpId == null && data == null \? \(/);
    });

    it('guards against stale responses landing on a moved popup', () => {
        // Both completion paths check the ask's origin — the data mirror
        // (a payload the fetch effect already replaced is never adopted)
        // plus the word id / surface / column language the ask started
        // from, which is how the missing card's null-data ask is pinned to
        // its token. Ticket 07's 409 sync reuses the same guard on its two
        // late-landing refetch paths.
        expect(runEnrich.match(/staleAsk\(origin\)/g)).toHaveLength(4);
        expect(popupBody).toMatch(/dataRef\.current !== origin\.data/);
        expect(popupBody).toMatch(/originRef\.current\.surface !== origin\.surface/);
        expect(popupBody).toMatch(/originRef\.current\.languageCode !== origin\.languageCode/);
    });

    it('resets the ask state when the popup moves to another word', () => {
        // The fetch effect's reset block clears the in-flight flag, the
        // error line and its status for the new word.
        const reset = popupBody.slice(
            popupBody.indexOf("setAnkiExisting('checking');"),
            popupBody.indexOf('fetch(`/words/${fetchId}'),
        );
        expect(reset).toContain('setAiBusy(false)');
        expect(reset).toContain('setAiError(null)');
        expect(reset).toContain('setAiErrorStatus(null)');
    });

    it('handlers act on the adopted word id, not the null missing-mode prop', () => {
        // resolvedWordId: the bound word id for a mapped token, the adopted
        // payload's id after the missing card's swap — progress actions and
        // the explanation request keep working in the transitioned popup.
        expect(popupBody).toMatch(/const resolvedWordId = wordId \?\? data\?\.id \?\? null;/);
        expect(popupBody).toContain('fetch(`/words/${resolvedWordId}/progress`');
        expect(popupBody).toContain('wordId: resolvedWordId');
    });
});

describe('The Context explanation lives in the info popup (word-popup-overhaul ticket 04)', () => {
    it('mounts the pane through the toggle gate into the layer — never in the dictionary', () => {
        // The toggle-on gate travels with the state that owns it: PopupContent
        // passes the pane into the layer's explainSlot only while
        // `explainShown`; the layer carries it behind the stable
        // `word-popup-explain-row` hook above the card.
        expect(contentSource).toMatch(/explainSlot=\{showExplain && explainShown && \(/);
        expect(contentSource).toContain('<ExplainPane explain={explainProps} explainKey={explainKey} preview={preview}/>');
        expect(infoLayer).toContain('word-popup-explain-row');
        // The dictionary view holds only the word family's sections now.
        expect(dictionary).not.toContain('<ExplainPane');
    });

    it('loads nothing before the first click — the pane mounts only behind the toggle', () => {
        // Pane mount == the auto-ask effect's fire (ExplainPane), so the
        // `explainShown` guard is the whole "no fetch before the click"
        // contract: the flag starts false and only the icon's click flips it.
        expect(contentSource).toMatch(/const \[explainShown, setExplainShown\] = useState\(false\)/);
        expect(contentSource).toContain('explainSlot={showExplain && explainShown && (');
    });

    it('reuses the page-lifetime cache on re-show — hide never discards', () => {
        // The cache module stays page-lifetime; nothing in the toggle path
        // clears it, so re-showing a hidden pane renders the memoized state
        // instead of refetching (ExplainPane reads cachedExplanation on mount).
        expect(popupSource).toContain('cachedExplanation(explainKey)');
        expect(popupSource).not.toContain('explainCache.clear()');
    });

    it('hides the pane again when the popup moves to another word', () => {
        // The fresh-payload reset (the old "back to the first tab" effect)
        // flips the toggle off; the cache entry survives for the word's
        // next open.
        const reset = contentSource.slice(
            contentSource.indexOf('useEffect(() => {'),
            contentSource.indexOf('const showExplain'),
        );
        expect(reset).toContain('setExplainShown(false)');
        expect(reset).toContain('}, [data]);');
    });
});

describe('The info popup layer (word-popup-overhaul ticket 04)', () => {
    it('hangs above the card — sibling of the card surface, bottom-anchored, spanning its width', () => {
        // The card surface is overflow-hidden, so the layer cannot nest
        // inside it: it renders as the positioned wrapper's other child,
        // bottom-anchored to the card's top edge with a small gap (mb-2),
        // spanning the card's width — "nests inside the absolutely positioned
        // popup" without ever being clipped by the card.
        expect(infoLayer).toMatch(/`word-popup-info absolute bottom-full left-0 right-0 z-10 mb-2 /);
    });

    it('is styled as the same card surface and scrolls internally when tall', () => {
        expect(infoLayer).toContain('overflow-hidden rounded-md border border-[var(--color-hairline)]');
        expect(infoLayer).toContain('bg-[#FDFBF5] dark:bg-[#1C1915]');
        expect(infoLayer).toContain('shadow-lg shadow-black/20');
        // Tall content scrolls inside the layer, never stretches the card.
        expect(infoLayer).toContain('overflow-y-auto');
    });

    it('clamps into the viewport — a popup opened near the top keeps its info popup visible', () => {
        // The measure reads the positioned wrapper (parentElement — the layer
        // is the wrapper's direct child in both mounts, portal and preview;
        // the rect already accounts for the flip transform) and caps the
        // layer to the space between the viewport's top edge and the card's
        // top edge, floored so a hard-up-against-the-top card keeps a usable
        // strip. Re-measured on resize, listener dropped with the layer.
        const measure = infoLayer.slice(
            infoLayer.indexOf('useLayoutEffect'),
            infoLayer.indexOf('}, []);', infoLayer.indexOf('useLayoutEffect')),
        );
        expect(measure).toContain('parentElement');
        expect(measure).toContain('getBoundingClientRect().top');
        expect(measure).toContain('VIEWPORT_MARGIN');
        expect(measure).toContain('MIN_INFO_HEIGHT');
        expect(measure).toContain("window.addEventListener('resize', measure)");
        expect(measure).toContain("return () => window.removeEventListener('resize', measure)");
    });

    it('stacks concurrent items in one layer — notices, anki error, ask-AI error, explanation', () => {
        // The ticket's listed order: the transient Anki notices, the sticky
        // Anki error, the sticky ask-AI error, the explanation slot — each a
        // conditional child of the items container, so singletons render
        // alone and concurrent items stack behind hairline separators.
        const order = [
            'word-popup-anki-added',
            'word-popup-anki-updated',
            'word-popup-anki-duplicate',
            'word-popup-anki-error',
            '<AskAiError',
            '{explainSlot && (',
        ];
        const at = order.map((hook) => infoLayer.indexOf(hook));
        at.forEach((position, index) => expect(position, order[index]).toBeGreaterThan(-1));
        expect([...at].sort((a, b) => a - b)).toEqual(at);
        // Hairline separators between the stacked items (the card's own
        // hairline token, night variant included).
        expect(infoLayer).toContain('divide-y divide-[var(--color-hairline)]');
        expect(infoLayer).toContain('dark:divide-[var(--color-hairline-night)]');
    });

    it('renders nothing — and costs no layout — when there is no message', () => {
        // The empty layer is a null render, so a quiet popup mounts no box at
        // all; and the layer is absolutely positioned, so while it shows it
        // can never push the card's content around.
        expect(infoLayer).toMatch(/return null;/);
        expect(infoLayer).toMatch(/explainSlot && aiError == null\s*\n\s*&& ankiSend\?\.status !== 'added'/);
    });

    it('auto-dismisses the success notices after ~4s — errors stay sticky', () => {
        // The timer lives beside the send state in the outer WordPopup. The
        // effect's cleanup is the timing guard: ANY ankiSend change — a new
        // send, a manual dismiss, the fetch effect's word-change reset, an
        // unmount — tears the pending timer down before it can fire, so a
        // notice can never outlive its word or swallow a newer one's state.
        expect(popupSource).toContain('const ANKI_NOTICE_LIFETIME_MS = 4000;');
        expect(noticeTimer).toContain('setTimeout(() => setAnkiSend(null), ANKI_NOTICE_LIFETIME_MS)');
        expect(noticeTimer).toContain('return () => clearTimeout(timer);');
        for (const status of ["ankiSend.status !== 'added'", "ankiSend.status !== 'updated'", "ankiSend.status !== 'duplicate'"]) {
            expect(noticeTimer).toContain(status);
        }
        // The error status is deliberately absent from the gate: errors are
        // sticky until their dismiss control (or word change / close).
        expect(noticeTimer).not.toContain("'error'");
    });

    it('sticky items each carry a dismiss control — wired to the owning state', () => {
        // One small shared dismiss button; the successes take none (they
        // auto-dismiss).
        expect(iconsSource).toMatch(/^    xMark: '/m);
        expect(infoLayer).toContain('word-popup-info-dismiss');
        expect(infoLayer).toContain('{onDismissAnki != null && (');
        expect(askAiErrorComponent).toContain('<InfoDismissButton');
        for (const hook of ['word-popup-anki-added', 'word-popup-anki-updated', 'word-popup-anki-duplicate']) {
            const line = infoLayer.slice(infoLayer.indexOf(hook), infoLayer.indexOf('</p>', infoLayer.indexOf(hook)));
            expect(line, hook).not.toContain('InfoDismissButton');
        }
        // The dismiss callbacks clear the message state where it lives — the
        // outer WordPopup — and reach PopupContent as props.
        expect(popupBodySource).toMatch(/const dismissAnkiStatus = \(\) => setAnkiSend\(null\);/);
        expect(popupBodySource).toMatch(/const dismissAskAiError = \(\) => \{\s*setAiError\(null\);\s*setAiErrorStatus\(null\);\s*\};/);
        expect(popupBodySource).toContain('onDismissAnki={dismissAnkiStatus}');
        expect(popupBodySource).toContain('onDismissAskAi={dismissAskAiError}');
    });

    it('the card surface never reflows — the layer renders before it, the card class is unconditional', () => {
        const layerAt = contentSource.indexOf('<InfoPopup');
        const cardAt = contentSource.indexOf('word-popup-card');
        const toolbarAt = contentSource.indexOf('className="word-popup-actions');
        expect(layerAt, 'the layer mounts first, outside the card').toBeGreaterThan(-1);
        expect(cardAt).toBeGreaterThan(layerAt);
        expect(toolbarAt).toBeGreaterThan(cardAt);
        // The card surface div is the shared popupContainerClass plus the
        // wrapper's flex slot — no conditional in the class, so messages
        // coming and going can never reflow the card's content.
        const cardDiv = contentSource.slice(contentSource.lastIndexOf('<div', cardAt), contentSource.indexOf('>', cardAt));
        expect(cardDiv).toContain('word-popup-card ${popupContainerClass} min-h-0 flex-1');
        expect(cardDiv).not.toMatch(/\?|\&\&/);
    });

    it('the missing card gets the same layer — its ask error floats above the card too', () => {
        const missingSource = popupSource.slice(
            popupSource.indexOf('export function MissingPopupContent'),
            popupSource.indexOf('export function PopupContent'),
        );
        expect(missingSource).toContain('<InfoPopup');
        expect(missingSource).toContain('onRetryAskAi={onAskAi}');
        expect(missingSource).toContain('onDismissAskAi={onDismissAskAi}');
    });
});

describe('Popups tab preview', () => {
    it('renders the same toolbar inert — no progress or Anki handlers, no network', () => {
        expect(previewSource).toContain('<PopupContent');
        expect(previewSource).not.toContain('onProgressAction');
        expect(previewSource).not.toContain('onAnkiSend');
        expect(previewSource).not.toContain('ankiSend=');
        // The preview keeps the explain payload + canned-answer flag that
        // make the Explanation toggle render its pane without any request.
        expect(previewSource).toContain('preview');
    });

    it('keeps the preview’s Explanation toggle enabled — the sample payload carries a sentence id', () => {
        // PopupContent disables a sentence-less payload (the AI-answer
        // mount's no-context case, ADR 0096 amendment); the preview stands
        // in for a real clicked column popup, so SAMPLE_EXPLAIN carries one
        // — its pane is the canned answer and never posts either way.
        const sampleSource = readFileSync(new URL('../Pages/Profile/sampleWordData.js', import.meta.url), 'utf8');
        expect(sampleSource).toMatch(/SAMPLE_EXPLAIN = \{[^}]*sentenceId: 1/);
    });

    it('anchors the info popup above the preview card — the wrapper is a positioning context', () => {
        // The preview wrapper mirrors the portal's positioning (minus
        // absolute/z-50): `relative` gives the info popup layer its offset
        // parent, so the canned explanation hangs above the preview card the
        // same way it hangs above the real one.
        expect(previewSource).toMatch(/className="word-popup relative mt-3"/);
        // Message-free stays message-free: no message state, no dismiss
        // handlers threaded into the preview.
        expect(previewSource).not.toContain('onDismissAnki');
        expect(previewSource).not.toContain('onDismissAskAi');
    });
});
