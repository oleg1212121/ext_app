// The AI answer's token-interaction decisions (ai-answer-words ticket 03),
// extracted as pure data-in/data-out functions (ADR 0066: lib modules test
// in Node). The DOM walk itself — event.target.closest, the token span's
// textContent and rect — has no DOM to run against in Node, so it stays
// component code pinned by source assertions beside AI.jsx; what is
// behaviorally testable here: the open-or-not gate for both event kinds and
// the ordered resolve list derived from the displayed answer's ask codes.
import {describe, expect, it} from 'vitest';

import {answerLanguageCodes, opensTokenPopup} from './aiAnswerTokens.mjs';

describe('opensTokenPopup: the ADR 0030 gate for both event kinds', () => {
    it('opens on Ctrl+click — a click event carries no key', () => {
        expect(opensTokenPopup({ctrlKey: true, key: undefined})).toBe(true);
    });

    it('opens on Ctrl+Enter / Ctrl+Space on a focused token — the columns’ keyboard parity', () => {
        // The WordText.jsx contract, mirrored: Enter/Space synthesize a
        // button click, and only Ctrl+that click opened the popup.
        expect(opensTokenPopup({ctrlKey: true, key: 'Enter'})).toBe(true);
        expect(opensTokenPopup({ctrlKey: true, key: ' '})).toBe(true);
    });

    it('never opens without Ctrl — plain clicks and plain Enter/Space are inert', () => {
        expect(opensTokenPopup({ctrlKey: false, key: undefined})).toBe(false);
        expect(opensTokenPopup({ctrlKey: false, key: 'Enter'})).toBe(false);
        expect(opensTokenPopup({ctrlKey: false, key: ' '})).toBe(false);
    });

    it('is deliberately Ctrl-ONLY: Cmd (metaKey) is neither required nor sufficient', () => {
        // ADR 0030: "Cmd/⌘ is not accepted — the contract is one modifier on
        // every platform." Cmd+click keeps the browser's native behavior
        // (open link in new tab); the gate reads ctrlKey alone.
        expect(opensTokenPopup({ctrlKey: false, metaKey: true, key: undefined})).toBe(false);
    });

    it('keeps every other Ctrl+key native — Ctrl+C must stay a copy shortcut', () => {
        expect(opensTokenPopup({ctrlKey: true, key: 'c'})).toBe(false);
        expect(opensTokenPopup({ctrlKey: true, key: 'Escape'})).toBe(false);
        expect(opensTokenPopup({ctrlKey: true, key: 'Tab'})).toBe(false);
    });
});

describe('answerLanguageCodes: the displayed answer’s ordered resolve list', () => {
    it('orders learning first, base fallback — resolution is learning→base', () => {
        expect(answerLanguageCodes({learning: 'ru', base: 'en'})).toEqual(['ru', 'en']);
    });

    it('keeps whichever code the ask carried', () => {
        expect(answerLanguageCodes({learning: 'ru', base: null})).toEqual(['ru']);
        expect(answerLanguageCodes({learning: null, base: 'en'})).toEqual(['en']);
    });

    it('resolves to null with no answer — before the first ask no popup can open', () => {
        expect(answerLanguageCodes(null)).toBeNull();
        expect(answerLanguageCodes(undefined)).toBeNull();
    });

    it('collapses an ask with no usable codes to null — the language-less popup mount', () => {
        // buildAskPayload defaults both codes to null; without a usable code
        // there is nothing to resolve with, and null keeps the popup exactly
        // like a column-less mount: missing card, no Ask-AI.
        expect(answerLanguageCodes({learning: null, base: null})).toBeNull();
        expect(answerLanguageCodes({learning: '', base: ''})).toBeNull();
    });
});
