import {describe, expect, it} from 'vitest';
import {
    DEFAULT_POPUP_FONT_SIZE,
    POPUP_MARGIN,
    VIEWPORT_MARGIN,
    explainButtonClass,
    popupContainerClass,
    popupFontSizeFor,
    popupStyle,
    toolbarButtonClass,
} from './popupStyle';

// word-card ticket 01 — the popup's pure style helpers move out of
// Components/WordPopup.jsx into lib so pages import them from lib and vitest
// can exercise the placement math behaviorally (ADR 0066): the viewport is an
// injectable parameter, so no jsdom and no window global are needed.

// A fixed viewport: 1200x800, unscrolled. Every expected value below is
// computed by hand from the documented derivation (560px base width at the
// default 17px font, 8px popup margin, 12px viewport margin, 160px height
// floor) — not from the module's own constants.
const VIEWPORT = {innerWidth: 1200, innerHeight: 800, scrollX: 0, scrollY: 0};

// A word sitting in the upper-left quadrant: plenty of room below, so the
// popup opens beneath it, anchored to its bottom edge.
const RECT_ABOVE_FOLD = {left: 100, top: 200, right: 160, bottom: 240};

describe('popupFontSizeFor — page font to popup font (ADR 0031)', () => {
    it('scales the page font at 0.65', () => {
        expect(popupFontSizeFor(40)).toBe(26);
    });

    it('floors at 14 so small reading fonts never shrink the popup', () => {
        expect(popupFontSizeFor(20)).toBe(14);
        expect(popupFontSizeFor(17)).toBe(14);
    });

    it('caps at 32 so huge fonts stay readable', () => {
        expect(popupFontSizeFor(50)).toBe(32);
        expect(popupFontSizeFor(100)).toBe(32);
    });

    it('rounds the product before clamping', () => {
        expect(popupFontSizeFor(21)).toBe(14);
        expect(popupFontSizeFor(22)).toBe(14);
        expect(popupFontSizeFor(23)).toBe(15);
    });

    it('honors the ratio/min/max overrides', () => {
        // The default floor still applies unless overridden too.
        expect(popupFontSizeFor(20, {ratio: 0.5})).toBe(14);
        expect(popupFontSizeFor(20, {ratio: 0.5, min: 0})).toBe(10);
        expect(popupFontSizeFor(20, {min: 18})).toBe(18);
        expect(popupFontSizeFor(20, {max: 12, min: 5})).toBe(12);
    });
});

describe('popupStyle — viewport-fit placement (injected viewport)', () => {
    it('opens below the word, anchored to its bottom edge, when there is room', () => {
        expect(popupStyle(RECT_ABOVE_FOLD, 17, VIEWPORT)).toEqual({
            left: '100px',
            top: '248px',
            width: '560px',
            maxHeight: '540px',
        });
    });

    it('flips up above the word when there is more room above', () => {
        const rect = {left: 100, top: 400, right: 150, bottom: 460};
        expect(popupStyle(rect, 17, {innerWidth: 1000, innerHeight: 500, scrollX: 0, scrollY: 0})).toEqual({
            left: '100px',
            top: '400px',
            width: '560px',
            maxHeight: '380px',
            transform: 'translateY(-100%)',
        });
    });

    it('scales the width with the popup font (560px at the default 17px)', () => {
        const style = popupStyle(RECT_ABOVE_FOLD, 34, VIEWPORT);
        expect(style.width).toBe('1120px');
        // The wider box re-clamps horizontally.
        expect(style.left).toBe('68px');
    });

    it('never exceeds the viewport minus both margins, on either axis', () => {
        const rect = {left: 250, top: 200, right: 290, bottom: 240};
        const style = popupStyle(rect, 17, {innerWidth: 300, innerHeight: 800, scrollX: 0, scrollY: 0});
        expect(style.width).toBe('276px');
        expect(style.left).toBe('12px');
    });

    it('offsets by the page scroll', () => {
        const scrolled = {innerWidth: 1200, innerHeight: 800, scrollX: 50, scrollY: 100};
        expect(popupStyle(RECT_ABOVE_FOLD, 17, scrolled)).toEqual({
            left: '150px',
            top: '348px',
            width: '560px',
            maxHeight: '540px',
        });
    });

    it('floors maxHeight at 160px so the toolbar stays reachable', () => {
        const rect = {left: 0, top: 100, right: 10, bottom: 110};
        const style = popupStyle(rect, 17, {innerWidth: 1200, innerHeight: 200, scrollX: 0, scrollY: 0});
        expect(style.maxHeight).toBe('160px');
    });

    it('defaults the viewport to the window global', () => {
        const original = globalThis.window;
        globalThis.window = VIEWPORT;
        try {
            expect(popupStyle(RECT_ABOVE_FOLD, 17)).toEqual(popupStyle(RECT_ABOVE_FOLD, 17, VIEWPORT));
        } finally {
            if (original === undefined) {
                delete globalThis.window;
            } else {
                globalThis.window = original;
            }
        }
    });
});

describe('the shared constants', () => {
    it('keep the default popup font at 17', () => {
        expect(DEFAULT_POPUP_FONT_SIZE).toBe(17);
    });

    it('keep the margins the placement math and the info popup share', () => {
        expect(POPUP_MARGIN).toBe(8);
        expect(VIEWPORT_MARGIN).toBe(12);
    });

    it('keep the card surface class byte-identical (the portal and the profile preview share it)', () => {
        expect(popupContainerClass).toBe('flex flex-col overflow-hidden rounded-md border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[#FDFBF5] dark:bg-[#1C1915] shadow-lg shadow-black/20 font-sans text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]');
    });
});

// word-card ticket 03: the card's sibling files (WordCard's toolbar, the info
// popup's dismiss control, the explain pane's and the ask-AI error's retry
// buttons) share these class helpers from lib — no helper is exported from a
// component file.
describe('toolbarButtonClass — the toolbar icon button style', () => {
    const BASE = 'rounded-sm p-1 transition-[opacity,background-color]';
    const TAIL = 'disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]';

    it('rests neutral by default — the dismiss control\'s plain grey', () => {
        expect(toolbarButtonClass()).toBe(`${BASE} opacity-80 hover:opacity-100 ${TAIL}`);
    });

    it('maps a color token to its full-literal accent classes', () => {
        expect(toolbarButtonClass('amber')).toBe(`${BASE} text-[var(--color-amber)] hover:bg-[var(--color-amber)]/10 dark:text-[var(--color-amber-night)] dark:hover:bg-[var(--color-amber-night)]/10 ${TAIL}`);
    });

    it('presses verdigris with a persistent tint, whatever token is passed', () => {
        const pressed = `${BASE} text-[var(--color-verdigris)] hover:bg-[var(--color-verdigris)]/10 dark:text-[var(--color-verdigris-night)] dark:hover:bg-[var(--color-verdigris-night)]/10 bg-[var(--color-verdigris)]/10 dark:bg-[var(--color-verdigris-night)]/10 ${TAIL}`;
        expect(toolbarButtonClass('neutral', true)).toBe(pressed);
        expect(toolbarButtonClass('amber', true)).toBe(pressed);
    });
});

describe('explainButtonClass — the verdigris outline retry button', () => {
    it('keeps the class string byte-identical (the explain pane and the ask-AI error share it)', () => {
        expect(explainButtonClass).toBe('rounded-sm border border-[var(--color-verdigris)] px-2.5 py-1 text-[0.857em] hover:bg-[var(--color-verdigris)]/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] dark:border-[var(--color-verdigris-night)]');
    });
});
