export function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * Dig the error message out of a JSON POST response: the flat error
 * envelope the reading-surface endpoints use, then the plain message, then
 * the caller's fallback.
 */
export function responseErrorMessage(json, status, fallback) {
    return json?.error ?? json?.message ?? fallback ?? `Request failed (HTTP ${status})`;
}
