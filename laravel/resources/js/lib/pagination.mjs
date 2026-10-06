/**
 * Clamp a 1-based page number into [1, lastPage]. Shared by every reading
 * surface's page input.
 */
export function clampPage(page, lastPage) {
    return Math.min(Math.max(1, page), lastPage);
}
