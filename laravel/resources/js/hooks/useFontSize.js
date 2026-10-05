import {useCallback, useState} from 'react';

/**
 * The font-size stepper every reading surface's toolbar carries: clamped
 * state with a fixed step, adjusted by numeric delta. Persistence stays with
 * each page's single useUiSettingsAutosave call (one writer per settings
 * section) and so does application — the simulator injects stylesheet rules,
 * the reader threads a prop. The clamping ranges are deliberate per-surface
 * constants, mirrored by the controllers' saved-value clamps.
 */
export function useFontSize({initial, min, max, step = 2}) {
    const clampSize = useCallback((size) => Math.max(min, Math.min(max, size)), [min, max]);
    const [fontSize, setFontSize] = useState(() => clampSize(initial));
    const adjust = useCallback((delta) => {
        setFontSize((current) => clampSize(current + delta));
    }, [clampSize]);
    return {fontSize, adjust, step};
}
