import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The word popup's translations lines (ADR 0066 source pin): the linked
// green translations line keeps its shape, and the word's staged raw
// candidates (the `words.translations` JSON, surfaced by the shared payload
// as per-entry `staged_translations`) render as a SECOND collapsible line
// directly under it — popup-provenance ticket 02 / ADR 0085. The raw line
// is amber (the token tickets 01 landed), carries the tiny muted
// word.raw_translations label, previews 8 with its own "+N more…"
// expander (per-line expanded state — label "raw-translations"), hides
// entirely when the entry stages nothing, and the calm "no translations
// yet" placeholder shows only when linked AND staged are both empty.
// Popup-only by decision: the Anki note fields and card Body keep reading
// the LINKED translations — ankiTemplate never touches staged strings.

const popupSource = readFileSync(new URL('./WordPopup.jsx', import.meta.url), 'utf8');
const ankiTemplateSource = readFileSync(new URL('../lib/ankiTemplate.js', import.meta.url), 'utf8');

// PopupContent's body: the exported component the real portal and the
// profile preview share.
const contentStart = popupSource.indexOf('export function PopupContent');
const contentSource = popupSource.slice(contentStart);

// The dictionary view: from its class hook to the end of PopupContent —
// every POS section lives inside it.
const dictionaryStart = contentSource.indexOf('className="word-popup-dictionary');
const dictionary = contentSource.slice(dictionaryStart);

// The translations section: from the linked line's CollapsibleLine open to
// the transcriptions line that follows it — both translations lines and the
// placeholder logic live between.
const linkedStart = dictionary.indexOf('<CollapsibleLine');
const transcriptionsStart = dictionary.indexOf('label="transcriptions"');
const translationsSection = dictionary.slice(linkedStart, transcriptionsStart);

// The raw line's JSX, located by its stable class-giving label prop. The
// slice runs to the translations section's fragment close — the decorate
// body carries its own `</>` and `className=`, so neither can bound it.
const rawLine = (source = popupSource) => {
    const at = source.indexOf('label="raw-translations"');
    expect(at, 'the staged raw translations line renders').toBeGreaterThan(-1);
    const start = source.lastIndexOf('<CollapsibleLine', at);

    return source.slice(start, source.indexOf('</React.Fragment>', at));
};

describe('The linked translations line', () => {
    it('keeps its verdigris shape and renders the placeholder through empty', () => {
        const linked = translationsSection.slice(0, translationsSection.indexOf('/>') + 2);
        expect(linked).toContain('label="translations"');
        expect(linked).toContain('renderItem={(translation) => translation.word}');
        expect(linked).toContain('text-[var(--color-verdigris)]');
        expect(linked).toContain('dark:text-[var(--color-verdigris-night)]');
        expect(translationsSection).toContain('word-popup-translations-placeholder');
        expect(translationsSection).toContain("t('word.no_translations')");
    });

    it('shows the calm placeholder only when linked AND staged are both empty', () => {
        // The linked line's empty prop is the placeholder ONLY while the
        // entry stages nothing: a staged-only word renders the raw line
        // instead of a "no translations" note (popup-provenance ticket 02).
        expect(translationsSection).toMatch(
            /empty=\{\(entry\.staged_translations\?\.length \?\? 0\) > 0 \? null : \(/,
        );
    });

    it('renders nothing at all for a line with no items and no empty fallback', () => {
        // CollapsibleLine's contract: an empty items list returns `empty`
        // verbatim — null included — so the linked line disappears entirely
        // when the raw line takes over.
        const component = popupSource.slice(
            popupSource.indexOf('function CollapsibleLine'),
            popupSource.indexOf('function ExplainGuidance'),
        );
        expect(component).toMatch(/if \(items\.length === 0\) \{\s*\n\s*return empty;\s*\n\s*\}/);
    });
});

describe('The staged raw translations line', () => {
    it('renders as a second CollapsibleLine on the stable raw-translations hooks', () => {
        const raw = rawLine();
        // label="raw-translations" names the word-popup-raw-translations and
        // word-popup-raw-translations-more classes — the raw line and its
        // own "+N more…" expander hook.
        expect(raw).toContain('label="raw-translations"');
        expect(popupSource).toContain('word-popup-${label}');
        expect(raw).toContain('previewCount={TRANSLATIONS_PREVIEW}');
        expect(raw).toContain('separator=", "');
    });

    it('sits directly under the linked line, inside the translations section', () => {
        const rawAt = translationsSection.indexOf('label="raw-translations"');
        expect(rawAt, 'the raw line follows the linked line').toBeGreaterThan(
            translationsSection.indexOf('label="translations"'),
        );
        expect(rawAt, 'the raw line stays inside the translations section').toBeLessThan(
            transcriptionsStart,
        );
    });

    it('hides entirely when the entry has no staged strings', () => {
        // The raw line's own mount gate: no staged strings, no line — not
        // even an empty shell.
        expect(popupSource).toMatch(/\(entry\.staged_translations\?\.length \?\? 0\) > 0 && \(/);
    });

    it('is amber — the staged/raw token, day and night', () => {
        const raw = rawLine();
        expect(raw).toContain('text-[var(--color-amber)]');
        expect(raw).toContain('dark:text-[var(--color-amber-night)]');
    });

    it('prefixes the tiny muted raw: label from the ui-strings', () => {
        const raw = rawLine();
        // The decorate wrapper carries the localized label at reduced
        // opacity in front of the joined strings.
        expect(raw).toContain('decorate=');
        expect(raw).toContain("t('word.raw_translations')");
        expect(raw).toContain('opacity-60');
    });

    it('maps the staged strings verbatim — language-less as-is', () => {
        // Plain strings from the words.translations JSON: rendered as
        // themselves, no language metadata wrapped around them.
        expect(rawLine()).toContain('renderItem={(candidate) => candidate}');
    });
});

describe('The popup-only boundary', () => {
    it('never leaks the staged strings into the Anki note fields or card Body', () => {
        // The card builder reads the LINKED translations only (the flat
        // Translations field maps language_code-bearing objects; the staged
        // strings are plain strings and stay out of the note).
        expect(popupSource).toContain('staged_translations');
        expect(ankiTemplateSource).not.toContain('staged_translations');
    });
});
