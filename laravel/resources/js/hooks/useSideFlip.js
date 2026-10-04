import {useCallback, useEffect, useState} from 'react';
import {loadSideFlip, saveSideFlip} from '../lib/sideFlip';
import {displaySideFor, otherSide} from '../lib/readingRows.mjs';

// Side swap (ADR 0037's Working-state tier): one per-device boolean per text,
// keyed by the server's positionKey, shared by every reading surface — the
// reader and the simulator both flip around this hook's state. Which side
// each display column shows is derived here from the canonical sides; the
// rows themselves never move (ADR 0060).
export function useSideFlip(defaultSide, positionKey) {
    const [flipped, setFlipped] = useState(() => loadSideFlip(positionKey));

    // A different positionKey is a different text with its own stored flip
    // (the simulator switches texts in place; the reader never re-keys).
    useEffect(() => {
        setFlipped(loadSideFlip(positionKey));
    }, [positionKey]);

    const hasTranslation = defaultSide === 'a' || defaultSide === 'b';
    const displaySide = displaySideFor(defaultSide, hasTranslation && flipped);

    const toggleTo = useCallback((side) => {
        if (!hasTranslation) {
            return;
        }
        const next = side !== defaultSide;
        if (next === flipped) {
            return;
        }
        setFlipped(next);
        saveSideFlip(positionKey, next);
    }, [hasTranslation, defaultSide, flipped, positionKey]);

    return {
        flipped,
        hasTranslation,
        displaySide,
        toggleTo,
        firstSide: displaySide ?? 'a',
        secondSide: displaySide !== null ? otherSide(displaySide) : null,
    };
}
