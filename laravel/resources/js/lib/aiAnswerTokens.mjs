// The AI answer's token-interaction decisions (ai-answer-words ticket 03),
// extracted as pure data-in/data-out functions so vitest can pin them in
// Node (ADR 0066). The DOM walk itself — event.target.closest, the token
// span's textContent and rect — has no DOM to run against in Node, so it
// stays component code in the AI panel, pinned by source assertions beside
// AI.jsx. Everything that DECIDES, though, lives here — since the review
// pass also the ask-time snapshot derivation that used to be inlined in
// useAiStream.js.

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
 * The ask-time snapshot of the codes the ask itself carried — useAiStream
 * stores this the moment a stream starts, so a side flip after the ask
 * never changes a displayed answer's resolution and a retry (Ask again)
 * re-captures from the stored payload. The returned object is a COPY: the
 * engine must keep its snapshot even when the caller mutates the payload
 * object afterwards. Null only for a null/undefined payload; unusable
 * codes stay nulls in the shape — deciding what is usable is
 * answerLanguageCodes's job (the consumer side), so the snapshot never
 * silently reshapes.
 */
export function answerLanguagesFromPayload(payload) {
    if (payload == null) {
        return null;
    }
    return {learning: payload.learning ?? null, base: payload.base ?? null};
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

/**
 * The single language descriptor the AI answer's word popup asks with
 * (word-popup-overhaul ticket 05 — the ADR 0096 adopt-less rule reversed
 * for this surface): the ask's learning-first language, i.e. the FIRST code
 * of the snapshotted resolution order (`answerLanguageCodes` — the learning
 * language, base fallback), which un-suppresses the missing card's Ask-AI.
 * Asking about an unknown answer word then mints the word into that
 * language through the existing adopt-on-ask path; a snapshot without
 * usable codes yields null and keeps the icon disabled, exactly like the
 * old column-less mount. The descriptor is `{code}`-shaped and nothing
 * more: the answer snapshot carries codes only, WordPopup's ask gating
 * reads the code alone, and a display name must never be invented here.
 */
export function answerAskLanguage(answerLanguages) {
    const code = answerLanguageCodes(answerLanguages)?.[0];
    return code != null ? {code} : null;
}
