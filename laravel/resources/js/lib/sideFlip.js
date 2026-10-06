// Side swap (ADR 0024's Working-state tier): whether a reading surface shows
// the flipped sides — the server's default learning side read second — keyed
// by the server's positionKey. Shared by the reader and the simulator (one
// flip per text via the useSideFlip hook). Per-device only, never sent to
// the server; written when the toggle changes.
import {loadJson, saveJson} from './jsonStorage';

const KEY = 'ext_app.reader.side-flip.v1';

export function loadSideFlip(positionKey) {
    if (!positionKey) {
        return false;
    }
    return loadJson(KEY, {})[positionKey] === true;
}

export function saveSideFlip(positionKey, flipped) {
    if (!positionKey) {
        return;
    }
    const flips = loadJson(KEY, {});
    flips[positionKey] = flipped;
    saveJson(KEY, flips);
}
