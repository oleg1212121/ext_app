import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The word popup's action toolbar (ADR 0066 source pin): components sit
// outside vitest's behavioral reach, so the toolbar's structure is pinned
// the way WordPopup.ankiSend.test.js pins the send — assertions on the JSX
// source. What it locks: the three actions (know / remove mark / Anki) live
// in a `word-popup-actions` strip that is PopupContent's first row — above
// the word header, with no bottom footer — and always render, disabled
// rather than hidden, when inapplicable (the `progress_actions` visibility
// gate covers only the two progress icons; the Anki icon ignores
// popup-visibility toggles). Tooltips and aria-labels come from the
// existing word.* ui-strings, the glyphs from the shared line-art set in
// Components/icons.jsx (currentColor, no own color), and the Anki status
// lines render inside the toolbar strip. The profile preview's inertness
// (no handlers reach PopupContent) is pinned on PopupsSettings.jsx.

const popupSource = readFileSync(new URL('./WordPopup.jsx', import.meta.url), 'utf8');
const iconsSource = readFileSync(new URL('./icons.jsx', import.meta.url), 'utf8');
const previewSource = readFileSync(new URL('../Pages/Profile/PopupsSettings.jsx', import.meta.url), 'utf8');

// The toolbar block: from the strip's own class hook to the word header
// that follows it — everything between is the first-row JSX.
const toolbarStart = popupSource.indexOf('className="word-popup-actions');
const headerStart = popupSource.indexOf('className="word-popup-header');
const toolbar = popupSource.slice(toolbarStart, headerStart);

// The full JSX of one toolbar button, located by its stable class hook.
const buttonIn = (hook) => {
    const at = toolbar.indexOf(hook);
    expect(at, `${hook} renders in the toolbar`).toBeGreaterThan(-1);
    const start = toolbar.lastIndexOf('<button', at);
    return toolbar.slice(start, toolbar.indexOf('</button>', at));
};

describe('WordPopup action toolbar', () => {
    it('is the popup’s first row — the toolbar renders above the word header', () => {
        expect(toolbarStart).toBeGreaterThan(-1);
        expect(headerStart).toBeGreaterThan(toolbarStart);
    });

    it('replaces the bottom footer — no footer row remains', () => {
        expect(popupSource).not.toContain('word-popup-footer');
    });

    it('carries the three actions on stable word-popup-* hooks', () => {
        expect(toolbar).toContain('word-popup-progress-known');
        expect(toolbar).toContain('word-popup-progress-reset');
        expect(toolbar).toContain('word-popup-anki-send');
    });

    it('disables — never hides — a progress icon in its inapplicable state', () => {
        // know: the mark is already at the maximum; remove mark: no mark to
        // remove. A busy send or a missing handler (the preview) disables
        // both, exactly like the old footer buttons did.
        expect(buttonIn('word-popup-progress-known')).toMatch(
            /disabled=\{[^}]*familiarity === FAMILIARITY_MAX\}/,
        );
        expect(buttonIn('word-popup-progress-known')).toMatch(/progressBusy \|/);
        expect(buttonIn('word-popup-progress-known')).toMatch(/onProgressAction == null/);
        expect(buttonIn('word-popup-progress-reset')).toMatch(
            /disabled=\{[^}]*familiarity == null\}/,
        );
    });

    it('the progress_actions visibility gate hides only the two progress icons', () => {
        // One gate per progress icon, both inside the toolbar…
        expect(toolbar.match(/sections\.progress_actions !== false/g)).toHaveLength(2);
        // …and the Anki icon sits outside them, disabled only by its own state.
        const anki = buttonIn('word-popup-anki-send');
        expect(anki).toMatch(/disabled=\{onAnkiSend == null \|\| ankiSend\?\.status === 'sending'\}/);
        expect(anki).not.toContain('sections.');
    });

    it('labels every icon with tooltip + aria from the existing word ui-strings', () => {
        expect(buttonIn('word-popup-progress-known')).toContain("t('word.i_know_this')");
        expect(buttonIn('word-popup-progress-reset')).toContain("t('word.remove_mark')");
        const anki = buttonIn('word-popup-anki-send');
        expect(anki).toContain("t('word.anki_send')");
        expect(anki).toContain("t('word.anki_sending')");
        expect(toolbar.match(/title=/g)).toHaveLength(3);
        expect(toolbar.match(/aria-label=/g)).toHaveLength(3);
    });

    it('draws the glyphs from the shared line-art set — no inline SVG', () => {
        expect(buttonIn('word-popup-progress-known')).toContain('<Icon name="checkCircle"');
        expect(buttonIn('word-popup-progress-reset')).toContain('<Icon name="minusCircle"');
        expect(buttonIn('word-popup-anki-send')).toContain('<Icon name="ankiAdd"');
        for (const name of ['checkCircle', 'minusCircle', 'ankiAdd']) {
            expect(iconsSource).toMatch(new RegExp(`^    ${name}: '`, 'm'));
        }
    });

    it('keeps today’s add-only send — no update glyph wired yet', () => {
        expect(popupSource).not.toContain('ankiUpdate');
    });

    it('renders the Anki status lines inside the toolbar strip', () => {
        expect(toolbar).toContain('word-popup-anki-added');
        expect(toolbar).toContain('word-popup-anki-duplicate');
        expect(toolbar).toContain('word-popup-anki-error');
        expect(toolbar).toContain('/profile?tab=anki');
    });

    it('reads as the popup’s first row: hairline strip, verdigris know accent, Anki right', () => {
        expect(toolbar).toContain('border-b border-[var(--color-hairline)]');
        expect(toolbar).toContain('dark:border-[var(--color-hairline-night)]');
        // The know icon takes the shared button class builder's accent
        // branch (verdigris, light + dark); the other two rest neutral.
        expect(buttonIn('word-popup-progress-known')).toContain('toolbarButtonClass(true)');
        expect(buttonIn('word-popup-progress-reset')).toContain('toolbarButtonClass(false)');
        expect(buttonIn('word-popup-anki-send')).toContain('toolbarButtonClass(false)');
        const helper = popupSource.slice(popupSource.indexOf('const toolbarButtonClass'));
        const accentBranch = helper.slice(helper.indexOf("? '"), helper.indexOf(": '"));
        expect(accentBranch).toContain('text-[var(--color-verdigris)]');
        expect(accentBranch).toContain('dark:text-[var(--color-verdigris-night)]');
        expect(buttonIn('word-popup-anki-send')).toContain('ml-auto');
    });
});

describe('Popups tab preview', () => {
    it('renders the same toolbar inert — no progress or Anki handlers, no network', () => {
        expect(previewSource).toContain('<PopupContent');
        expect(previewSource).not.toContain('onProgressAction');
        expect(previewSource).not.toContain('onAnkiSend');
        expect(previewSource).not.toContain('ankiSend=');
    });
});
