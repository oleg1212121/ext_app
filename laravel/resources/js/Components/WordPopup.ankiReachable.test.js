import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The reading popup's Anki reachability probe (popup-provenance ticket 05):
// on every popup open the outer WordPopup asks the user's AnkiConnect
// endpoint its cheap version action — the SAME probe the profile's Anki
// tab's manual test button uses (lib/ankiConnect.js's ankiConnectVersion,
// lib/ankiConnect.test.js pins its wire) — and the Save-to-Anki icon
// answers with the amber token while Anki is reachable. It stays neutral
// grey while the probe is pending, when Anki is unreachable, and when no
// Anki settings exist (no probe at all). Reachability is a browser-side
// fact only — no backend flag — probed fresh per open, so starting Anki
// mid-session is picked up on the next popup. The send keeps the icon
// honest without a re-probe: any completed send proves Anki answered
// (added, updated, and a foreign-model duplicate alike) and flips amber; a
// network-kind failure — Anki never answered — flips grey, while an
// anki-kind failure (Anki answered, with an error string) and a note-type
// mismatch keep amber. Components sit outside vitest's behavioral reach
// (ADR 0066), so the wiring is pinned the way the sibling suites pin
// theirs: source assertions on WordPopup.jsx.

const popupSource = readFileSync(new URL('./WordPopup.jsx', import.meta.url), 'utf8');
const previewSource = readFileSync(new URL('../Pages/Profile/PopupsSettings.jsx', import.meta.url), 'utf8');

// The outer WordPopup's body: the state, the probe effect and the send
// callback live here — never in PopupContent, which the inert profile
// preview shares.
const bodySource = popupSource.slice(popupSource.indexOf('export default function WordPopup'));
const contentSource = popupSource.slice(
    popupSource.indexOf('export function PopupContent'),
    popupSource.indexOf('export default function WordPopup'),
);

// The probe effect: from its comment hook to the document close handlers
// that follow the duplicate check it sits beside.
const probeSource = () => bodySource.slice(
    bodySource.indexOf('On-open reachability probe'),
    bodySource.indexOf('const onKeyDown'),
);

// The send callback: the same slice WordPopup.ankiSend.test.js uses.
const sendSource = () => bodySource.slice(
    bodySource.indexOf('const sendToAnki'),
    bodySource.indexOf('const askAi = () =>'),
);
const resolveSource = () => sendSource().slice(
    sendSource().indexOf('(result) => {'),
    sendSource().indexOf('(error) => {'),
);
const failureSource = () => sendSource().slice(sendSource().indexOf('(error) => {'));

// The Anki icon button's JSX inside PopupContent's toolbar strip.
const ankiButton = () => {
    const toolbar = contentSource.slice(
        contentSource.indexOf('word-popup-actions'),
        contentSource.indexOf('word-popup-header'),
    );
    const at = toolbar.indexOf('word-popup-anki-send');
    expect(at, 'the Anki icon renders in the toolbar').toBeGreaterThan(-1);
    return toolbar.slice(toolbar.lastIndexOf('<button', at), toolbar.indexOf('</button>', at));
};

describe('The on-open Anki reachability probe', () => {
    it('reuses the profile tab\'s cheap version probe — no new client code', () => {
        // ankiConnectVersion is the lib's existing `version` action wrapper
        // (the Anki tab's manual-test probe), imported beside the
        // AnkiConnectError the popup already carries.
        expect(popupSource).toMatch(/import \{[^}]*ankiConnectVersion[^}]*\} from '\.\.\/lib\/ankiConnect'/);
        expect(probeSource()).toContain('ankiConnectVersion(ankiSettings)');
    });

    it('fires only with settings and a loaded payload — no settings, no probe', () => {
        // The early return mirrors the duplicate check's: without the
        // shared ankiSettings prop there is no endpoint to ask, and the
        // icon keeps its neutral grey (the state's false default).
        expect(probeSource()).toMatch(/if \(!ankiSettings \|\| !data\) \{/);
    });

    it('shares the duplicate check\'s open lifecycle — same key, same cancelled guard', () => {
        // Keyed [ankiSettings, data] exactly like the detection effect: a
        // new payload (the next open, or the popup moving to another word)
        // re-probes; the guard drops a late answer for a popup that has
        // moved on.
        expect(probeSource()).toContain('}, [ankiSettings, data]);');
        expect(probeSource()).toContain('let cancelled = false');
        expect(probeSource().match(/if \(!cancelled\)/g)).toHaveLength(2);
    });

    it('resolve marks reachable, rejection marks unreachable — grey while pending', () => {
        // The effect starts by clearing the flag: whatever the previous
        // open proved, the current probe answers for itself, so the icon
        // sits grey until Anki answers. Resolve → amber, rejection
        // (Anki off, CORS, HTTP/non-JSON) → grey.
        expect(probeSource().indexOf('setAnkiReachable(false)'))
            .toBeLessThan(probeSource().indexOf('ankiConnectVersion(ankiSettings)'));
        expect(probeSource()).toContain('setAnkiReachable(true)');
    });

    it('the state starts false — nothing is reachable before anything answered', () => {
        expect(bodySource).toMatch(/const \[ankiReachable, setAnkiReachable\] = useState\(false\)/);
    });
});

describe('The Anki icon\'s conditional amber', () => {
    it('amber iff reachable — neutral while pending, unreachable, or without settings', () => {
        // The full-literal token ternary (Tailwind's scanner reads this
        // file): every non-reachable state falls to the neutral grey the
        // other unaccented icons wear.
        expect(ankiButton()).toContain("toolbarButtonClass(ankiReachable ? 'amber' : 'neutral')");
    });

    it('the prop defaults grey and the preview never probes — PopupContent stays inert', () => {
        // The probe lives in the outer WordPopup only (it needs the
        // ankiSettings prop and the loaded data): PopupContent just reads
        // the outcome through a false-defaulting prop, so the profile's
        // Popups-tab preview — which passes neither — renders the grey icon
        // and cannot reach the network, same as today.
        expect(contentSource).toMatch(/ankiReachable = false,/);
        expect(contentSource).not.toContain('ankiConnectVersion');
        expect(contentSource).not.toContain('setAnkiReachable');
        expect(previewSource).not.toContain('ankiReachable');
        expect(previewSource).not.toContain('ankiConnect');
    });
});

describe('Send outcomes update reachability without a re-probe', () => {
    it('a completed send flips amber — added, updated, and a duplicate all prove Anki answered', () => {
        // The resolve handler's outcome (added / updated / foreign-model
        // duplicate) is itself reachability evidence — Anki answered every
        // action — so the flip is unconditional inside it, behind the same
        // dataRef guard the status lines use.
        expect(resolveSource()).toContain('setAnkiReachable(true)');
    });

    it('the send never re-probes — the outcome is the evidence', () => {
        expect(sendSource()).not.toContain('ankiConnectVersion');
    });

    it('a network-kind failure flips grey — Anki never answered', () => {
        expect(failureSource()).toContain("error instanceof AnkiConnectError && error.kind === 'network'");
        expect(failureSource()).toContain('setAnkiReachable(false)');
    });

    it('only the network kind flips grey — an anki-kind failure or a mismatch keeps amber', () => {
        // One flip in the failure path, gated on the network kind: an
        // anki-kind error means Anki answered (it IS reachable), and a
        // note-type mismatch means the note search talked to it too.
        expect(failureSource().match(/setAnkiReachable\(/g)).toHaveLength(1);
        expect(failureSource().indexOf('error.kind'))
            .toBeLessThan(failureSource().indexOf('setAnkiReachable(false)'));
    });
});
