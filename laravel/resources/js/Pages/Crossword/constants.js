export const ALLOWED_KEYS = [
    '.',
    'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
    'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
    'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M',
    'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
    '-', "'", ' ',
];

export const VECTORS = [[1, 0], [0, 1], [-1, 0], [0, -1]];

export const DEFAULT_LEVEL = 0;

export const MIN_PANEL_WIDTH = 280;

export function getDefaultPanelWidth() {
    return Math.round(window.innerWidth * 0.5);
}

export function getMaxPanelWidth() {
    return Math.round(window.innerWidth * 0.8);
}

export function cellKey(y, x) {
    return `${y}.${x}`;
}
