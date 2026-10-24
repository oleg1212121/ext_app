import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The word popup's provenance chips (ADR 0066 source pins): the bound
// headword's import provenance — the shared payload's top-level `source`
// (words.source, ADR 0084) — renders as small muted LABELS (one bordered
// chip per part) at the very end of every section label ("DECIMATED — verb
// [wiktionary] [ai]"), the form's data origin; the header's row carries the
// headword alone (the chip moved out of the header, 2026-10-24; the joined
// plain-text form became per-part chips the same day — a resource title is a
// label, not running text). One chip per importer plus one for "ai" when the
// word carries a succeeded ask-AI enrichment, "ai" alone when only enriched,
// and NOTHING at all when neither is known (pre-provenance rows stay
// honest). An adopt-on-ask mint carries source 'ai' (ADR 0084) AND ai_asked
// true from the same ask — the chips dedupe the duplicate and render a
// single "ai", never two. ADR 0082's "the popup renders no visual marker" is
// superseded at word level only — per-row content stays unmarked
// (popup-provenance ticket 04).

const popupSource = readFileSync(new URL('./WordPopup.jsx', import.meta.url), 'utf8');

// PopupContent's body: the component the real portal and the profile's
// Popups-tab preview share. The missing card — no word behind it, no chip
// to show — sits before it in the file.
const contentSource = popupSource.slice(popupSource.indexOf('export function PopupContent'));

// The dictionary view's section-label heading: the h3 from its opening tag
// to its close — the "WORD — pos" text, the usage chips and the source chips
// all live between.
const labelAt = contentSource.indexOf('word-popup-section-label');
const sectionLabel = contentSource.slice(
    contentSource.lastIndexOf('<h3', labelAt),
    contentSource.indexOf('</h3>', labelAt),
);

// The derivation, lifted verbatim from the component source and evaluated
// against each payload state: components sit outside vitest's behavioral
// reach (ADR 0066), so the states are pinned by running the REAL
// expression — not a copy of it, which is how the "ai · ai" dedup bug
// slipped past the original verbatim-string pin.
const derivation = contentSource.match(/const sourceBadge = (.+);/)?.[1];
if (!derivation) throw new Error('the sourceBadge derivation was not found in WordPopup.jsx');
const badgeFor = (data) => new Function('data', `'use strict'; return ${derivation}`)(data);

const chipAt = sectionLabel.indexOf('word-popup-section-source');

describe('The provenance chips on the section label', () => {
    it('the header’s row carries the headword alone — no source badge there anymore', () => {
        const headerStart = contentSource.indexOf('word-popup-header');
        const header = contentSource.slice(headerStart, contentSource.indexOf('word-popup-familiarity', headerStart));
        expect(header).toContain('word-popup-title');
        expect(header).not.toContain('word-popup-source');
    });

    it('trails every section label’s very end — after the "WORD — pos" text and the usage chips', () => {
        const labelTextAt = sectionLabel.indexOf('{sectionLabel(entry');
        const usageChipsAt = sectionLabel.indexOf('entryUsageChips(entry');
        expect(labelTextAt, 'the label text renders').toBeGreaterThan(-1);
        expect(usageChipsAt, 'the usage chips render').toBeGreaterThan(labelTextAt);
        expect(chipAt, 'the source chips are the label’s last element').toBeGreaterThan(usageChipsAt);
    });

    it('joins the payload facts: importer + "ai" when asked', () => {
        // The importer string renders verbatim — the closed words.source
        // vocabulary is already human-readable ("wiktionary" since the
        // 2026-10-24 rename, ADR 0084 amendment).
        expect(badgeFor({source: 'wiktionary', ai_asked: true})).toEqual(['wiktionary', 'ai']);
    });

    it('renders the importer alone when the word was never asked', () => {
        expect(badgeFor({source: 'golddict', ai_asked: false})).toEqual(['golddict']);
    });

    it('renders "ai" alone for an enriched word no importer ever stamped', () => {
        expect(badgeFor({source: null, ai_asked: true})).toEqual(['ai']);
    });

    it('dedups the adopt-on-ask word — source \'ai\' asked renders plain "ai", never "ai · ai"', () => {
        // The mint that created the word IS the ask (adopt-on-ask, ticket
        // 05): source 'ai' per ADR 0084 and ai_asked true from the ledger
        // are the same fact twice. The derivation dedupes the parts.
        expect(badgeFor({source: 'ai', ai_asked: true})).toEqual(['ai']);
    });

    it('renders one chip PER PART — every resource title is a label of its own', () => {
        // The map renders the derivation's every element as its own chip
        // span: "wiktionary · ai" as running text is gone, each part wears
        // the box. key={part} is safe — the derivation dedupes.
        expect(sectionLabel).toMatch(/sourceBadge\.map\(\(part\) => \(/);
        expect(sectionLabel).toContain('{part}');
        expect(sectionLabel).not.toContain('{sourceBadge}');
        expect(sectionLabel).not.toContain(' · ');
    });

    it('renders nothing at all when neither importer nor enrichment is known', () => {
        // The chips gate on the derivation's own length: an empty array
        // renders no span at all — not an empty shell.
        expect(badgeFor({source: null, ai_asked: false})).toEqual([]);
        expect(sectionLabel).toMatch(/sourceBadge\.map/);
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

    it('the missing card carries no chip — no word behind it', () => {
        const beforeContent = popupSource.slice(0, popupSource.indexOf('export function PopupContent'));
        expect(beforeContent).toContain('word-popup-title');
        expect(beforeContent).not.toContain('word-popup-section-source');
    });
});
