// Best-effort JSON in localStorage: a corrupt read or a quota-exceeded write
// never breaks a reading surface — Working-state restore (ADR 0024) is always
// optional. The per-store shapes stay in their own modules.
export function loadJson(key, fallback = {}) {
    try {
        return JSON.parse(localStorage.getItem(key)) ?? fallback;
    } catch {
        return fallback;
    }
}

export function saveJson(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // quota exceeded / private mode — best-effort
    }
}
