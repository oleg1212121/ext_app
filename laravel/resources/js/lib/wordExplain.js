// Pure guard logic for the Word popup's Explanation pane (ADR 0066 keeps
// vitest lib-only, so the pane's ask/no-ask decision lives here as a
// data-in/boolean-out function, pinned by wordExplain.test.js).

/**
 * True when an Explanation pane may ask for a context explanation: the
 * feature is enabled (an `enabled: false` payload means the user has no API
 * key) and an explanation model is chosen (`modelKey` null means none).
 * WordPopup's pane derives both its guidance render and its auto-request
 * guard from this one predicate, so they can never drift.
 */
export function canAskExplanation(explain) {
    return explain.enabled !== false && explain.modelKey != null;
}
