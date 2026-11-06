import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The word popup's action toolbar (ADR 0066 source pin): components sit
// outside vitest's behavioral reach, so the toolbar's structure is pinned
// the way WordPopup.ankiSend.test.js pins the send — assertions on the JSX
// source. What it locks (popup-ai-enrich ticket 01): NO tab strip — the
// dictionary content always renders; the toolbar's single row is "I know" /
// "Remove mark" on the left, then pushed right Anki, the Explanation
// on-demand toggle, the live Ask-AI icon, and the robot (models-used) icon
// rightmost — and it is ICONS ONLY: every message the icons trigger (Anki
// outcomes, the ask-AI error, the AI Context explanation) lives in the info
// popup, the absolutely positioned layer that floats above the card
// (word-popup-overhaul ticket 04, pinned in its own describe below) so the
// card's content is byte-stable while messages come and go. A hairline
// closes the header block (word / familiarity / frequency / form-of) —
// nothing loads until the Explanation icon's first click, and the
// page-lifetime cache survives hide/re-show.
//
// Since word-card ticket 02 the card is ONE component, Components/WordCard.jsx,
// rendering every status from lib/popupModel.js's view-model: the toolbar is
// a descriptor list the card maps to buttons (one mapped <button>, the class
// hook and the handler bound by `kind` through one lookup each), so every
// DERIVED fact the old per-button pins matched verbatim — the row order, the
// progress_actions / explanation / admin visibility gates, the disabled
// combinations, the Anki label chain and amber ternary, the ask label and
// icon variants, the per-button color tokens — is asserted behaviorally in
// lib/popupModel.test.js instead. What stays here is the wiring: the hooks,
// the descriptor-to-JSX mapping, the token-to-class map (full Tailwind
// literals), the shell's state/handler hand-over, the info popup layer and
// the profile preview's inertness (pinned on PopupsSettings.jsx).

const popupSource = readFileSync(new URL('./WordPopup.jsx', import.meta.url), 'utf8');
const iconsSource = readFileSync(new URL('./icons.jsx', import.meta.url), 'utf8');
const previewSource = readFileSync(new URL('../Pages/Profile/PopupsSettings.jsx', import.meta.url), 'utf8');

// WordCard: the one card the real portal and the profile preview share.
const contentSource = readFileSync(new URL('./WordCard.jsx', import.meta.url), 'utf8');

// word-card ticket 03: the card's panes live in flat sibling files beside
// the shell — the info popup layer, the ask-AI error (with the shared
// per-item dismiss control it and the layer both mount), the Explanation
// pane (with its page-lifetime cache) — and the shared button class helpers
// in lib/popupStyle.js. The pins below read the file each pinned piece
// lives in now; the pinned strings are unchanged.
const infoPopupSource = readFileSync(new URL('./InfoPopup.jsx', import.meta.url), 'utf8');
const askAiErrorSource = readFileSync(new URL('./AskAiError.jsx', import.meta.url), 'utf8');
const explainPaneSource = readFileSync(new URL('./ExplainPane.jsx', import.meta.url), 'utf8');
const styleSource = readFileSync(new URL('../lib/popupStyle.js', import.meta.url), 'utf8');

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
// every transient message floats in — its own file since word-card ticket
// 03, from the layer component to the end of the file.
const infoLayer = infoPopupSource.slice(infoPopupSource.indexOf('export function InfoPopup'));

// The shared per-item dismiss control: co-located with the ask-AI error,
// which mounts it (the layer imports it from there for the Anki error).
const dismissButton = askAiErrorSource.slice(askAiErrorSource.indexOf('function InfoDismissButton'));

// The ask-AI error render proper — mounted inside the info popup, so the
// component is pinned on its own slice.
const askAiErrorComponent = askAiErrorSource.slice(
    askAiErrorSource.indexOf('function AskAiError'),
    askAiErrorSource.indexOf('function InfoDismissButton'),
);

// The auto-dismiss timer for the Anki success notices: an effect of the
// outer WordPopup, bounded by the fetch effect's dependency list and the
// resolve-list comment that follows it.
const noticeTimer = popupSource.slice(
    popupSource.indexOf('}, [wordId, lookedUpId, surface, t]);'),
    popupSource.indexOf('// The resolve list'),
);

// The one mapped toolbar button: every descriptor renders through it.
const mappedButton = () => {
    const at = toolbar.indexOf('<button');
    expect(at, 'the toolbar maps its descriptors to a button').toBeGreaterThan(-1);
    return toolbar.slice(at, toolbar.indexOf('</button>', at));
};

// The kind → handler lookup the mapped button binds its click through.
const clickLookup = () => {
    const at = contentSource.indexOf('const clickForKind = {');
    expect(at, 'the card binds handlers through one lookup').toBeGreaterThan(-1);
    return contentSource.slice(at, contentSource.indexOf('};', at));
};

// The kind → class-hook lookup.
const hookLookup = () => {
    const at = contentSource.indexOf('const HOOK_FOR_KIND = {');
    expect(at, 'the card maps kinds to class hooks through one lookup').toBeGreaterThan(-1);
    return contentSource.slice(at, contentSource.indexOf('};', at));
};

describe('WordPopup action toolbar', () => {
    it('is the popup’s first row — the toolbar renders above the word header', () => {
        expect(toolbarStart).toBeGreaterThan(-1);
        expect(headerStart).toBeGreaterThan(toolbarStart);
    });

    it('replaces the bottom footer — no footer row remains', () => {
        for (const source of [popupSource, contentSource]) {
            expect(source).not.toContain('word-popup-footer');
        }
    });

    it('has no tab strip anywhere — the dictionary content always renders', () => {
        for (const source of [popupSource, contentSource]) {
            expect(source).not.toContain('word-popup-tabs');
            expect(source).not.toContain('role="tablist"');
            expect(source).not.toContain('tab_dictionary');
            expect(source).not.toContain('tab_explanation');
            // No tab state, no gated dictionary: the old `tab`/`showTabs`/
            // `showDictionary` wiring is gone entirely, and no `&& (`
            // conditional wraps the dictionary div — it renders
            // unconditionally on every card with chrome.
            expect(source).not.toMatch(/\bshowTabs\b|\bshowDictionary\b|\bsetTab\b|tab ===/);
            expect(source).not.toMatch(/&& \(\s*<div className="word-popup-dictionary/);
        }
    });

    it('carries the six actions on stable word-popup-* hooks', () => {
        // One lookup names every hook; the mapped button wears the hook of
        // its descriptor's kind.
        expect(hookLookup()).toContain("know: 'word-popup-progress-known'");
        expect(hookLookup()).toContain("'remove-mark': 'word-popup-progress-reset'");
        expect(hookLookup()).toContain("anki: 'word-popup-anki-send'");
        expect(hookLookup()).toContain("explain: 'word-popup-explain-toggle'");
        expect(hookLookup()).toContain("'ask-ai': 'word-popup-ask-ai'");
        expect(hookLookup()).toContain("models: 'word-popup-models-button'");
        expect(mappedButton()).toContain('${HOOK_FOR_KIND[b.kind]}');
    });

    it('renders the model’s toolbar once — one mapped button, in the descriptors’ own order', () => {
        // The row order, the visibility gates and the disabled states are
        // the model's (lib/popupModel.test.js); the card maps the list it
        // is given, in order, keyed by kind.
        expect(toolbar).toContain('card.toolbar.map((b) => (');
        expect(toolbar.match(/<button/g)).toHaveLength(1);
        expect(mappedButton()).toContain('key={b.kind}');
        // No visibility key is read in the toolbar — the model already
        // dropped what does not render.
        expect(toolbar).not.toContain('sections.');
        expect(toolbar).not.toContain('canAskAi');
    });

    it('pushes Anki — the first of the right-hand icons — off the left pair with ml-auto', () => {
        // Only the group leader carries the push: the anki kind, nothing else.
        expect(mappedButton()).toContain("${b.kind === 'anki' ? ' ml-auto' : ''}");
        expect(toolbar.match(/ml-auto/g)).toHaveLength(1);
    });

    it('labels every icon from its descriptor — tooltip + aria', () => {
        expect(mappedButton()).toContain('title={b.title}');
        expect(mappedButton()).toContain('aria-label={b.label}');
    });

    it('disables per descriptor — never hides inline', () => {
        // The disabled state is the model's verdict per icon (an absent
        // handler is an absent capability the model reads); the card never
        // computes it.
        expect(mappedButton()).toContain('disabled={b.disabled}');
        expect(toolbar).not.toMatch(/disabled=\{[^b]/);
    });

    it('draws the glyphs from the shared line-art set — no inline SVG', () => {
        // The icon token maps to the shared Icon set; the robot token to
        // the Models used popup's own glyph.
        expect(mappedButton()).toContain('<Icon name={b.icon} className="h-[1.25em] w-[1.25em]"/>');
        expect(mappedButton()).toContain("b.icon === 'robot'");
        expect(mappedButton()).toContain('<RobotHelpIcon className="h-[1.25em] w-[1.25em]"/>');
        expect(toolbar).not.toContain('<svg');
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

    it('binds every handler by kind through one lookup', () => {
        expect(mappedButton()).toContain('onClick={clickForKind[b.kind]}');
        const lookup = clickLookup();
        expect(lookup).toContain("know: () => handlers.onProgressAction?.('known')");
        expect(lookup).toContain("'remove-mark': () => handlers.onProgressAction?.('reset')");
        expect(lookup).toContain('anki: handlers.onAnkiSend');
        expect(lookup).toContain("'ask-ai': handlers.onAskAi");
    });

    it('asks live for approved admins only — the gate is the shared lib helper, computed once in the shell', () => {
        // The hide-not-disable rule and the per-state disabling are model
        // output (lib/popupModel.test.js); the gate itself is the shared lib
        // helper (role + is_approved, evaluated behaviorally in
        // lib/authUser.test.js) off the shared auth user, computed once in
        // the outer WordPopup and handed to the one card — the profile
        // preview reads the same helper, so the two surfaces cannot drift.
        expect(popupSource).toContain("from '../lib/authUser'");
        expect(popupBodySource).toContain('canAskAi = isApprovedAdmin(props.auth?.user)');
        expect(previewSource).toContain('canAskAi = isApprovedAdmin(props.auth?.user)');
        expect(popupBodySource.match(/^ {24}canAskAi,$/gm)).toHaveLength(1);
    });

    it('floats a failed ask in the info popup — never silently, with the Retry control', () => {
        // The card mounts the shared info popup layer; its Retry binds to
        // the model's ask availability — the same verdict that enables the
        // icon — so the missing card's admin + language + mid-resolve gate
        // keeps the retry in lockstep with the sparkles icon.
        const layerMount = contentSource.slice(
            contentSource.indexOf('<InfoPopup'),
            contentSource.indexOf('word-popup-card'),
        );
        expect(layerMount).toContain('onRetryAskAi={card.askAi.available ? handlers.onAskAi : null}');
        expect(askAiErrorComponent).toContain('word-popup-ask-ai-retry');
        expect(askAiErrorComponent).toContain("t('word.ask_ai_retry')");
        expect(askAiErrorComponent).toContain('onClick={onRetry}');
        // The toolbar keeps only the icon row — nothing renders beneath it.
        expect(toolbar).not.toContain('<AskAiError');
        // The network-level fallback copy lives in the shared runEnrich.
        const askCallback = popupSource.slice(popupSource.indexOf('const runEnrich = (body, origin) => {'));
        expect(askCallback).toContain("t('word.ask_ai_failed'");
    });

    it('gates the Models used popup on the model’s explain verdict — the robot’s own gate', () => {
        expect(contentSource).toContain('const showExplain = card.explain.visible;');
        expect(contentSource).toMatch(/\{showExplain && \(\s*<ModelsUsedPopup/s);
    });

    it('toggles the Explanation pane: pressed state, click to show, click to hide', () => {
        // Pressed semantics travel to assistive tech from the descriptor…
        expect(mappedButton()).toContain('aria-pressed={b.pressed}');
        // …one click flips the card-owned flag either way.
        expect(clickLookup()).toContain('explain: () => setExplainShown((shown) => !shown)');
        // While shown the icon reads as pressed: the shared button class's
        // pressed branch (verdigris accent + persistent tint, whatever token
        // the button passes — the toggle rests neutral).
        expect(mappedButton()).toContain('toolbarButtonClass(b.color, b.pressed)');
        const helper = styleSource.slice(styleSource.indexOf('const toolbarButtonClass'));
        const pressedBranch = helper.slice(helper.indexOf("pressed = false"), helper.indexOf("'disabled:opacity-40"));
        expect(pressedBranch).toContain('TOOLBAR_ACCENTS.verdigris');
        expect(pressedBranch).toContain('bg-[var(--color-verdigris)]/10');
        expect(pressedBranch).toContain('dark:bg-[var(--color-verdigris-night)]/10');
    });

    it('maps the color tokens to full-literal classes — verdigris, vermilion, amber, info, neutral', () => {
        // popup-provenance ticket 01: the helper takes a color token, and the
        // token map carries the house hues. Its class strings are full
        // literals — Tailwind's scanner reads this file's text, so
        // concatenation-built names would never compile. Which icon wears
        // which token is the model's (lib/popupModel.test.js).
        const map = styleSource.slice(styleSource.indexOf('const TOOLBAR_ACCENTS'));
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
        // Neutral is the signature's default.
        expect(styleSource).toContain("toolbarButtonClass = (color = 'neutral', pressed = false)");
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
        // Gone from the toolbar strip — it keeps only the icon row.
        expect(toolbar).not.toContain('word-popup-anki-added');
        expect(toolbar).not.toContain('word-popup-anki-updated');
        expect(toolbar).not.toContain('word-popup-anki-duplicate');
        expect(toolbar).not.toContain('word-popup-anki-error');
    });

    it('keeps the robot a models-popup opener — never a toggle of its own', () => {
        expect(clickLookup()).toContain('models: () => handlers.onModelsOpenChange?.(true)');
        expect(mappedButton()).toContain('aria-haspopup={b.hasPopup}');
        // The modal mounts behind the same gate and closes through the
        // same handler.
        expect(contentSource).toMatch(/\{showExplain && \(\s*<ModelsUsedPopup/s);
        expect(contentSource).toContain('onClose={() => handlers.onModelsOpenChange?.(false)}');
    });

    it('wires the hover probe from the descriptor — no probe handler, no hover', () => {
        expect(mappedButton()).toContain('onMouseEnter={b.hoverProbe ? handlers.onAnkiProbe : undefined}');
    });
});

describe('WordPopup header hairline', () => {
    it('closes the header block with the POS sections’ hairline style', () => {
        expect(header).toContain('word-popup-header');
        expect(header).toContain('border-b border-[var(--color-hairline)]');
        expect(header).toContain('dark:border-[var(--color-hairline-night)]');
        // The POS sections keep their existing separators — the same style.
        expect(contentSource).toContain(
            'mt-2.5 border-t border-[var(--color-hairline)] pt-2.5 dark:border-[var(--color-hairline-night)]',
        );
    });
});

describe('The shell hands the one card its state and handlers (word-card ticket 02)', () => {
    const mount = popupBodySource.slice(popupBodySource.indexOf('<WordCard'));

    it('mounts one WordCard for every status — the three-branch body is gone', () => {
        expect(popupSource.match(/<WordCard/g)).toHaveLength(1);
        expect(popupSource).not.toContain('PopupContent');
        expect(popupSource).not.toContain('word-popup-card');
        // The status selector is lib/popupModel.js's cardStatus (its
        // statuses table-tested in lib/popupModel.test.js); the shell only
        // calls it on its fetch state and mounts no card on the null gap.
        expect(popupSource).toContain("import {cardStatus} from '../lib/popupModel';");
        expect(popupBodySource).toContain('const status = cardStatus({data, wordId, lookedUpId, error, loading});');
        expect(popupBodySource).toContain('{status != null && (');
    });

    it('passes ONE handlers object — progress, Anki send + probe, ask, both dismissals, models', () => {
        const handlers = mount.slice(mount.indexOf('handlers={{'), mount.indexOf('}}', mount.indexOf('handlers={{')));
        for (const pair of [
            'onProgressAction: changeProgress',
            'onAnkiSend: ankiIconClick',
            'onAnkiProbe: probeAnkiNow',
            'onAskAi: askAiAction',
            'onDismissAnki: dismissAnkiStatus',
            'onDismissAskAi: dismissAskAiError',
            'onModelsOpenChange: setModelsOpen',
        ]) {
            expect(handlers, pair).toContain(pair);
        }
    });

    it('hands the Explanation pane over inert — the card mounts it behind its own toggle', () => {
        expect(mount).toContain('explainPane={explainProps ? <ExplainPane explain={explainProps} explainKey={explainKey}/> : null}');
        expect(mount).toContain('modelsOpen={modelsOpen}');
    });

    it('builds the view-model in the card — never deriving there', () => {
        // The card feeds the shell's state plus its own toggle flag and the
        // handler set through buildCardModel; nothing else derives.
        expect(contentSource).toContain("from '../lib/popupModel'");
        expect(contentSource).toContain('const card = buildCardModel({...state, explainShown, handlers}, t);');
        for (const derived of ['FAMILIARITY_MAX', 'ankiConnectEndpoint', 'entryUsageChips', 'dimsEntry(', 'ai_asked', 'ankiReachable', 'sectionLabel(']) {
            expect(contentSource, derived).not.toContain(derived);
        }
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

    it('answers the queued dispatch with the started notice — never a live swap (ticket 06)', () => {
        // The success path floats the transient "AI enrichment started"
        // notice and keeps the card's current content: the provider call
        // runs in the queue, the enriched content appears the next time
        // the popup opens (ai_asked picks the re-ask icon then).
        const successThen = runEnrich.slice(
            runEnrich.indexOf('.then((json) => {'),
            runEnrich.indexOf('.catch((e) => {'),
        );
        expect(successThen).toContain('setAiStarted(true)');
        expect(successThen).not.toContain('setData(');
        // The 409 sync is byte-identical: the refetch path still swaps a
        // fresh payload in.
        expect(runEnrich).toContain('setData(json.data)');
        // The status selector: the missing card holds until the on-open
        // lookup resolves the adopted word — the queued ask itself swaps
        // nothing. (Ticket 07's lookedUpId leaves the missing status the
        // same way: a resolved id loads the payload through the fetch
        // effect.) The selector's statuses are table-tested on
        // lib/popupModel.js's cardStatus; here: the shell feeds it the
        // looked-up id alongside the bound one.
        expect(popupBody).toContain('const status = cardStatus({data, wordId, lookedUpId, error, loading});');
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
        // error line, its status and the queued ask's started notice for
        // the new word (the missing-mode early return clears the notice
        // too — an ask on a missing token must not leak onto the next).
        const reset = popupBody.slice(
            popupBody.indexOf("setAnkiExisting('checking');"),
            popupBody.indexOf('fetch(`/words/${fetchId}'),
        );
        expect(reset).toContain('setAiBusy(false)');
        expect(reset).toContain('setAiError(null)');
        expect(reset).toContain('setAiErrorStatus(null)');
        expect(reset).toContain('setAiStarted(false)');
        const earlyReturn = popupBody.slice(
            popupBody.indexOf('if (fetchId == null) {'),
            popupBody.indexOf('let cancelled = false;'),
        );
        expect(earlyReturn).toContain('setAiStarted(false)');
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
        // The toggle-on gate travels with the state that owns it: WordCard
        // passes the pane into the layer's explainSlot only while
        // `explainShown`; the layer carries it behind the stable
        // `word-popup-explain-row` hook above the card. The pane element
        // itself is built inert by the mount site (the shell from its
        // explain payload, the profile preview with its canned flag).
        expect(contentSource).toMatch(/explainSlot=\{showExplain && card\.explain\.pressed && \(\s*explainPane\s*\)\}/);
        expect(popupBodySource).toContain('<ExplainPane explain={explainProps} explainKey={explainKey}/>');
        expect(infoLayer).toContain('word-popup-explain-row');
        // The dictionary view holds only the word family's sections now.
        expect(dictionary).not.toContain('<ExplainPane');
        expect(dictionary).not.toContain('explainPane');
    });

    it('loads nothing before the first click — the pane mounts only behind the toggle', () => {
        // Pane mount == the auto-ask effect's fire (ExplainPane), so the
        // `explainShown` flag is the whole "no fetch before the click"
        // contract: it starts false and only the icon's click flips it; the
        // pane gate reads it through the model's pressed verdict
        // (explain.pressed — lib/popupModel.test.js).
        expect(contentSource).toMatch(/const \[explainShown, setExplainShown\] = useState\(false\)/);
        expect(contentSource).toContain('buildCardModel({...state, explainShown, handlers}, t)');
        expect(contentSource).toContain('explainSlot={showExplain && card.explain.pressed && (');
    });

    it('reuses the page-lifetime cache on re-show — hide never discards', () => {
        // The cache module stays page-lifetime; nothing in the toggle path
        // clears it, so re-showing a hidden pane renders the memoized state
        // instead of refetching (ExplainPane reads cachedExplanation on mount).
        expect(explainPaneSource).toContain('cachedExplanation(explainKey)');
        expect(explainPaneSource).not.toContain('explainCache.clear()');
        expect(contentSource).not.toContain('explainCache');
    });

    it('hides the pane again when the popup moves to another word', () => {
        // The fresh-payload reset (the old "back to the first tab" effect)
        // flips the toggle off; the cache entry survives for the word's
        // next open.
        const reset = contentSource.slice(
            contentSource.indexOf('useEffect(() => {'),
            contentSource.indexOf('const card = buildCardModel'),
        );
        expect(reset).toContain('setExplainShown(false)');
        expect(reset).toContain('}, [state.data]);');
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
            'word-popup-ai-started',
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
        expect(infoLayer).toMatch(/explainSlot && aiError == null && !aiStarted\s*\n\s*&& ankiSend\?\.status !== 'added'/);
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
        // The queued ask's "started" notice (word-popup-overhaul ticket 06)
        // rides the same gate + timer: transient like the Anki successes.
        expect(noticeTimer).toContain('setTimeout(() => setAiStarted(false), ANKI_NOTICE_LIFETIME_MS)');
    });

    it('sticky items each carry a dismiss control — wired to the owning state', () => {
        // One small shared dismiss button; the successes take none (they
        // auto-dismiss).
        expect(iconsSource).toMatch(/^ {4}xMark: '/m);
        expect(dismissButton).toContain('word-popup-info-dismiss');
        expect(infoLayer).toContain('{onDismissAnki != null && (');
        expect(askAiErrorComponent).toContain('<InfoDismissButton');
        for (const hook of ['word-popup-anki-added', 'word-popup-anki-updated', 'word-popup-anki-duplicate']) {
            const line = infoLayer.slice(infoLayer.indexOf(hook), infoLayer.indexOf('</p>', infoLayer.indexOf(hook)));
            expect(line, hook).not.toContain('InfoDismissButton');
        }
        // The dismiss callbacks clear the message state where it lives — the
        // outer WordPopup — and reach the card in its one handlers object.
        expect(popupBodySource).toMatch(/const dismissAnkiStatus = \(\) => setAnkiSend\(null\);/);
        expect(popupBodySource).toMatch(/const dismissAskAiError = \(\) => \{\s*setAiError\(null\);\s*setAiErrorStatus\(null\);\s*\};/);
        expect(popupBodySource).toContain('onDismissAnki: dismissAnkiStatus');
        expect(popupBodySource).toContain('onDismissAskAi: dismissAskAiError');
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
        expect(cardDiv).not.toMatch(/\?|&&/);
    });

    it('one mount serves every card — the missing card’s ask messages float above it too', () => {
        // The layer's messages are the model's notices (the missing card
        // carries the ask-AI ones only — it can send nothing to Anki, pinned
        // behaviorally in lib/popupModel.test.js); the bare pre-payload card
        // has none and mounts no layer at all.
        const layerMount = contentSource.slice(
            contentSource.indexOf('{card.notices && ('),
            contentSource.indexOf('word-popup-card'),
        );
        expect(layerMount).toContain('<InfoPopup');
        expect(layerMount).toContain('ankiSend={card.notices.ankiSend}');
        expect(layerMount).toContain('onDismissAnki={handlers.onDismissAnki}');
        expect(layerMount).toContain('aiStarted={card.notices.aiStarted}');
        expect(layerMount).toContain('aiError={card.notices.aiError}');
        expect(layerMount).toContain('aiErrorStatus={card.notices.aiErrorStatus}');
        expect(layerMount).toContain('onDismissAskAi={handlers.onDismissAskAi}');
        expect(contentSource.match(/<InfoPopup/g)).toHaveLength(1);
    });

    it('floats the queued ask’s started notice — transient, verdigris, no dismiss control (ticket 06)', () => {
        expect(infoLayer).toContain('word-popup-ai-started');
        expect(infoLayer).toContain("t('word.ask_ai_started')");
        // Transient: the Anki-success treatment (the verdigris tokens), no
        // dismiss button — the auto-dismiss timer owns it.
        const line = infoLayer.slice(
            infoLayer.indexOf('word-popup-ai-started'),
            infoLayer.indexOf('</p>', infoLayer.indexOf('word-popup-ai-started')),
        );
        expect(line).not.toContain('InfoDismissButton');
        expect(line).toContain('var(--color-verdigris)]');
        expect(line).toContain('var(--color-verdigris-night)]');
    });
});

describe('Popups tab preview', () => {
    it('renders the same card inert — no progress or Anki handlers, no network', () => {
        expect(previewSource).toContain('<WordCard');
        expect(previewSource).not.toContain('<PopupContent');
        // The one handler: the Models used popup's open change. The model
        // reads every other capability as absent — the inert toolbar.
        expect(previewSource).toContain('handlers={{onModelsOpenChange: setModelsOpen}}');
        expect(previewSource).not.toContain('onProgressAction');
        expect(previewSource).not.toContain('onAnkiSend');
        expect(previewSource).not.toContain('ankiSend');
        // The preview keeps the explain payload + canned-answer flag that
        // make the Explanation toggle render its pane without any request
        // (the canned flag is the pane's own `preview` prop — the card
        // model carries no preview field).
        expect(previewSource).not.toContain('preview: true');
        expect(previewSource).toContain('<ExplainPane explain={previewExplain} explainKey="preview" preview/>');
    });

    it('keeps the preview’s Explanation toggle enabled — the sample payload carries a sentence id', () => {
        // The model disables a sentence-less payload (the AI-answer mount's
        // no-context case, ADR 0096 amendment); the preview stands in for a
        // real clicked column popup, so SAMPLE_EXPLAIN carries one — its
        // pane is the canned answer and never posts either way.
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

describe('Flat siblings — WordPopup.jsx is the shell (word-card ticket 03)', () => {
    // The card and its panes live in flat sibling files beside the shell;
    // the tiny helpers live with the component that mounts them.
    it('keeps no pane, helper or explain cache in the shell file', () => {
        for (const moved of [
            'function InfoPopup',
            'function AskAiError',
            'function InfoDismissButton',
            'function ExplainPane',
            'function ExplainGuidance',
            'function CollapsibleLine',
            'explainCache',
            'MIN_INFO_HEIGHT',
        ]) {
            expect(popupSource, moved).not.toContain(moved);
        }
        expect(popupSource).toContain("import {ExplainPane} from './ExplainPane.jsx';");
    });

    it('homes each pane in its own sibling, the helpers beside their consumer', () => {
        expect(infoPopupSource).toContain('export function InfoPopup(');
        expect(askAiErrorSource).toContain('export function AskAiError(');
        expect(askAiErrorSource).toContain('export function InfoDismissButton(');
        expect(explainPaneSource).toContain('export function ExplainPane(');
        expect(explainPaneSource).toContain('function ExplainGuidance(');
        expect(explainPaneSource).toContain('const explainCache = new Map();');
        expect(contentSource).toContain('function CollapsibleLine(');
    });

    it('imports one way — no sibling reads back from the shell, no cycle between panes', () => {
        for (const [name, source] of [
            ['WordCard.jsx', contentSource],
            ['InfoPopup.jsx', infoPopupSource],
            ['AskAiError.jsx', askAiErrorSource],
            ['ExplainPane.jsx', explainPaneSource],
        ]) {
            expect(source, name).not.toContain("from './WordPopup.jsx'");
        }
        expect(contentSource).toContain("import {InfoPopup} from './InfoPopup.jsx';");
        expect(infoPopupSource).toContain("import {AskAiError, InfoDismissButton} from './AskAiError.jsx';");
        expect(askAiErrorSource).not.toContain("from './InfoPopup.jsx'");
    });

    it('exports no class helper from a component file — the button styles come from lib', () => {
        for (const [name, source] of [
            ['WordCard.jsx', contentSource],
            ['InfoPopup.jsx', infoPopupSource],
            ['AskAiError.jsx', askAiErrorSource],
            ['ExplainPane.jsx', explainPaneSource],
            ['WordPopup.jsx', popupSource],
        ]) {
            expect(source, name).not.toMatch(/^export const /m);
        }
        expect(styleSource).toMatch(/^export const toolbarButtonClass = /m);
        expect(styleSource).toMatch(/^export const explainButtonClass = /m);
    });

    it('lets the profile preview import the pane from its own file', () => {
        expect(previewSource).toContain("import {ExplainPane} from '../../Components/ExplainPane.jsx'");
        expect(previewSource).not.toContain("from '../../Components/WordPopup.jsx'");
    });
});
