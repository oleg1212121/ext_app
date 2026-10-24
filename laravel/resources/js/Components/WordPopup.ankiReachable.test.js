import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// The reading popup's Anki reachability probe (popup-provenance ticket 05,
// quiet-gated 2026-10-24): on every popup open the outer WordPopup asks the
// user's AnkiConnect endpoint its cheap version action through
// lib/ankiConnect.js's probeReachable — but ONLY while the page session
// holds a successful Anki answer (the lib's success ledger; the probe runs
// with {onlyIfKnown: true}). A cold page — fresh reload, Anki never up —
// gets null answered from the ledger WITHOUT a request: the browser logs
// every refused fetch as a console error, so browsing with Anki down costs
// zero console errors instead of one per 30s window. Engaging the icon is
// the way back in: hovering (onAnkiProbe) or clicking (the click wrapper
// probes, then sends) fires the UNGATED probe, whose success both flips
// the icon amber in place and opens the ledger gate for later opens. The
// icon's amber token answers ankiReachable alone; it stays CLICKABLE in
// every grey state (pending, unreachable, the quiet unknown) — disabling
// would seal the only path back to a discovered Anki. Disabling keeps its
// two own reasons: no handler, a send in flight. Send outcomes keep the
// answer honest without a re-probe: any completed send proves Anki
// answered (added, updated, and a foreign-model duplicate alike) — the
// ledger records the success and the icon flips amber; a network-kind
// failure — Anki never answered — drops the ledger entry and turns grey
// (now KNOWN-unreachable: the tooltip switches to the start-Anki
// guidance), while an anki-kind failure (Anki answered, with an error
// string) and a note-type mismatch keep amber. Reachability is a
// browser-side fact only — no backend flag. Components sit outside
// vitest's behavioral reach (ADR 0066), so the wiring is pinned the way
// the sibling suites pin theirs: source assertions on WordPopup.jsx.

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

// The probe effect: from its comment hook to the reader-engaged probe that
// follows it.
const probeSource = () => bodySource.slice(
    bodySource.indexOf('On-open reachability probe'),
    bodySource.indexOf('const probeAnkiNow'),
);

// The reader-engaged probe: from its hook to the document close handlers.
const probeAnkiNowSource = () => bodySource.slice(
    bodySource.indexOf('const probeAnkiNow'),
    bodySource.indexOf('const onKeyDown'),
);

// The duplicate-detection effect: from its comment hook to the probe's
// comment — the slice between them is detection's alone.
const detectionSource = () => bodySource.slice(
    bodySource.indexOf('On-open duplicate detection'),
    bodySource.indexOf('On-open reachability probe'),
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
    it('probes through probeReachable — gated by the session ledger (onlyIfKnown)', () => {
        // probeReachable wraps the lib's existing `version` action wrapper
        // behind the page-session success ledger: while no success is on
        // record the gated call answers null without a request (lib/
        // ankiConnect.test.js pins the ledger), so a cold page browsed
        // with Anki down never logs a refused fetch.
        expect(popupSource).toMatch(/import \{[^}]*probeReachable[^}]*\} from '\.\.\/lib\/ankiConnect'/);
        expect(probeSource()).toContain('probeReachable(ankiSettings, {onlyIfKnown: true})');
    });

    it('fires only with settings and a loaded payload — no settings, no probe', () => {
        // The early return mirrors the duplicate check's: without the
        // shared ankiSettings prop there is no endpoint to ask, and the
        // icon keeps its neutral grey (the state's false default).
        expect(probeSource()).toMatch(/if \(!ankiSettings \|\| !data\) \{/);
    });

    it('a quiet null answer keeps the icon grey — reachability only on a real answer', () => {
        // The gated probe resolves null while the session has heard
        // nothing: `answer != null` keeps the flag false — grey, silent,
        // clickable — while a real version answer flips amber.
        expect(probeSource()).toContain('setAnkiReachable(answer != null)');
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
        // sits grey until Anki answers. Resolve → amber (the gated call's
        // null answers grey — see the quiet-null pin above), rejection
        // (Anki off, CORS, HTTP/non-JSON) → grey.
        expect(probeSource().indexOf('setAnkiReachable(false)'))
            .toBeLessThan(probeSource().indexOf('probeReachable(ankiSettings'));
        expect(probeSource()).toContain('setAnkiReachable(answer != null)');
    });

    it('the state starts false — nothing is reachable before anything answered', () => {
        expect(bodySource).toMatch(/const \[ankiReachable, setAnkiReachable\] = useState\(false\)/);
    });

    it('the session starts unknown — the quiet gate closed until something answers', () => {
        // ankiUnknown mirrors the lib ledger's emptiness (one boolean: the
        // config is one endpoint). It NEVER resets per word — once a real
        // probe or send has answered, the session is known either way —
        // so no code path sets it back to true.
        expect(bodySource).toMatch(/const \[ankiUnknown, setAnkiUnknown\] = useState\(true\)/);
        expect(bodySource).not.toContain('setAnkiUnknown(true)');
    });
});

describe('The reader-engaged probe — the way back in', () => {
    it('hovering the icon probes ungated and is passed down as onAnkiProbe', () => {
        // probeAnkiNow fires the probe WITHOUT the onlyIfKnown gate: the
        // reader's own engagement is what re-discovers a running Anki in
        // a cold session. PopupContent receives it as onAnkiProbe and
        // wires it to the icon's hover; the preview passes nothing (the
        // prop defaults null → onMouseEnter undefined → no network).
        expect(probeAnkiNowSource()).toContain('probeReachable(ankiSettings)');
        expect(probeAnkiNowSource()).not.toContain('onlyIfKnown');
        expect(contentSource).toMatch(/onMouseEnter=\{onAnkiProbe \?\? undefined\}/);
        expect(popupSource).toMatch(/onAnkiProbe=\{probeAnkiNow\}/);
    });

    it('whatever the answer, the session becomes KNOWN — the tooltip switches', () => {
        // Success: reachable, unknown off. Failure: unreachability is now
        // a fact — unknown off too, so the tooltip leaves the
        // "haven't heard from Anki" state for the start-Anki guidance.
        expect(probeAnkiNowSource().match(/setAnkiUnknown\(false\)/g)).toHaveLength(2);
    });

    it('the click probes, then sends — ankiIconClick wraps both', () => {
        // The click is the check: the ungated probe first (a running Anki
        // lights the icon amber in place), then the send's
        // find-before-add talks to AnkiConnect for real — a down Anki
        // fails the send into the inline error line, the honest outcome.
        expect(bodySource).toMatch(/const ankiIconClick = \(\) => \{\s*probeAnkiNow\(\);\s*sendToAnki\(\);\s*\}/);
        expect(popupSource).toMatch(/onAnkiSend=\{ankiIconClick\}/);
    });
});

describe('The duplicate check waits for the probe', () => {
    it('never fires findExistingNote while Anki is unreachable', () => {
        // 2026-10-24: the detection effect early-returns without
        // ankiReachable — with Anki off a popup open costs no findNotes
        // request at all (the gated probe itself is silent when the
        // session is cold, so an Anki-down popup open costs NO request).
        expect(detectionSource()).toMatch(/if \(!ankiReachable \|\| !ankiSettings \|\| !data\) \{/);
    });

    it('runs once the probe answers — the reachability flag rides the effect key', () => {
        // The flag flipping false→true re-runs detection, so the check
        // fires the moment Anki answers; the other two keys are the open
        // lifecycle it always shared.
        expect(detectionSource()).toContain('}, [ankiReachable, ankiSettings, data]);');
    });
});

describe('The Anki icon\'s conditional amber', () => {
    it('amber iff reachable — neutral while pending, unreachable, or unknown', () => {
        // The full-literal token ternary (Tailwind's scanner reads this
        // file): every non-reachable state falls to the neutral grey the
        // other unaccented icons wear.
        expect(ankiButton()).toContain("toolbarButtonClass(ankiReachable ? 'amber' : 'neutral')");
    });

    it('grey is COLOR only — the icon stays enabled so a cold session can re-discover Anki', () => {
        // 2026-10-24, quiet probes: with the on-open probe silent for a
        // cold session, a disabled grey icon could never learn Anki came
        // up — hover and click ARE the discovery path. Disabling keeps
        // only its own two reasons: no handler, a send in flight.
        expect(ankiButton()).toMatch(/disabled=\{onAnkiSend == null \|\| ankiSend\?\.status === 'sending'\}/);
        expect(ankiButton()).not.toContain('!ankiReachable');
    });

    it('the tooltip explains the state — sending, unknown (with the endpoint), unreachable, the forms', () => {
        // The label chain: send-in-flight wins, then reachable picks
        // between the update and add forms, the quiet unknown names the
        // endpoint and offers the click-as-check, and a known-unreachable
        // endpoint (plus the no-settings case) carries the start-Anki
        // guidance.
        const labelSource = contentSource.slice(
            contentSource.indexOf('const ankiActionLabel'),
            contentSource.indexOf('const askAiLabel'),
        );
        expect(labelSource).toContain("t('word.anki_sending')");
        expect(labelSource).toContain("t('word.anki_unknown', {endpoint: ankiConnectEndpoint(ankiSettings)})");
        expect(labelSource).toContain("t('word.anki_unreachable')");
        expect(labelSource).toContain("t('word.anki_update')");
        expect(labelSource).toContain("t('word.anki_send')");
    });

    it('the prop defaults grey and the preview never probes — PopupContent stays inert', () => {
        // The probe lives in the outer WordPopup only (it needs the
        // ankiSettings prop and the loaded data): PopupContent just reads
        // the outcome through a false-defaulting prop, so the profile's
        // Popups-tab preview — which passes neither — renders the grey,
        // unprobing icon, same as today.
        expect(contentSource).toMatch(/ankiReachable = false,/);
        expect(contentSource).not.toContain('probeReachable');
        expect(contentSource).not.toContain('setAnkiReachable');
        expect(previewSource).not.toContain('ankiReachable');
        expect(previewSource).not.toContain('ankiConnect');
    });
});

describe('Send outcomes update reachability without a re-probe', () => {
    it('a completed send flips amber AND records the session success', () => {
        // The resolve handler's outcome (added / updated / foreign-model
        // duplicate) is itself reachability evidence — Anki answered every
        // action — so markAnkiReachable opens the ledger gate (later opens
        // probe for real again) behind the same dataRef guard the status
        // lines use.
        expect(resolveSource()).toContain('markAnkiReachable(ankiSettings)');
        expect(resolveSource()).toContain('setAnkiReachable(true)');
    });

    it('the send never re-probes — the outcome is the evidence', () => {
        expect(sendSource()).not.toContain('ankiConnectVersion');
    });

    it('a network-kind failure flips grey and closes the ledger gate — Anki never answered', () => {
        expect(failureSource()).toContain("error instanceof AnkiConnectError && error.kind === 'network'");
        expect(failureSource()).toContain('unmarkAnkiReachable(ankiSettings)');
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
