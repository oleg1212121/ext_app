// The word popup's pure style helpers (word-card ticket 01), lifted out of
// Components/WordPopup.jsx so pages import them from lib — never from a
// component file — and vitest exercises the placement math behaviorally
// (ADR 0066). The viewport is an injectable parameter defaulting to the
// window global, so the module stays Node-safe: nothing here touches a
// browser global at import time.

// The popup's distance from the clicked word, and the gap it keeps from
// every viewport edge. The info popup's viewport clamp
// (Components/InfoPopup.jsx) reads the same two values.
export const POPUP_MARGIN = 8;
export const VIEWPORT_MARGIN = 12;
const MIN_POPUP_HEIGHT = 160;

// Surface styling of the popup box, shared by the real portal and the
// profile's Popups-tab preview (ADR 0046). Positioning classes stay with
// the portal wrapper. A full literal on purpose — Tailwind's scanner reads
// this file's text (resources/js/** is a @source), so concatenated names
// would never compile.
export const popupContainerClass = 'flex flex-col overflow-hidden rounded-md border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[#FDFBF5] dark:bg-[#1C1915] shadow-lg shadow-black/20 font-sans text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]';

// Action-toolbar icon buttons (the card's first row; word-card ticket 03
// moved them here so the card's sibling files share them without exporting
// a helper from a component file). The glyphs carry no color of their own —
// these text classes drive the resting and hover states: each toolbar
// descriptor carries a color token (verdigris / vermilion / amber / info, or
// the neutral default), mapped here to its full literal class strings — full
// literals on purpose, Tailwind's scanner reads this file's text, so names
// built by concatenation would never compile.
const TOOLBAR_ACCENTS = {
    verdigris: 'text-[var(--color-verdigris)] hover:bg-[var(--color-verdigris)]/10 dark:text-[var(--color-verdigris-night)] dark:hover:bg-[var(--color-verdigris-night)]/10',
    vermilion: 'text-[var(--color-vermilion)] hover:bg-[var(--color-vermilion)]/10 dark:text-[var(--color-vermilion-night)] dark:hover:bg-[var(--color-vermilion-night)]/10',
    amber: 'text-[var(--color-amber)] hover:bg-[var(--color-amber)]/10 dark:text-[var(--color-amber-night)] dark:hover:bg-[var(--color-amber-night)]/10',
    info: 'text-[var(--color-info)] hover:bg-[var(--color-info)]/10 dark:text-[var(--color-info-night)] dark:hover:bg-[var(--color-info-night)]/10',
    neutral: 'opacity-80 hover:opacity-100',
};
// `pressed` is the Explanation toggle's shown state: the verdigris accent
// plus a persistent tint, so the icon reads as active while its pane is
// mounted — verdigris whatever the passed token (only the toggle presses,
// and it passes neutral). The info popup's per-item dismiss control
// (InfoDismissButton, Components/AskAiError.jsx) wears the neutral default.
export const toolbarButtonClass = (color = 'neutral', pressed = false) => [
    'rounded-sm p-1 transition-[opacity,background-color]',
    pressed
        ? `${TOOLBAR_ACCENTS.verdigris} bg-[var(--color-verdigris)]/10 dark:bg-[var(--color-verdigris-night)]/10`
        : TOOLBAR_ACCENTS[color],
    'disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]',
].join(' ');

// The verdigris outline button the Explanation pane's error Retry and the
// ask-AI error's Retry share (Components/ExplainPane.jsx,
// Components/AskAiError.jsx).
export const explainButtonClass = 'rounded-sm border border-[var(--color-verdigris)] px-2.5 py-1 text-[0.857em] hover:bg-[var(--color-verdigris)]/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] dark:border-[var(--color-verdigris-night)]';

// Popup typography derives from the host page's font-size setting (ADR 0031):
// the page passes the size it computed with popupFontSizeFor(); this default
// only covers callers that don't (≈ today's look, slightly larger than the
// original fixed 14px text).
export const DEFAULT_POPUP_FONT_SIZE = 17;
const POPUP_BASE_WIDTH = 560;

/**
 * Derive the popup font from a page's reading font size: proportional at
 * POPUP_FONT_RATIO, floored so small reading fonts never shrink the popup
 * below its original fixed size, capped so huge fonts stay readable.
 */
export function popupFontSizeFor(pageFontSize, {
    ratio = 0.65,
    min = 14,
    max = 32,
} = {}) {
    return Math.min(max, Math.max(min, Math.round(pageFontSize * ratio)));
}

/**
 * Position the popover next to the clicked word, always inside the viewport:
 * it opens below the word unless there is more room above (then it flips up,
 * anchored to the word's top edge), and its height is capped to the larger
 * side so the top action toolbar stays reachable. Width scales with the popup
 * font (560px at the default 17px) so proportions hold as the user scales
 * text from the page's +/- controls. `viewport` supplies innerWidth,
 * innerHeight, scrollX and scrollY — the browser passes nothing and gets the
 * window; tests inject a plain object.
 */
export function popupStyle(rect, fontSize, viewport = window) {
    const width = Math.min(
        Math.round((POPUP_BASE_WIDTH * fontSize) / DEFAULT_POPUP_FONT_SIZE),
        viewport.innerWidth - VIEWPORT_MARGIN * 2,
    );
    const left = Math.min(
        Math.max(VIEWPORT_MARGIN, rect.left + viewport.scrollX),
        Math.max(VIEWPORT_MARGIN, viewport.innerWidth - width - VIEWPORT_MARGIN),
    );

    const spaceBelow = viewport.innerHeight - rect.bottom - POPUP_MARGIN - VIEWPORT_MARGIN;
    const spaceAbove = rect.top - POPUP_MARGIN - VIEWPORT_MARGIN;
    const flipUp = spaceAbove > spaceBelow;
    const maxHeight = Math.max(MIN_POPUP_HEIGHT, Math.max(spaceBelow, spaceAbove));
    const top = flipUp ? rect.top + viewport.scrollY : rect.bottom + viewport.scrollY + POPUP_MARGIN;

    return {
        left: `${left}px`,
        top: `${top}px`,
        width: `${width}px`,
        maxHeight: `${maxHeight}px`,
        ...(flipUp ? {transform: 'translateY(-100%)'} : {}),
    };
}
