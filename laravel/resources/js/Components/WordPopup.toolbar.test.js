import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The word popup's action toolbar (ADR 0066 source pin): components sit
// outside vitest's behavioral reach, so the toolbar's structure is pinned
// the way WordPopup.ankiSend.test.js pins the send — assertions on the JSX
// source. What it locks (popup-ai-enrich ticket 01): NO tab strip — the
// dictionary content always renders; the toolbar's single row is "I know" /
// "Remove mark" on the left, then pushed right, in order, Anki, the
// Explanation on-demand toggle, the Ask-AI disabled placeholder, and the
// robot (models-used) icon rightmost. A hairline closes the header block
// (word / familiarity / frequency / form-of) and the Explanation pane mounts
// as the FIRST row of the dictionary view behind the same hairline — nothing
// loads until the icon's first click, the page-lifetime cache survives
// hide/re-show, and `explanation: false` removes the toggle (and the robot)
// entirely (ADR 0046 keys keep their meaning). All icons disable rather than
// disappear when inapplicable (the `progress_actions` gate covers only the
// two progress icons), tooltips and aria-labels come from the word.* /
// ai.* ui-strings, and the glyphs come from the shared line-art set in
// Components/icons.jsx (currentColor, no own color). The profile preview's
// inertness (no handlers reach PopupContent) is pinned on PopupsSettings.jsx.

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
        // disabled only by its own state, the other two never disable through
        // a visibility toggle.
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
        expect(buttonIn('word-popup-ask-ai')).toContain('<Icon name="sparkles"');
        for (const name of ['checkCircle', 'minusCircle', 'ankiAdd', 'ankiUpdate', 'chatBubble', 'sparkles']) {
            expect(iconsSource).toMatch(new RegExp(`^    ${name}: '`, 'm'));
        }
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

    it('asks live — the ticket 04 icon never hides and only disables, per state', () => {
        // The live enrichment (popup-ai-enrich ticket 04): the icon renders
        // always and disables when inapplicable — no handler (the profile
        // preview's inertness), a send in flight, or the word already asked
        // (the succeeded ledger lock — the grey icon's click is a no-op).
        const askAi = buttonIn('word-popup-ask-ai');
        expect(askAi).toContain('onClick={onAskAi}');
        expect(askAi).toMatch(/disabled=\{[^}]*onAskAi == null/);
        expect(askAi).toMatch(/disabled=\{[^}]*aiBusy/);
        expect(askAi).toMatch(/disabled=\{[^}]*data\.ai_asked/);
        expect(askAi).not.toMatch(/\n\s+disabled\n/);
    });

    it('swaps the ask icon label between ask / sending / already asked', () => {
        // One hoisted label const drives tooltip + aria (the Anki pattern):
        // the succeeded lock reads "already asked", a send in flight reads
        // "sending", the default is the plain Ask AI.
        const askAi = buttonIn('word-popup-ask-ai');
        expect(askAi).toContain('title={askAiLabel}');
        expect(askAi).toContain('aria-label={askAiLabel}');
        const label = popupSource.match(/const askAiLabel =[\s\S]*?data\.ai_asked\s*\?\s*t\('word\.ask_ai_already'\)\s*:\s*\(aiBusy \? t\('word\.ask_ai_sending'\) : t\('word\.ask_ai'\)\)/);
        expect(label).toHaveLength(1);
    });

    it('shows a failed ask beneath the toolbar — never silently, with the Retry control', () => {
        // The toolbar mounts the shared AskAiError render (the stable
        // word-popup-ask-ai-error hook lives on the component itself,
        // pinned below): the line carries the server's guidance verbatim
        // (no key / no model / already asked / in progress / throttled)
        // plus the four-state contract's Retry control, in both popup
        // bodies.
        expect(toolbar).toContain('<AskAiError');
        expect(toolbar).toContain('onRetry={onAskAi}');
        const errorComponent = popupSource.slice(
            popupSource.indexOf('function AskAiError'),
            popupSource.indexOf('export function MissingPopupContent'),
        );
        expect(errorComponent).toContain('word-popup-ask-ai-retry');
        expect(errorComponent).toContain("t('word.ask_ai_retry')");
        expect(errorComponent).toContain('onClick={onRetry}');
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

    it('toggles the Explanation pane: pressed state, click to show, click to hide', () => {
        const toggle = buttonIn('word-popup-explain-toggle');
        // Pressed semantics travel to assistive tech…
        expect(toggle).toContain('aria-pressed={explainShown}');
        // …one click flips the flag either way.
        expect(toggle).toContain('onClick={() => setExplainShown((shown) => !shown)}');
        // While shown the icon reads as pressed: the shared button class's
        // pressed branch (verdigris accent + persistent tint).
        expect(toggle).toContain('toolbarButtonClass(false, explainShown)');
        const helper = popupSource.slice(popupSource.indexOf('const toolbarButtonClass'));
        const pressedBranch = helper.slice(helper.indexOf('(accent, pressed'), helper.indexOf("'disabled:opacity-40'"));
        expect(pressedBranch).toContain('accent || pressed');
        expect(pressedBranch).toContain('bg-[var(--color-verdigris)]/10');
        expect(pressedBranch).toContain('dark:bg-[var(--color-verdigris-night)]/10');
    });

    it('renders the Anki status lines inside the toolbar strip', () => {
        expect(toolbar).toContain('word-popup-anki-added');
        expect(toolbar).toContain('word-popup-anki-updated');
        expect(toolbar).toContain("t('word.anki_updated')");
        // The duplicate line survives ONLY for a foreign-model note (our own
        // notes update in place instead) — "Already in Anki." stays accurate
        // there.
        expect(toolbar).toContain('word-popup-anki-duplicate');
        expect(toolbar).toContain("t('word.anki_already')");
        expect(toolbar).toContain('word-popup-anki-error');
        expect(toolbar).toContain('/profile?tab=anki');
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
        // The succeeded lock's grey icon makes the click a no-op client-side.
        expect(askKnown).toMatch(/if \(!data \|\| aiBusy \|\| data\.ai_asked\)/);
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
        // view.
        expect(popupBody).toMatch(/wordId == null && data == null \? \(/);
    });

    it('guards against stale responses landing on a moved popup', () => {
        // Both completion paths check the ask's origin — the data mirror
        // (a payload the fetch effect already replaced is never adopted)
        // plus the word id / surface / column language the ask started
        // from, which is how the missing card's null-data ask is pinned to
        // its token.
        expect(runEnrich.match(/staleAsk\(origin\)/g)).toHaveLength(2);
        expect(popupBody).toMatch(/dataRef\.current !== origin\.data/);
        expect(popupBody).toMatch(/originRef\.current\.surface !== origin\.surface/);
        expect(popupBody).toMatch(/originRef\.current\.languageCode !== origin\.languageCode/);
    });

    it('resets the ask state when the popup moves to another word', () => {
        // The fetch effect's reset block clears the in-flight flag, the
        // error line and its status for the new word.
        const reset = popupBody.slice(
            popupBody.indexOf("setAnkiExisting('checking');"),
            popupBody.indexOf('fetch(`/words/${wordId}'),
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

describe('Explanation pane as the dictionary view’s first row', () => {
    it('mounts the pane above all POS sections, behind the same hairline', () => {
        const paneAt = dictionary.indexOf('<ExplainPane');
        expect(paneAt, 'the pane renders inside the dictionary view').toBeGreaterThan(-1);
        expect(paneAt, 'the pane precedes every POS section').toBeLessThan(dictionary.indexOf('entries?.map'));
        // The pane sits in its own row, closed by the hairline the POS
        // sections use.
        const row = dictionary.slice(dictionary.indexOf('word-popup-explain-row'), paneAt);
        expect(row).toContain('border-b border-[var(--color-hairline)]');
        expect(row).toContain('dark:border-[var(--color-hairline-night)]');
    });

    it('loads nothing before the first click — the pane mounts only behind the toggle', () => {
        // Pane mount == the auto-ask effect's fire (ExplainPane), so the
        // `explainShown` guard is the whole "no fetch before the click"
        // contract: the flag starts false and only the icon's click flips it.
        expect(dictionary).toMatch(/\{showExplain && explainShown && \(/);
        expect(contentSource).toMatch(/const \[explainShown, setExplainShown\] = useState\(false\)/);
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
});
