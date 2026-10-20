import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// popup-ai-enrich ticket 07 — missing-word re-resolution (ADR 0066 source
// pins, the WordText.missing.test.js pattern): components sit outside
// vitest's behavioral reach, so the decisions are pinned as source
// assertions on WordPopup.jsx. What they lock: a missing-mode popup with a
// column language descriptor resolves its RAW surface server-side (POST
// /words/lookup, one shared helper, CSRF-signed) BEFORE the missing card
// settles — a hit feeds the existing fetch effect (`wordId ?? lookedUpId`),
// so the minted word a previous adopt-on-ask left behind opens the full
// dictionary view with `ai_asked` grey, instead of a re-ask that could only
// 409; a null (miss) — or a failed lookup — leaves ticket 03's card
// untouched, and no lookup fires without a language descriptor. Stale
// answers (popup closed / surface moved) drop through the fetch effect's
// cancelled guard. A 409 from the ask ("already been completed" / "in
// progress") syncs the popup with the ledger instead of leaving the icon
// live: the known path refetches the payload, the missing card — whose
// adopted word id the client never learns — re-runs its lookup.

const popupSource = readFileSync(new URL('./WordPopup.jsx', import.meta.url), 'utf8');

const popupBody = popupSource.slice(popupSource.indexOf('export default function WordPopup'));

// The shared lookup POST helper (module scope, above the component).
const postLookupSource = popupSource.slice(
    popupSource.indexOf("const postLookup = (surface, languageCode) => fetch('/words/lookup'"),
    popupSource.indexOf('export default function WordPopup'),
);

// The detail fetch effect: from its useEffect opener to the fetch itself.
const fetchAt = popupBody.indexOf('fetch(`/words/${fetchId}?surface=');
const fetchEffectSource = popupBody.slice(
    popupBody.lastIndexOf('useEffect(() => {', fetchAt),
    fetchAt,
);

// The re-resolution effect: between the fetch effect's dependency list and
// the Anki detection effect that follows it.
const lookupEffectSource = popupBody.slice(
    popupBody.indexOf('}, [wordId, lookedUpId, surface, t]);'),
    popupBody.indexOf('// On-open duplicate detection'),
);

// runEnrich: the shared ask POST, including the 409 sync in its catch.
const runEnrichSource = popupBody.slice(
    popupBody.indexOf('const runEnrich = (body, origin) => {'),
    popupBody.indexOf('const askAi = () => {'),
);

// The default portal's render branch.
const popupRenderSource = popupBody.slice(popupBody.indexOf('return createPortal('));

describe('WordPopup: the on-open lookup (ticket 07)', () => {
    it('POSTs /words/lookup through one shared helper — CSRF-signed, RAW surface + column language code', () => {
        // One POST literal in the whole file: the helper. Both callers (the
        // on-open effect, the 409 sync) go through it.
        expect(popupBody.match(/fetch\('\/words\/lookup'/g)).toBeNull();
        expect(postLookupSource).toContain("fetch('/words/lookup'");
        expect(postLookupSource).toContain('X-CSRF-TOKEN');
        // The server derives the lookup key from the RAW surface — the
        // client never folds or strips.
        expect(postLookupSource).toContain('body: JSON.stringify({surface, language_code: languageCode})');
    });

    it('fires on missing open with a language descriptor only', () => {
        // The guard precedes the POST: a bound word id needs no resolve,
        // and a column without a language descriptor has nothing valid to
        // resolve with — no request fires (the ask's own condition).
        expect(lookupEffectSource).toMatch(/if \(wordId != null \|\| language\?\.code == null\) \{\s*return;\s*\}/);
        expect(lookupEffectSource).toContain('postLookup(surface, language.code)');
    });

    it('a hit flips the popup into known mode through the existing fetch effect', () => {
        // The fetch id is the bound word OR the looked-up id — the payload
        // loads exactly like a mapped token's, so the ledger's ai_asked
        // greys the icon for the already-asked word.
        expect(fetchEffectSource).toMatch(/const fetchId = wordId \?\? lookedUpId;/);
        expect(fetchEffectSource).toMatch(/if \(fetchId == null\) \{\s*return;\s*\}/);
        // The lookup effect stores the resolved id…
        expect(lookupEffectSource).toContain('setLookedUpId(found)');
        // …and the render branch leaves missing mode once an id exists —
        // the loading line shows while the payload loads, then the
        // dictionary view.
        expect(popupRenderSource).toMatch(/wordId == null && lookedUpId == null && data == null \? \(/);
    });

    it('a miss — or a failed lookup — leaves the missing card with its live ask icon', () => {
        // postLookup collapses a miss and a transport failure into one
        // null…
        expect(postLookupSource).toMatch(/\.catch\(\(\) => null\)/);
        // …the null stores nothing (the missing branch holds), and the
        // card's ask icon only ever disables while the resolve is in
        // flight — on a miss it is as live as ticket 03 left it.
        const missingBranch = popupRenderSource.slice(
            popupRenderSource.indexOf('wordId == null && lookedUpId == null && data == null ? ('),
            popupRenderSource.indexOf(') : ('),
        );
        expect(missingBranch).toContain('onAskAi={language?.code != null && !lookupBusy ? askAiMissing : null}');
    });

    it('drops stale answers — popup closed or surface moved — like the fetch effect', () => {
        expect(lookupEffectSource).toContain('let cancelled = false;');
        expect(lookupEffectSource).toMatch(/if \(!cancelled\) \{\s*setLookupBusy\(false\);\s*setLookedUpId\(found\);\s*\}/);
        expect(lookupEffectSource).toContain('cancelled = true;');
    });

    it('holds the ask icon disabled while the resolve is in flight', () => {
        expect(lookupEffectSource).toContain('setLookupBusy(true)');
        expect(lookupEffectSource).toContain('setLookupBusy(false)');
    });
});

describe('WordPopup: the 409 ask sync (ticket 07)', () => {
    it('refetches the word payload on a known-path 409 so ai_asked greys the icon', () => {
        expect(runEnrichSource).toContain('if (e.status === 409)');
        // The refetch is the same word-show GET the payload came from…
        expect(runEnrichSource).toContain('fetch(`/words/${origin.data.id}?surface=${encodeURIComponent(surface)}`');
        // …quietly swapped in under the same stale guard the ask answers
        // use — a popup that moved on never adopts the refetch.
        expect(runEnrichSource.match(/staleAsk\(origin\)/g)).toHaveLength(4);
    });

    it('re-resolves instead on the missing path — the adopted word id is unknown client-side', () => {
        // The adopt-on-ask 409 carries no payload, so there is no id to
        // refetch: the lookup resolves the minted word and the fetch
        // effect loads its locked payload (ai_asked true).
        expect(runEnrichSource).toContain('postLookup(surface, language.code)');
        expect(runEnrichSource).toContain('setLookedUpId(found)');
    });
});
