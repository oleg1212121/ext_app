import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The reading popup's "Save to Anki" send (WordPopup.jsx's sendToAnki) is
// React wiring — ADR 0066 keeps components outside vitest's behavioral
// reach — so its one untestable-elsewhere decision is pinned the way the
// PHP mirror suites pin their hand-mirrored JSX: a source assertion. What
// it locks: the popup must feed buildNoteFields the app's own UI-language
// strings (database/seeders/ui-strings/words.php) as the labels option, so
// a card sent from the reader carries the sender's language in its counted
// summaries, its "+N more…" expander, and its "form of" label — not always
// English. The builder's handling of labels is pinned in
// lib/ankiTemplate.test.js; the wire behavior around the built note in
// lib/ankiSync.test.js. This test only guards the pass-through itself.

const popupSource = readFileSync(new URL('./WordPopup.jsx', import.meta.url), 'utf8');

// The five value-side wording strings the builder understands, keyed by the
// app's own ui-string keys — the same five the Anki tab's sample send passes.
const LABEL_STRINGS = [
    "t('word.definitions', {count})",
    "t('word.examples', {count})",
    "t('word.etymology', {count})",
    "t('word.more', {count})",
    "t('word.form_of')",
];

describe('WordPopup sendToAnki', () => {
    it('builds the note fields with the labels option', () => {
        expect(popupSource).toMatch(/buildNoteFields\(data, \{[^}]*labels:/s);
    });

    it('passes the popup\'s own UI-language strings as those labels', () => {
        const send = popupSource.slice(popupSource.indexOf('const sendToAnki'));
        for (const label of LABEL_STRINGS) {
            expect(send).toContain(label);
        }
    });
});
