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

// The default portal's render: from the card-status selector (word-card
// ticket 02 — the old three-branch body, now one selector feeding the one
// WordCard) to the end of the shell.
const popupRenderSource = popupBody.slice(popupBody.indexOf('const status = cardStatus('));

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
        // …and the status selector leaves missing mode once an id exists —
        // the loading line shows while the payload loads, then the
        // dictionary view (cardStatus, table-tested in
        // lib/popupModel.test.js): the shell feeds it the looked-up id.
        expect(popupRenderSource).toContain('const status = cardStatus({data, wordId, lookedUpId, error, loading});');
    });

    it('the fetch effect’s early return clears the payload state — the missing branch is self-sufficient', () => {
        // The missing-card branch keys on `data == null`; without the clear
        // it only ever held because the popup unmounted between clicks. A
        // popup that stays mounted while the selection moves from a known
        // token (dictionary loaded) to a missing one must show the missing
        // card, not the previous token's stale dictionary — and none of its
        // loading/error state.
        expect(fetchEffectSource).toMatch(
            /if \(fetchId == null\) \{\s*setLoading\(false\);\s*setError\(null\);\s*setData\(null\);\s*setAiStarted\(false\);\s*return;\s*\}/,
        );
    });

    it('a miss — or a failed lookup — leaves the missing card with its live ask icon', () => {
        // The null-collapse lives in the lib module (pinned in the first
        // test); here: the null stores nothing (the missing branch holds),
        // and the card's ask icon only ever disables while the resolve is
        // in flight — on a miss it is as live as ticket 03 left it. The
        // icon stays keyed on the SINGLE column language: an ordered list
        // never enables adoption (ticket 03's contract). The gate lives in
        // the model's ask verdict (askAi.available — admin, a column
        // language, the resolve settled; behavioral in
        // lib/popupModel.test.js, "the missing card toolbar"); the shell
        // hands over the single column language and the in-flight resolve,
        // and the card's one ask dispatch.
        expect(popupRenderSource).toContain('languageCode: language?.code ?? null,');
        expect(popupRenderSource).toContain('resolving: lookupBusy,');
        expect(popupRenderSource).toContain('onAskAi: askAiAction,');
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

    it('shows the loading line while the resolve is in flight — the note only after it settles', () => {
        // ADR 0096 amendment: the not-in-dictionary note is a verdict, and
        // pronouncing it mid-resolve read as an empty popup that later
        // swapped itself. The card body holds the shared word.loading line
        // (the same one the detail fetch uses) while lookupBusy, then
        // settles to the note on a miss — or swaps to the dictionary view
        // on a hit (the fetch effect's own loading line).
        expect(popupRenderSource).toContain('resolving: lookupBusy,');
    });
});

describe('WordPopup: the popup-open penalty (word-popup-overhaul ticket 01)', () => {
    // The penalty effect: between the resolve effect's dependency list and
    // the Anki duplicate-detection comment.
    const penaltyEffectSource = popupBody.slice(
        popupBody.indexOf('const [resolvedFamiliarity'),
        popupBody.indexOf('// On-open duplicate detection'),
    );

    it('POSTs the resolved id through the shared lib helper — CSRF-signed, ledger-free', () => {
        // The lib POST (never a fetch literal in the component — the
        // /words/lookup pin's one-POST-literal contract, extended to the
        // opens helper).
        expect(popupSource).toContain("from '../lib/wordFamiliarity'");
        expect(penaltyEffectSource).toContain('recordWordOpens([lookedUpId])');
    });

    it('fires once per popup instance — keyed on the resolved id', () => {
        // The effect's only trigger is lookedUpId: null until THIS
        // instance's on-open resolve (or the 409 sync's re-resolve) lands a
        // hit, reset when the popup moves on — so a miss never fires, a
        // bound wordId never fires (WordText posts that one), and a repeat
        // open is a fresh instance applying again.
        expect(penaltyEffectSource).toMatch(/useEffect\(\(\) => \{\s*if \(lookedUpId == null\) \{\s*setResolvedFamiliarity\(null\);\s*return;\s*\}/);
        expect(penaltyEffectSource).toMatch(/\}, \[lookedUpId\]\);/);
    });

    it('best-effort like every event POST — a failure leaves the header at the stale score', () => {
        // The cancelled guard drops a late answer for a popup that moved;
        // an absent map entry (failed POST) never overwrites the score.
        expect(penaltyEffectSource).toContain('let cancelled = false;');
        expect(penaltyEffectSource).toContain('cancelled = true;');
        expect(penaltyEffectSource).toMatch(/if \(!cancelled && familiarity\[lookedUpId\] !== undefined\) \{\s*setResolvedFamiliarity\(familiarity\[lookedUpId\]\);\s*\}/);
    });

    it('the Anki note reports the new score — the shell calls the one lib helper', () => {
        // The AI panel's mount passes no familiarity prop, so the resolved
        // score becomes the note's value — the same slot the columns'
        // WordText patches through their own response handling. The
        // fallback ORDER (the penalty response wins, nullish so a 0 sticks)
        // is asserted behaviorally on lib/wordFamiliarity.js's
        // effectiveFamiliarity (lib/wordFamiliarity.test.js, word-card
        // review pass); here: the shell holds no copy of the derivation,
        // only the call, and feeds the send from it.
        expect(popupSource).toMatch(/import \{[^}]*\beffectiveFamiliarity\b[^}]*\} from '\.\.\/lib\/wordFamiliarity'/);
        expect(penaltyEffectSource).toContain('const noteFamiliarity = effectiveFamiliarity(resolvedFamiliarity, familiarity);');
        expect(popupSource).not.toMatch(/resolvedFamiliarity \?\? familiarity/);
        expect(popupBody).toContain('familiarity: noteFamiliarity,');
    });

    it('the card resolves its header from both values — the shell hands over the opening and the resolved score', () => {
        // word-card ticket 02: the header's slot is the model's own
        // resolution of the two, so header and Anki note read one value.
        expect(popupRenderSource).toMatch(/^ {24}familiarity,$/m);
        expect(popupRenderSource).toMatch(/^ {24}resolvedFamiliarity,$/m);
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
