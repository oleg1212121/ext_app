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
// line carries the word-popup-definition-dimmed hook at half opacity (hover
// restoring it — but never inside an already-dimmed section, where the
// extra opacity would stack), and the chips carry the
// word-popup-definition-label hook — vermilion for the dimming tier,
// opacity-60 plain text (the raw:-label precedent) for the register one.
// The follow-up pins the section header's word-level mark (entry.labels):
// dimsEntry dims the WHOLE <section> at half opacity with hover restoring
// it — the hook lives on the section element, the header keeps its base
// 60% — and the chips ride entryUsageChips with the
// word-popup-section-label-chip hook. Since word-card ticket 02 the
// dictionary renders in Components/WordCard.jsx, and the section-level
// facts (the "WORD — pos" label, the word-level chips, the section's
// dimming mark) arrive per entry from lib/popupModel.js — derived and
// asserted behaviorally there; the per-sense chips still derive here, in
// the JSX, through the same lib module.

const popupSource = readFileSync(new URL('./WordCard.jsx', import.meta.url), 'utf8');

// The definitions section: from the <details> to its closing </details>.
const definitionsSource = () => popupSource.slice(
    popupSource.indexOf('<details className="word-popup-definitions'),
    popupSource.indexOf('</details>', popupSource.indexOf('word-popup-definitions-list')),
);

// The POS section: from the <section> element to its closing </section>.
const sectionSource = () => {
    const start = popupSource.indexOf('<section');
    return popupSource.slice(start, popupSource.indexOf('</section>', start) + '</section>'.length);
};

// The POS section header: from the conditional <h3> to its closing tag.
const sectionLabelSource = () => {
    const start = popupSource.indexOf('<h3 className="word-popup-section-label');
    return popupSource.slice(start, popupSource.indexOf('</h3>', start) + '</h3>'.length);
};

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

    it('dims a labelled sense with the word-popup-definition-dimmed hook at half opacity, hover restoring it', () => {
        const block = definitionsSource();
        expect(block).toContain('word-popup-definition-dimmed');
        expect(block).toContain('opacity-50');
        expect(block).toContain('hover:opacity-100');
    });

    it('never stacks the sense dim inside an already-dimmed section', () => {
        const block = definitionsSource();
        // `dimmed` is the model's section mark (dimsEntry, lib/popupModel.js).
        expect(block).toContain('!dimmed && dimsDefinition(definition.labels)');
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

describe('The popup\'s section-wide word-level mark', () => {
    it('dims the whole section on the model\'s mark, hover restoring it', () => {
        // The mark is the model's per-entry `dimmed` (dimsEntry from the
        // shared lib, lib/popupModel.test.js).
        expect(popupSource).toContain('card.entries.map(({entry, label, chips, dimmed}, index) => (');
        const block = sectionSource();
        expect(block).toContain('${dimmed');
        expect(block).toContain('word-popup-section-dimmed');
        expect(block).toContain('opacity-50');
        expect(block).toContain('hover:opacity-100');
    });

    it('keeps the dim hook off the header — it keeps its base 60%', () => {
        const block = sectionLabelSource();
        expect(block).not.toContain('word-popup-section-dimmed');
        expect(block).not.toContain('opacity-50');
        expect(block).toContain('opacity-60');
    });

    it('renders the word-level chips after the label text', () => {
        // Both arrive per entry from the model (sectionLabel +
        // entryUsageChips, lib/popupModel.js).
        const block = sectionLabelSource();
        expect(block).toContain('chips.map((chip, chipIndex) => (');
        expect(block).toContain('{label}');
        expect(block.indexOf('{label}')).toBeLessThan(block.indexOf('chips.map((chip, chipIndex) => ('));
    });

    it('carries the word-popup-section-label-chip hook on both tier branches', () => {
        const block = sectionLabelSource();
        const chipMatches = block.match(/word-popup-section-label-chip/g) ?? [];
        expect(chipMatches.length).toBe(2);
    });

    it('paints the dimming tier vermilion, day and night', () => {
        const block = sectionLabelSource();
        expect(block).toContain('text-[var(--color-vermilion)]');
        expect(block).toContain('dark:text-[var(--color-vermilion-night)]');
    });
});
