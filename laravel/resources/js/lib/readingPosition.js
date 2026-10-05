// Reader Reading position (ADR 0024's Working-state tier): the last page
// reached per text, keyed by the server's positionKey — 'mm:{entityMatchId}'
// for matched texts, 'ent:{entityId}' for single-language ones. Per-device
// only, never sent to the server; written on every page turn.
import {loadJson, saveJson} from './jsonStorage';

const KEY = 'ext_app.reader.position.v1';

export function loadReadingPositions() {
    return loadJson(KEY, {});
}

export function saveReadingPositions(positions) {
    saveJson(KEY, positions);
}
