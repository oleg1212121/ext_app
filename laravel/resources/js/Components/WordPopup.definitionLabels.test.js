import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The word popup's usage-label chips (popup-usage-labels ticket 01) are
// React wiring — ADR 0066 keeps components outside vitest's behavioral
// reach — so the JSX decisions are pinned as source assertions. The tier
// logic underneath is behavioral in lib/usageLabelDisplay.test.js; the
// payload's {text, labels} shape is pinned by the Pest suites
// (WordEndpointTest, AiWordEnrichEndpointTest). What the pins here lock:
// the list renders the sense's text (never the raw object), a dimmed
// sense's line carries the word-popup-definition-dimmed hook at half
// opacity (hover restoring it), and the chips carry the
// word-popup-definition-label hook — vermilion for the dimming tier,
// opacity-60 plain text (the raw:-label precedent) for the register one.
// The follow-up pins the section header's word-level mark (entry.labels):
// the mark dims the WHOLE <section> at half opacity with hover restoring
// it — the hook lives on the section element, the header keeps its base
// 60% — and the chips carry the word-popup-section-label-chip hook. Since
// word-card ticket 02 the dictionary renders in Components/WordCard.jsx,
// and since the word-card review pass every derived fact — the "WORD —
// pos" label, the word-level chips, the section's dimming mark, and per
// sense its text, chips and dim (never stacked inside an already-dimmed
// section) — arrives per entry from lib/popupModel.js, asserted
// behaviorally there ("the entry list"); the card derives nothing.

const cardSource = readFileSync(new URL('./WordCard.jsx', import.meta.url), 'utf8');

// The definitions section: from the <details> to its closing </details>.
const definitionsSource = () => cardSource.slice(
    cardSource.indexOf('<details className="word-popup-definitions'),
    cardSource.indexOf('</details>', cardSource.indexOf('word-popup-definitions-list')),
);

// The POS section: from the <section> element to its closing </section>.
const sectionSource = () => {
    const start = cardSource.indexOf('<section');
    return cardSource.slice(start, cardSource.indexOf('</section>', start) + '</section>'.length);
};

// The POS section header: from the conditional <h3> to its closing tag.
const sectionLabelSource = () => {
    const start = cardSource.indexOf('<h3 className="word-popup-section-label');
    return cardSource.slice(start, cardSource.indexOf('</h3>', start) + '</h3>'.length);
};

describe('The popup\'s definition label chips', () => {
    it('renders definition.text — the payload\'s objects, never the raw object', () => {
        expect(definitionsSource()).toContain('definition.text');
        expect(definitionsSource()).not.toMatch(/\{definition\}/);
    });

    it('renders the model\'s per-sense chips and dim — the card imports no label logic', () => {
        const block = definitionsSource();
        expect(block).toContain('definitions.map((definition, definitionIndex) => (');
        expect(block).toContain('definition.chips.map((chip, chipIndex) => (');
        expect(block).toContain('className={definition.dimmed');
        expect(cardSource).not.toContain("from '../lib/usageLabelDisplay'");
    });

    it('dims a labelled sense with the word-popup-definition-dimmed hook at half opacity, hover restoring it', () => {
        const block = definitionsSource();
        expect(block).toContain('word-popup-definition-dimmed');
        expect(block).toContain('opacity-50');
        expect(block).toContain('hover:opacity-100');
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
        expect(cardSource).toContain('card.entries.map(({entry, label, chips, dimmed, definitions}, index) => (');
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
