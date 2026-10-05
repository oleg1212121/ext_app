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

/**
 * Rewrite one match's position entry, seeding positions.currentText on the
 * way: load → clone the entry → merge `fields` → save → return the store
 * (callers read back other fields from the return value). The cloned entry
 * always drops the legacy `flipped` key — the flip left the position store
 * for the shared side-flip store (useSideFlip); this deletes it from any
 * store an older build wrote. One home for that migration.
 */
export function writePosition(textId, fields) {
    const positions = loadPositions();
    const key = String(textId);
    positions.currentText = key;
    const entry = {...(positions.alignments?.[key] ?? {})};
    delete entry.flipped;
    positions.alignments = {
        ...(positions.alignments ?? {}),
        [key]: {...entry, ...fields},
    };
    savePositions(positions);
    return positions;
}

/**
 * Record which match is open without touching any per-match entry.
 */
export function writeCurrentText(textId) {
    const positions = loadPositions();
    positions.currentText = String(textId);
    savePositions(positions);
    return positions;
}
