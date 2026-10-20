import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The word popup's header source badge (ADR 0066 source pins): the bound
// headword's import provenance — the shared payload's top-level `source`
// (words.source, ADR 0084) — renders as small muted plain text in the
// header's baseline row, next to the headword: the importer plus " · ai"
// when the word carries a succeeded ask-AI enrichment, "ai" alone when only
// enriched, and NOTHING at all when neither is known (pre-provenance rows
// stay honest). An adopt-on-ask mint carries source 'ai' (ADR 0084) AND
// ai_asked true from the same ask — the badge dedups the duplicate and
// renders plain "ai", never "ai · ai". ADR 0082's "the popup renders no
// visual marker" is superseded at word level only — per-row content stays
// unmarked (popup-provenance ticket 04).

const popupSource = readFileSync(new URL('./WordPopup.jsx', import.meta.url), 'utf8');

// PopupContent's body: the component the real portal and the profile's
// Popups-tab preview share. The missing card — no word behind it, no badge
// to show — sits before it in the file.
const contentSource = popupSource.slice(popupSource.indexOf('export function PopupContent'));

// The dictionary view's header baseline row: from the row div to the
// familiarity line that follows it — title and badge live between.
const headerStart = contentSource.indexOf('word-popup-header');
const headerRow = contentSource.slice(
    contentSource.indexOf('flex items-baseline', headerStart),
    contentSource.indexOf('word-popup-familiarity'),
);

// The derivation, lifted verbatim from the component source and evaluated
// against each payload state: components sit outside vitest's behavioral
// reach (ADR 0066), so the states are pinned by running the REAL
// expression — not a copy of it, which is how the "ai · ai" dedup bug
// slipped past the original verbatim-string pin.
const derivation = contentSource.match(/const sourceBadge = (.+);/)?.[1];
if (!derivation) throw new Error('the sourceBadge derivation was not found in WordPopup.jsx');
const badgeFor = (data) => new Function('data', `'use strict'; return ${derivation}`)(data);

const badgeAt = headerRow.indexOf('word-popup-source');

describe('The source badge in the popup header', () => {
    it('sits in the header’s baseline row, next to the headword', () => {
        const titleAt = headerRow.indexOf('word-popup-title');
        expect(titleAt, 'the headword title renders in the baseline row').toBeGreaterThan(-1);
        expect(badgeAt, 'the badge follows the headword in the same row').toBeGreaterThan(titleAt);
    });

    it('joins the payload facts: importer + " · ai" when asked', () => {
        // The importer string renders verbatim — the closed words.source
        // vocabulary is already human-readable.
        expect(badgeFor({source: 'kaikki', ai_asked: true})).toBe('kaikki · ai');
    });

    it('renders the importer alone when the word was never asked', () => {
        expect(badgeFor({source: 'golddict', ai_asked: false})).toBe('golddict');
    });

    it('renders "ai" alone for an enriched word no importer ever stamped', () => {
        expect(badgeFor({source: null, ai_asked: true})).toBe('ai');
    });

    it('dedups the adopt-on-ask word — source \'ai\' asked renders plain "ai", never "ai · ai"', () => {
        // The mint that created the word IS the ask (adopt-on-ask, ticket
        // 05): source 'ai' per ADR 0084 and ai_asked true from the ledger
        // are the same fact twice. The derivation dedupes the parts.
        expect(badgeFor({source: 'ai', ai_asked: true})).toBe('ai');
    });

    it('renders nothing at all when neither importer nor enrichment is known', () => {
        // The badge gates on its own joined text: an empty join renders no
        // span — not an empty shell.
        expect(headerRow).toMatch(/sourceBadge !== '' && \(/);
    });

    it('is small muted plain text, day and night alike — the word-popup-source hook', () => {
        // The hook names the badge's own class: the span opens just before it.
        const badge = headerRow.slice(
            headerRow.lastIndexOf('<span', badgeAt),
            headerRow.indexOf('</span>', badgeAt),
        );
        expect(badge).toContain('word-popup-source');
        // Opacity-based muting carries both themes without a night variant;
        // the small size matches the familiarity/frequency lines.
        expect(badge).toContain('opacity-60');
        expect(badge).toContain('text-[0.786em]');
        // The joined text stays one unbroken unit in the wrapping row.
        expect(badge).toContain('whitespace-nowrap');
    });

    it('the missing card’s header carries no badge — no word behind it', () => {
        const beforeContent = popupSource.slice(0, popupSource.indexOf('export function PopupContent'));
        const missingHeader = beforeContent.slice(beforeContent.indexOf('word-popup-header'));
        expect(missingHeader).toContain('word-popup-title');
        expect(missingHeader).not.toContain('word-popup-source');
    });
});
