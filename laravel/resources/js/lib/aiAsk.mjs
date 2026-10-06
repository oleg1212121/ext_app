/**
 * Legacy prompt cleanup: trims the text and strips the first literal '*'
 * anywhere in it. Predates ADR 0060 (arrived in a June 2026 squash commit
 * with no recorded rationale — likely leftover emphasis-marker cleanup);
 * kept verbatim so prompts don't change during the engine extraction.
 * Note the pattern is unanchored and non-global: a second '*' survives.
 */
export function stripLegacyAsterisk(text) {
    return String(text ?? '').trim().replace('*', '');
}

/**
 * The /ai/question/stream request body: base-cell text and workplace
 * translation joined by a newline, the learner's task list, and the two
 * column language codes. Both texts must be non-empty (callers gate that
 * before building).
 */
export function buildAskPayload({cellContent, workplaceText, tasks, base, learning}) {
    return {
        data: `${cellContent}\n${workplaceText}`,
        tasks,
        base: base ?? null,
        learning: learning ?? null,
    };
}
