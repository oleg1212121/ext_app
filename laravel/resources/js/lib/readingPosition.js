// Reader Reading position (ADR 0024's Working-state tier): the last page
// reached per text, keyed by the server's positionKey — 'mm:{entityMatchId}'
// for matched texts, 'ent:{entityId}' for single-language ones. Per-device
// only, never sent to the server; written on every page turn.
const KEY = 'ext_app.reader.position.v1';

export function loadReadingPositions() {
    try {
        return JSON.parse(localStorage.getItem(KEY)) ?? {};
    } catch {
        return {};
    }
}

export function saveReadingPositions(positions) {
    try {
        localStorage.setItem(KEY, JSON.stringify(positions));
    } catch {
        // quota exceeded / private mode — position restore is best-effort
    }
}
