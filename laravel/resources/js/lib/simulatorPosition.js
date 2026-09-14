const KEY = 'ext_app.simulator.position.v1';

export function loadPositions() {
    try {
        return JSON.parse(localStorage.getItem(KEY)) ?? {};
    } catch {
        return {};
    }
}

export function savePositions(positions) {
    try {
        localStorage.setItem(KEY, JSON.stringify(positions));
    } catch {
        // quota exceeded / private mode — position restore is best-effort
    }
}
