import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The word popup's header source badge (ADR 0066 source pins): the bound
// headword's import provenance — the shared payload's top-level `source`
// (words.source, ADR 0084) — renders as small muted plain text in the
// header's baseline row, next to the headword: the importer plus " · ai"
// when the word carries a succeeded ask-AI enrichment, "ai" alone when only
// enriched, and NOTHING at all when neither is known (pre-provenance rows
// stay honest). ADR 0082's "the popup renders no visual marker" is
// superseded at word level only — per-row content stays unmarked
// (popup-provenance ticket 04).

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

const badgeAt = headerRow.indexOf('word-popup-source');

describe('The source badge in the popup header', () => {
    it('sits in the header’s baseline row, next to the headword', () => {
        const titleAt = headerRow.indexOf('word-popup-title');
        expect(titleAt, 'the headword title renders in the baseline row').toBeGreaterThan(-1);
        expect(badgeAt, 'the badge follows the headword in the same row').toBeGreaterThan(titleAt);
    });

    it('joins the payload facts: importer + " · ai" when asked, "ai" alone when only asked', () => {
        // One derivation covers every case: ["kaikki", "ai"] joins to
        // "kaikki · ai"; a null/empty source drops out leaving "ai"; a
        // false ai_asked leaves the importer alone. The importer string
        // renders verbatim — the closed words.source vocabulary is already
        // human-readable.
        expect(contentSource).toContain(
            "[data.source, data.ai_asked ? 'ai' : ''].filter(Boolean).join(' · ')",
        );
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
