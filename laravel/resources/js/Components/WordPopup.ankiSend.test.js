import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The reading popup's Anki wiring (WordPopup.jsx's sendToAnki + the on-open
// duplicate detection) is React wiring — ADR 0066 keeps components outside
// vitest's behavioral reach — so its decisions are pinned the way the PHP
// mirror suites pin their hand-mirrored JSX: source assertions. The wire
// behavior underneath is behavioral in lib/ankiSync.test.js
// (find-before-add, update-in-place of our own notes, memoized lookup) and
// lib/ankiWordConfig.test.js (the per-word config resolution); the builder's
// key→string mapping in lib/ankiTemplate.test.js. What the pins here lock:
// the send builds its note with the shared uiLabels(t) wording and the
// word's RESOLVED per-language config, maps the sync result's updated flag
// to its own status, and both the send and the detection drop results that
// belong to a word the popup has already left (ADR 0077 addendum point E
// and the anki-popup-refresh stale-response rule).

const popupSource = readFileSync(new URL('./WordPopup.jsx', import.meta.url), 'utf8');

const sendSource = () => popupSource.slice(popupSource.indexOf('const sendToAnki'));
const detectionSource = () => popupSource.slice(popupSource.indexOf('On-open duplicate detection'));

describe('WordPopup sendToAnki', () => {
    it('builds the note fields with the labels option', () => {
        expect(popupSource).toMatch(/buildNoteFields\(data, \{[^}]*labels:/s);
    });

    it('takes those labels from the shared uiLabels builder — no hand-rolled object', () => {
        expect(sendSource()).toContain('labels: uiLabels(t)');
        // An inline labels object here would fork the five-key mapping the
        // Anki tab's sample send shares; the builder is the single source.
        expect(sendSource()).not.toMatch(/labels: \{/);
    });

    it('sends through the word\'s resolved per-language config', () => {
        // The word's language entry (ankiWordSyncConfig: languages[code] ??
        // languages.default) carries deck + toggles; the connection stays
        // global. Detection resolves the SAME config for the same word, so
        // the two can never target different decks.
        expect(sendSource()).toContain('ankiWordSyncConfig(ankiSettings, language)');
        expect(detectionSource()).toContain('ankiWordSyncConfig(ankiSettings, language)');
    });

    it('passes the resolved fields map — the language entry\'s toggles shape the Body value at send time', () => {
        // The shared ankiSettings prop carries the user's saved toggles
        // (AnkiSettings::for), body master toggle included. Without it the
        // card's Body would ignore the user's section opt-outs.
        expect(sendSource()).toContain('fields: config.fields');
    });

    it('maps the sync result\'s updated flag to its own status — no duplicate dead-end for our notes', () => {
        // added → 'added', updated → 'updated' (the "Updated in Anki."
        // status), only a FOREIGN-model note left standing → 'duplicate'
        // (lib/ankiSync.js updates every note of our own types in place).
        expect(sendSource()).toContain("result.added ? 'added' : result.updated ? 'updated' : 'duplicate'");
    });

    it('a successful add or update flips the icon without a re-query', () => {
        // The word is now in the deck as one of OUR notes: ankiExisting
        // goes 'found' (the update glyph) — and the sync layer seeded the
        // detection memo, so the next open answers from the memo too.
        expect(sendSource()).toContain("setAnkiExisting('found')");
    });

    it('never lands a send on a popup that has moved to another word', () => {
        // Both completion paths compare the data they started with against
        // the mirrored ref — a late resolve for a previous word is dropped
        // instead of lighting the current word's status line.
        expect(sendSource().match(/dataRef\.current !== data/g)).toHaveLength(2);
    });
});

describe('WordPopup on-open duplicate detection', () => {
    it('asks the sync layer for the loaded word — raw word + language_code, memoized there', () => {
        // findExistingNote (lib/ankiSync.js) never runs the ensure, so
        // detection cannot create decks or note types, and it memoizes per
        // session — a reopened word does not re-query.
        expect(detectionSource()).toContain(
            'findExistingNote(ankiWordSyncConfig(ankiSettings, language), {word: data.word, language})',
        );
    });

    it('maps null to the add form and a note id to the update form', () => {
        // The sync layer collapses "not in the deck" and "couldn't ask"
        // into one null — both leave the add-form icon; only a found note
        // flips it.
        expect(detectionSource()).toContain("noteId == null ? 'absent' : 'found'");
    });

    it('never adopts a stale answer for a popup that has moved to another word', () => {
        expect(detectionSource()).toContain('let cancelled = false');
        expect(detectionSource()).toContain('if (!cancelled)');
    });

    it('resets with the word — the fetch effect clears the previous word\'s state', () => {
        const fetch = popupSource.slice(popupSource.indexOf('let cancelled = false'), popupSource.indexOf('On-open duplicate detection'));
        expect(fetch).toContain('setAnkiSend(null)');
        expect(fetch).toContain("setAnkiExisting('checking')");
    });
});
