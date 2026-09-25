// Reader language swap (ADR 0024's Working-state tier): whether the reader
// shows the flipped sides — the server's translation column read as the
// primary one — keyed by the server's positionKey. Per-device only, never
// sent to the server; written when the toggle changes.
const KEY = 'ext_app.reader.side-flip.v1';

export function loadSideFlip(positionKey) {
    if (!positionKey) {
        return false;
    }
    try {
        return JSON.parse(localStorage.getItem(KEY))?.[positionKey] === true;
    } catch {
        return false;
    }
}

export function saveSideFlip(positionKey, flipped) {
    if (!positionKey) {
        return;
    }
    try {
        const flips = JSON.parse(localStorage.getItem(KEY)) ?? {};
        flips[positionKey] = flipped;
        localStorage.setItem(KEY, JSON.stringify(flips));
    } catch {
        // quota exceeded / private mode — flip restore is best-effort
    }
}
