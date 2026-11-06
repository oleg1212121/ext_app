import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The word popup's provenance chips (ADR 0066 source pins): the bound
// headword's import provenance — the shared payload's top-level `source`
// (words.source, ADR 0084) — renders as small muted LABELS (one bordered
// chip per part) at the very end of every section label ("DECIMATED — verb
// [wiktionary] [ai]"), the form's data origin; the header's row carries the
// headword alone (the chip moved out of the header, 2026-10-24; the joined
// plain-text form became per-part chips the same day — a resource title is a
// label, not running text). ADR 0082's "the popup renders no visual marker"
// is superseded at word level only — per-row content stays unmarked
// (popup-provenance ticket 04).
//
// The DERIVATION — one chip per importer plus one for "ai" when the word
// carries a succeeded ask-AI enrichment, "ai" alone when only enriched,
// nothing when neither is known, and the adopt-on-ask dedupe that keeps the
// "ai · ai" regression out — moved into lib/popupModel.js (word-card ticket
// 01) and is asserted behaviorally there (lib/popupModel.test.js, "the
// provenance badge"); this suite's old lift-and-evaluate pins retired with
// word-card ticket 02. What stays here is the structure the card renders the
// model's badge with (Components/WordCard.jsx).

const cardSource = readFileSync(new URL('./WordCard.jsx', import.meta.url), 'utf8');

// The dictionary view's section-label heading: the h3 from its opening tag
// to its close — the "WORD — pos" text, the usage chips and the source chips
// all live between.
const labelAt = cardSource.indexOf('word-popup-section-label');
const sectionLabel = cardSource.slice(
    cardSource.lastIndexOf('<h3', labelAt),
    cardSource.indexOf('</h3>', labelAt),
);

const chipAt = sectionLabel.indexOf('word-popup-section-source');

describe('The provenance chips on the section label', () => {
    it('the header’s row carries the headword alone — no source badge there anymore', () => {
        const headerStart = cardSource.indexOf('word-popup-header');
        const header = cardSource.slice(headerStart, cardSource.indexOf('word-popup-familiarity', headerStart));
        expect(header).toContain('word-popup-title');
        expect(header).not.toContain('word-popup-source');
    });

    it('trails every section label’s very end — after the "WORD — pos" text and the usage chips', () => {
        const labelTextAt = sectionLabel.indexOf('{label}');
        const usageChipsAt = sectionLabel.indexOf('chips.map(');
        expect(labelTextAt, 'the label text renders').toBeGreaterThan(-1);
        expect(usageChipsAt, 'the usage chips render').toBeGreaterThan(labelTextAt);
        expect(chipAt, 'the source chips are the label’s last element').toBeGreaterThan(usageChipsAt);
    });

    it('renders the model’s badge — the card never derives provenance', () => {
        expect(sectionLabel).toContain('card.header.sourceBadge.map(');
        expect(cardSource).not.toContain('data.source');
        expect(cardSource).not.toContain('ai_asked');
    });

    it('renders one chip PER PART — every resource title is a label of its own', () => {
        // The map renders the badge's every element as its own chip span:
        // "wiktionary · ai" as running text is gone, each part wears the
        // box. key={part} is safe — the model dedupes.
        expect(sectionLabel).toMatch(/sourceBadge\.map\(\(part\) => \(/);
        expect(sectionLabel).toContain('{part}');
        expect(sectionLabel).not.toContain('{sourceBadge}');
        expect(sectionLabel).not.toContain(' · ');
    });

    it('is a small muted bordered label, day and night alike — the word-popup-section-source hook', () => {
        // The hook names the chip's own class: the span opens just before it.
        // A hairline box (the usage chips' rounded-sm border treatment in
        // the neutral hue) reads as a label; opacity muting carries both
        // themes with the night border variant; the size matches the usage
        // chips beside it.
        const chip = sectionLabel.slice(
            sectionLabel.lastIndexOf('<span', chipAt),
            sectionLabel.indexOf('</span>', chipAt),
        );
        expect(chip).toContain('word-popup-section-source');
        expect(chip).toContain('rounded-sm');
        expect(chip).toContain('border');
        expect(chip).toContain('px-1');
        expect(chip).toContain('opacity-60');
        expect(chip).toContain('text-[0.786em]');
        // A resource title never wraps mid-word.
        expect(chip).toContain('whitespace-nowrap');
    });

    it('renders the chips at one site only — inside a section label, which the missing card never has', () => {
        // The missing card's sections list is empty (model), so the one
        // chip render site inside the entries map can never reach it.
        expect(cardSource.match(/word-popup-section-source/g)).toHaveLength(1);
        expect(cardSource.indexOf('card.entries.map(')).toBeLessThan(chipAt + cardSource.indexOf(sectionLabel));
    });
});
