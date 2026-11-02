import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// ai-answer-words ticket 03 — the tracer bullet: Ctrl+click (Ctrl ONLY, no
// Cmd, ADR 0030) on any word token in the AI answer opens the SAME
// dictionary popup the text columns use, resolving learning-first through
// ticket 02's ordered resolve. Components sit outside vitest's behavioral
// reach (ADR 0066), so the decisions are pinned as source assertions (the
// WordText.missing.test.js pattern); the two extractable decisions — the
// open-or-not gate and the code-order derivation — are behaviorally tested
// in lib/aiAnswerTokens.test.mjs. What they lock: the handling is event
// DELEGATION on the #ai_answer_div container (the answer HTML is swapped
// wholesale by dangerouslySetInnerHTML on every streaming render, so a
// per-token handler could never survive mid-stream), tokens are found via
// event.target.closest, a Ctrl+click inside a link prevents the browser's
// new-tab navigation, the popup mounts in a portal with wordId=null (missing
// mode) and the RAW token surface, and NOTHING of the columns' ledger
// reaches it — no column language (that is what suppresses Ask-AI/adopt),
// no familiarity map, no explain payload, no progress recording.

const aiSource = readFileSync(new URL('./AI.jsx', import.meta.url), 'utf8');
const bilingualsSource = readFileSync(new URL('../Bilinguals.jsx', import.meta.url), 'utf8');
const tokensLibSource = readFileSync(new URL('../../../lib/aiAnswerTokens.mjs', import.meta.url), 'utf8');

// The answer container: the one div whose innerHTML streams.
const answerDivAt = aiSource.indexOf('<div id="ai_answer_div"');
const answerDivSource = aiSource.slice(answerDivAt, aiSource.indexOf('</div>', answerDivAt) + 6);

// The WordPopup portal mount at the component's tail.
const portalAt = aiSource.indexOf('{popup && createPortal(');
const portalSource = aiSource.slice(portalAt, aiSource.indexOf('</div>\n    )', portalAt));

// The click delegation handler.
const clickHandlerSource = aiSource.slice(
    aiSource.indexOf('const onAnswerClick ='),
    aiSource.indexOf('const onAnswerKeyDown ='),
);

// The keydown delegation handler (the columns' Ctrl+Enter/Ctrl+Space parity).
const keydownHandlerSource = aiSource.slice(
    aiSource.indexOf('const onAnswerKeyDown ='),
    aiSource.indexOf('return ('),
);

describe('AI panel: the token event delegation (ticket 03)', () => {
    it('handles click + keydown on the #ai_answer_div container — never on a token', () => {
        // The answer HTML is swapped wholesale on every throttled streaming
        // render; handlers live on the React-owned container div, so no
        // rewrite can detach them.
        expect(answerDivSource).toContain('onClick={onAnswerClick}');
        expect(answerDivSource).toContain('onKeyDown={onAnswerKeyDown}');
        // The content itself is dangerouslySetInnerHTML — no React-rendered
        // token exists to carry a handler.
        expect(answerDivSource).toContain('dangerouslySetInnerHTML=');
    });

    it('finds tokens through event.target.closest — plain clicks fall through', () => {
        for (const handlerSource of [clickHandlerSource, keydownHandlerSource]) {
            expect(handlerSource).toContain("event.target.closest('[data-word-key]')");
            // No token, or the gate says no → return; links and plain text
            // keep their native behavior.
            expect(handlerSource).toMatch(/return;\s*\}/);
        }
    });

    it('gates on the shared opensTokenPopup helper — Ctrl ONLY, never Cmd', () => {
        // ADR 0030: the gesture is one modifier on every platform; the gate
        // lives in lib/aiAnswerTokens.mjs and reads ctrlKey alone.
        const gateSource = tokensLibSource.slice(
            tokensLibSource.indexOf('export function opensTokenPopup'),
            tokensLibSource.indexOf('}', tokensLibSource.indexOf('return key === undefined')),
        );
        expect(clickHandlerSource).toContain('opensTokenPopup(event)');
        expect(keydownHandlerSource).toContain('opensTokenPopup(event)');
        expect(aiSource).not.toContain('metaKey');
        expect(gateSource).toContain('ctrlKey !== true');
        expect(gateSource).not.toContain('metaKey');
    });

    it('Ctrl+click inside a link opens the popup without navigating', () => {
        // preventDefault BEFORE the open: the browser's Ctrl+click new-tab
        // navigation must never fire for a token inside a markdown link.
        const guard = clickHandlerSource.indexOf('if (!token || !opensTokenPopup(event))');
        const prevent = clickHandlerSource.indexOf('event.preventDefault()');
        const open = clickHandlerSource.indexOf('openTokenPopup(');
        expect(prevent).toBeGreaterThan(guard);
        expect(open).toBeGreaterThan(prevent);
    });

    it('keyboard parity: Ctrl+Enter / Ctrl+Space on a focused token opens, key swallowed', () => {
        // The WordText.jsx:374-386 contract mirrored through delegation:
        // same helper, preventDefault so nothing activates underneath.
        expect(keydownHandlerSource).toContain('event.preventDefault()');
        expect(keydownHandlerSource).toContain('openTokenPopup(');
    });

    it('opens the popup from the RAW token surface and its live rect', () => {
        // The span's textContent — marked leaves the text node intact, and
        // the tokenizer carries no edge punctuation — plus the span's own
        // getBoundingClientRect for the popup's viewport-aware placement.
        const openFnSource = aiSource.slice(
            aiSource.indexOf('const openTokenPopup ='),
            aiSource.indexOf('const onAnswerClick ='),
        );
        expect(openFnSource).toContain('token.textContent');
        expect(openFnSource).toContain('token.getBoundingClientRect()');
    });
});

describe('AI panel: the WordPopup mount', () => {
    it('portals the same WordPopup the columns use, into document.body', () => {
        expect(portalSource).toContain('<WordPopup');
        expect(portalSource).toContain('document.body');
        // Escape / outside-click close come from the popup itself; the
        // panel only clears its own state.
        expect(portalSource).toContain('onClose={() => setPopup(null)}');
    });

    it('mounts in missing mode — wordId is null, resolution goes through the ordered codes', () => {
        // The lookup (ticket 02) swaps the full card in via lookedUpId when
        // a code hits; the mount itself never carries a word id.
        expect(portalSource).toContain('wordId={null}');
        expect(portalSource).toContain('surface={popup.surface}');
        expect(portalSource).toContain('rect={popup.rect}');
        expect(portalSource).toContain('languageCodes={answerLanguageCodes(props.answerLanguages)}');
    });

    it('reaches nothing of the columns’ ledger — no language, familiarity, explain, onProgress', () => {
        // Structural absences, each one load-bearing: no `language` prop is
        // what suppresses the Ask-AI/adopt icon (nothing is ever minted from
        // AI-answer text), no familiarity map opens the card untouched, no
        // explain payload (it needs a sentence id), no progress recording
        // (no word-event ledger without sentence identity).
        expect(portalSource).not.toContain('language=');
        expect(portalSource).not.toContain('familiarity=');
        expect(portalSource).not.toContain('explain=');
        expect(portalSource).not.toContain('onProgress=');
    });

    it('typography follows the page font — the host page’s popupFontSize rides down (ADR 0031)', () => {
        expect(portalSource).toContain('fontSize={props.popupFontSize}');
        // The simulator page computes it (popupFontSizeFor) and threads it.
        expect(bilingualsSource).toContain('popupFontSize={popupFontSize}');
    });

    it('resolution uses the displayed answer’s ask codes, snapshotted by the engine', () => {
        // The page passes useAiStream's snapshot — a side flip after the ask
        // changes the columns, never this list.
        expect(bilingualsSource).toContain('answerLanguages={answerLanguages}');
    });
});
