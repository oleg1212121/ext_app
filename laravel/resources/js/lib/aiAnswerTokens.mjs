// The AI answer's token-interaction decisions (ai-answer-words ticket 03),
// extracted as pure data-in/data-out functions so vitest can pin them in
// Node (ADR 0066). The DOM walk itself — event.target.closest, the token
// span's textContent and rect — has no DOM to run against in Node, so it
// stays component code in the AI panel, pinned by source assertions beside
// AI.jsx. Everything that DECIDES, though, lives here.

/**
 * The ADR 0030 gate for both event kinds on an ai-word-token: Ctrl+click
 * (a click event carries no key) and Ctrl+Enter / Ctrl+Space on a focused
 * token (keydown — the text columns' WordText contract, mirrored). The
 * gesture is deliberately Ctrl-ONLY: metaKey (macOS Cmd) is neither
 * required nor sufficient, so Cmd+click keeps the browser's native
 * behavior, and every other Ctrl+key (copy shortcuts, navigation) keeps
 * its native meaning.
 */
export function opensTokenPopup({ctrlKey, key} = {}) {
    if (ctrlKey !== true) {
        return false;
    }
    return key === undefined || key === 'Enter' || key === ' ';
}

/**
 * The ordered resolve list for the DISPLAYED answer's tokens: learning
 * first, base fallback. The codes are snapshotted from the ask payload the
 * moment a stream starts (useAiStream's answerLanguages), so a side flip
 * after the ask never changes resolution and a retry re-captures from the
 * stored payload. No answer yet — or an ask that carried no usable code —
 * yields null, which keeps the popup exactly like a column-less mount:
 * missing card, no Ask-AI.
 */
export function answerLanguageCodes(answerLanguages) {
    if (answerLanguages == null) {
        return null;
    }
    const codes = [answerLanguages.learning, answerLanguages.base]
        .filter((code) => typeof code === 'string' && code !== '');
    return codes.length > 0 ? codes : null;
}
