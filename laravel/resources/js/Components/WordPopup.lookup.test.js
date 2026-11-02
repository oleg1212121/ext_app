import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// popup-ai-enrich ticket 07 — missing-word re-resolution (ADR 0066 source
// pins, the WordText.missing.test.js pattern): components sit outside
// vitest's behavioral reach, so the decisions are pinned as source
// assertions on WordPopup.jsx and — since ai-answer-words ticket 02 — on
// the lib/wordLookup.js module the POST moved into (the resolver's
// order/short-circuit/fallthrough/failure-collapse semantics are pinned
// behaviorally there, with fetch mocked). What they lock: a missing-mode
// popup resolves its RAW surface server-side (POST /words/lookup through
// the shared module, CSRF-signed) BEFORE the missing card settles — a hit
// feeds the existing fetch effect (`wordId ?? lookedUpId`), so the minted
// word a previous adopt-on-ask left behind opens the full dictionary view
// with `ai_asked` picking the re-ask icon variant; a null (a genuine miss)
// — or a failed lookup — leaves ticket 03's card
// untouched, and no lookup fires without a language. Stale answers (popup
// closed / surface moved) drop through the fetch effect's cancelled guard.
// A 409 from the ask (the "in progress" gate) syncs the popup with the
// ledger instead of leaving the ask state stale: the known path refetches
// the payload, the missing card — whose
// adopted word id the client never learns — re-runs its lookup.

const popupSource = readFileSync(new URL('./WordPopup.jsx', import.meta.url), 'utf8');

const popupBody = popupSource.slice(popupSource.indexOf('export default function WordPopup'));

// The shared lookup POST module (ai-answer-words ticket 02): the sequential
// resolveWordId over an ordered language list — a single-code list is the
// exact old postLookup contract (the review pass unexported the standalone
// single-code wrapper).
const lookupLibSource = readFileSync(new URL('../lib/wordLookup.js', import.meta.url), 'utf8');

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

// The resolve-list key: the prop's ordered codes when given, else the
// single column language — keyed as a primitive string so an inline-array
// prop cannot re-trigger the effect.
const codesKeySource = popupBody.slice(
    popupBody.indexOf('const lookupCodesKey'),
    popupBody.indexOf('// The on-open re-resolution (popup-ai-enrich ticket 07): in missing'),
);

// runEnrich: the shared ask POST, including the 409 sync in its catch.
const runEnrichSource = popupBody.slice(
    popupBody.indexOf('const runEnrich = (body, origin) => {'),
    popupBody.indexOf('const askAi = () => {'),
);

// The default portal's render branch.
const popupRenderSource = popupBody.slice(popupBody.indexOf('return createPortal('));

describe('WordPopup: the on-open lookup (ticket 07)', () => {
    it('POSTs /words/lookup through one shared lib module — CSRF-signed, RAW surface + language code', () => {
        // One POST literal on the whole path the popup uses: the lib
        // module. Both popup callers (the on-open effect, the 409 sync) go
        // through its resolver — no lookup fetch anywhere in the component
        // file, module scope included.
        expect(popupSource).not.toContain("fetch('/words/lookup'");
        expect(popupSource).toContain("from '../lib/wordLookup'");
        expect(lookupLibSource).toContain("fetch('/words/lookup'");
        expect(lookupLibSource).toContain('X-CSRF-TOKEN');
        // The server derives the lookup key from the RAW surface — the
        // client never folds or strips.
        expect(lookupLibSource).toContain('body: JSON.stringify({surface, language_code: languageCode})');
        // The collapse contract (a transport failure resolves null, never an
        // error state): the resolver's try/catch — a throw stops the whole
        // sequence with null; the behavioral pins live in
        // lib/wordLookup.test.js.
        expect(lookupLibSource).toMatch(/catch \{\s*return null;/);
    });

    it('resolves against the ordered list when given, else the single column language', () => {
        // ai-answer-words ticket 02: the prop wins outright — an empty list
        // means nothing to resolve — and without the prop the key is
        // exactly the clicked column's code, the pre-list behavior.
        expect(codesKeySource).toContain('languageCodes != null');
        expect(codesKeySource).toContain("languageCodes.join('|') || null");
        expect(codesKeySource).toContain('language?.code ?? null');
    });

    it('fires on missing open with a resolve list only', () => {
        // The guard precedes the resolve: a bound word id needs no resolve,
        // and no codes at all (no prop, no column language) has nothing
        // valid to resolve with — no request fires (the ask's own
        // condition).
        expect(lookupEffectSource).toMatch(/if \(wordId != null \|\| lookupCodesKey == null\) \{\s*return;\s*\}/);
        expect(lookupEffectSource).toContain("resolveWordId(surface, lookupCodesKey.split('|'))");
    });

    it('a hit flips the popup into known mode through the existing fetch effect', () => {
        // The fetch id is the bound word OR the looked-up id — the payload
        // loads exactly like a mapped token's, so the ledger's ai_asked
        // picks the re-ask icon variant for the already-asked word.
        expect(fetchEffectSource).toMatch(/const fetchId = wordId \?\? lookedUpId;/);
        expect(fetchEffectSource).toMatch(/if \(fetchId == null\) \{/);
        // The lookup effect stores the resolved id…
        expect(lookupEffectSource).toContain('setLookedUpId(found)');
        // …and the render branch leaves missing mode once an id exists —
        // the loading line shows while the payload loads, then the
        // dictionary view.
        expect(popupRenderSource).toMatch(/wordId == null && lookedUpId == null && data == null \? \(/);
    });

    it('the fetch effect’s early return clears the payload state — the missing branch is self-sufficient', () => {
        // The missing-card branch keys on `data == null`; without the clear
        // it only ever held because the popup unmounted between clicks. A
        // popup that stays mounted while the selection moves from a known
        // token (dictionary loaded) to a missing one must show the missing
        // card, not the previous token's stale dictionary — and none of its
        // loading/error state.
        expect(fetchEffectSource).toMatch(
            /if \(fetchId == null\) \{\s*setLoading\(false\);\s*setError\(null\);\s*setData\(null\);\s*return;\s*\}/,
        );
    });

    it('a miss — or a failed lookup — leaves the missing card with its live ask icon', () => {
        // The null-collapse lives in the lib module (pinned in the first
        // test); here: the null stores nothing (the missing branch holds),
        // and the card's ask icon only ever disables while the resolve is
        // in flight — on a miss it is as live as ticket 03 left it. The
        // icon stays keyed on the SINGLE column language: an ordered list
        // never enables adoption (ticket 03's contract).
        const missingBranch = popupRenderSource.slice(
            popupRenderSource.indexOf('wordId == null && lookedUpId == null && data == null ? ('),
            popupRenderSource.indexOf(') : ('),
        );
        expect(missingBranch).toContain('onAskAi={language?.code != null && !lookupBusy ? askAiMissing : null}');
    });

    it('drops stale answers — popup closed or surface moved — like the fetch effect', () => {
        // The cancelled guard spans the whole sequence: a resolver that ran
        // three codes still drops its answer for a popup that moved.
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
    it('refetches the word payload on a known-path 409 — a harmless sync with the ledger', () => {
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
        // effect loads its payload (ai_asked true, the re-ask icon). The
        // single code the ask itself carried — the ask flow stays keyed on
        // the column language; the ordered list never widens it.
        expect(runEnrichSource).toContain('resolveWordId(surface, [language.code])');
        expect(runEnrichSource).toContain('setLookedUpId(found)');
    });
});
