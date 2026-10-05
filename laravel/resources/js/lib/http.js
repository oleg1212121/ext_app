export function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * Dig the error message out of a JSON POST response: the deep envelope the
 * JSON endpoints use, then the plain message, then the caller's fallback.
 */
export function responseErrorMessage(json, status, fallback) {
    return json?.data?.data?.error ?? json?.message ?? fallback ?? `Request failed (HTTP ${status})`;
}
