import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The reading popup's "Save to Anki" send (WordPopup.jsx's sendToAnki) is
// React wiring — ADR 0066 keeps components outside vitest's behavioral
// reach — so its one untestable-elsewhere decision is pinned the way the
// PHP mirror suites pin their hand-mirrored JSX: a source assertion. What
// it locks: the send must feed buildNoteFields the app's UI-language label
// wording through lib/ankiTemplate.js's shared uiLabels(t) builder, so a
// card sent from the reader carries the sender's language in its counted
// summaries, its "+N more…" expander, and its "form of" label — not always
// English. The builder's key→string mapping is pinned behaviorally in
// lib/ankiTemplate.test.js (uiLabels is a lib export); the wire behavior
// around the built note in lib/ankiSync.test.js. This test only guards the
// pass-through itself (ADR 0077 addendum point E).

const popupSource = readFileSync(new URL('./WordPopup.jsx', import.meta.url), 'utf8');

describe('WordPopup sendToAnki', () => {
    it('builds the note fields with the labels option', () => {
        expect(popupSource).toMatch(/buildNoteFields\(data, \{[^}]*labels:/s);
    });

    it('takes those labels from the shared uiLabels builder — no hand-rolled object', () => {
        const send = popupSource.slice(popupSource.indexOf('const sendToAnki'));
        expect(send).toContain('labels: uiLabels(t)');
        // An inline labels object here would fork the five-key mapping the
        // Anki tab's sample send shares; the builder is the single source.
        expect(send).not.toMatch(/labels: \{/);
    });
});
