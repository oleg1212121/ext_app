export const WORD_LEVELS = [
    {id: 0, name: 'Less 500 (A0)'},
    {id: 1, name: 'Less 1000 (A1)'},
    {id: 2, name: 'Less 3000 (A2)'},
    {id: 3, name: 'Less 5000 (B1)'},
    {id: 4, name: 'Less 8000 (B2)'},
    {id: 5, name: 'Less 10000 (C1)'},
    {id: 6, name: 'Less 20000 (C2)'},
    {id: 7, name: 'Native'},
];

export const ALLOWED_KEYS = [
    '.',
    'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
    'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
    'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M',
    'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
    '-', "'", ' ',
];

export const VECTORS = [[1, 0], [0, 1], [-1, 0], [0, -1]];

export const DEFAULT_LEVEL = 7;

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
