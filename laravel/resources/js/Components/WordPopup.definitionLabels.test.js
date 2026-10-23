import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The word popup's usage-label chips (popup-usage-labels ticket 01) are
// React wiring — ADR 0066 keeps components outside vitest's behavioral
// reach — so the JSX decisions are pinned as source assertions. The tier
// logic underneath is behavioral in lib/usageLabelDisplay.test.js; the
// payload's {text, labels} shape is pinned by the Pest suites
// (WordEndpointTest, AiWordEnrichEndpointTest). What the pins here lock:
// the list renders definition.text (never the raw object), every chip goes
// through the shared lib module with the component's t, a dimming sense's
// line carries the word-popup-definition-dimmed hook at half opacity, and
// the chips carry the word-popup-definition-label hook — vermilion for the
// dimming tier, opacity-60 plain text (the raw:-label precedent) for the
// register one.

const popupSource = readFileSync(new URL('./WordPopup.jsx', import.meta.url), 'utf8');

// The definitions section: from the <details> to its closing </details>.
const definitionsSource = () => popupSource.slice(
    popupSource.indexOf('<details className="word-popup-definitions'),
    popupSource.indexOf('</details>', popupSource.indexOf('word-popup-definitions-list')),
);

describe('The popup\'s definition label chips', () => {
    it('renders definition.text — the payload\'s objects, never the raw object', () => {
        expect(definitionsSource()).toContain('definition.text');
        expect(definitionsSource()).not.toMatch(/\{definition\}/);
    });

    it('derives every chip through the shared lib module with the component\'s t', () => {
        expect(popupSource).toContain("from '../lib/usageLabelDisplay'");
        const block = definitionsSource();
        expect(block).toContain('usageLabelChips(definition.labels, t)');
        expect(block).toContain('dimsDefinition(definition.labels)');
    });

    it('dims a labelled sense with the word-popup-definition-dimmed hook at half opacity', () => {
        const block = definitionsSource();
        expect(block).toContain('word-popup-definition-dimmed');
        expect(block).toContain('opacity-50');
    });

    it('carries the word-popup-definition-label hook on both tier branches', () => {
        const block = definitionsSource();
        const chipMatches = block.match(/word-popup-definition-label/g) ?? [];
        expect(chipMatches.length).toBeGreaterThanOrEqual(2);
    });

    it('paints the dimming tier vermilion, day and night', () => {
        const block = definitionsSource();
        expect(block).toContain('text-[var(--color-vermilion)]');
        expect(block).toContain('dark:text-[var(--color-vermilion-night)]');
    });

    it('keeps the register tier muted plain text — the raw:-label precedent', () => {
        expect(definitionsSource()).toContain('opacity-60');
    });
});
