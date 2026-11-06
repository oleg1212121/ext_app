# ADR 0066: vitest for pure-logic JS tests

Date: 2026-10-05
Status: Accepted

## Context

The frontend had no JS test runner at all: `package.json` scripts were only
`vite build`/`vite`, CI had no npm step, and `composer run test` is Pest-only.
The only JS code exercised by tests rode a different seam —
`lib/wordTokenizer.cli.mjs`, a Node CLI printing JSON that a Pest test
consumes (the tokenizer parity test).

Meanwhile the Bilinguals simulator page had grown into an 856-line module
whose logic-heavy parts (SSE stream parsing, `/text` payload mapping, page
clamping, position-store writes, ask-payload assembly) were untestable: they
lived inline in a React component, reachable only through a browser. An
architecture review (2026-10-05) proposed extracting them into engine
modules, and testing was the open question: with no runner, the extracted
logic would have moved without being pinned — churn without locality.

## Decision

**1. vitest runs pure-logic JS tests; React components are not covered.**
The suite pins `lib/` modules in Node only (`vitest.config.js` is standalone
and deliberately does not load the laravel-vite plugin or the app's
`vite.config.js`). No jsdom, no React testing library, no component tests:
the Inertia pages stay covered by the Pest feature suite (which renders
server-side responses) and manual verification. The rule of thumb: if a
module needs a DOM or React to test, it is either not pure enough to live in
`lib/` or it is wiring, and wiring is not unit-test territory here.

**2. Pure logic lives in `lib/`, marked `.mjs` when it must stay
framework-free.** The extraction created `lib/sseStream.mjs`,
`lib/simulatorText.mjs`, `lib/aiAsk.mjs`, plus pure additions to
`lib/simulatorPosition.js` and `lib/http.js`. Tests are colocated
(`lib/*.test.{js,mjs}`) and run with `npm run test` (vitest run) inside the
container, per the docker-exec rule.

**3. CI gets its own `frontend` job; `composer run test` is not chained.**
The job checks out, sets up Node, `npm ci`, and `vitest run` — no PHP, no
database, no asset build. It is deliberately NOT wired into
`composer run test`: the Pest suite must not gain an npm dependency (it runs
without Vite today, and PHP-only changes should not pay an npm install).
CI runs both jobs on the same triggers as before.

## Consequences

- Extracted logic is pinned: the SSE parser's boundary/sentinel/partial-JSON
  semantics, the `/text` snake→camel mapping and defaults, the page clamp,
  the position-store flip migration, the envelope error chain, and the ask
  payload's legacy asterisk strip (pinned verbatim — see `lib/aiAsk.mjs`).
- Engine hooks (`useSimulatorText`, `useAiStream`,
  `useAssessmentQuestion`) stay untested by vitest by design: they are React
  wiring over the pinned pure modules.
- `package-lock.json` is now load-bearing for CI (`npm ci` + cache).
- The next pure extraction (reading-surface chrome, candidate 6 of the
  architecture review) has a home to test in.

## Amended 2026-10-23 (source pins: derived logic is evaluated, never matched verbatim)

Consequence 1's "pinned verbatim" splits into two pin kinds now that the
suite blesses Components-side source pins (popup JSX read as text): a pin
asserting **derived logic** must lift the real expression out of the
component source and evaluate it against payload states (the exemplar,
`Components/WordPopup.sourceBadge.test.js`, regex-lifts the badge
derivation and evaluates it via `new Function` against the payload
states); verbatim-contains pins are allowed only for **wiring/structure**
— pass-throughs, class hooks, presence/absence (exemplar:
`Components/WordPopup.ankiSend.test.js`). The incident that forces the
split: the "ai · ai" badge bug passed its own verbatim-contains pin — the
pin asserted the buggy expression byte-for-byte, so red-green was
impossible for that logic error and only the review pass caught it
(fixed 2026-10-20, `cab5280`). The decision above is unchanged: logic
that CAN be imported from `lib/` is still tested behaviorally; the
lift-and-evaluate form exists for the components that sit outside
vitest's behavioral reach.

## Amended 2026-11-02 (page-local component pins; the hook-pin exception retired)

Two clarifications from the ai-answer-words review. First, "Components-side"
in the 2026-10-23 amendment means component files wherever they live:
page-local components under `Pages/*/Components/` sit under the same
two-kind rule, exemplar `Pages/Bilinguals/Components/AI.tokens.test.js`
(wiring pins over the AI panel's delegation + portal mount; its decisions
are behaviorally tested in `lib/aiAnswerTokens.test.mjs`). Second, the
amendment's closing rule was applied to the suite's one Pages-**hook** pin:
`useAiStream.answerLanguages.test.js` had pinned a verbatim snapshot line
inside the hook — derived logic sitting where vitest cannot reach it
behaviorally. The derivation moved to `lib/aiAnswerTokens.mjs`
(`answerLanguagesFromPayload`, Node-tested) and the hook imports it; the
hook pin is deleted. Hooks are untested React wiring again, exactly as
Decision 1 has always said.

## Amended 2026-11-06 (lift-and-evaluate narrows to logic that must stay in a component; the badge exemplar goes behavioral)

The 2026-10-23 amendment's lift-and-evaluate clause narrows. It covers
only derived logic that **must** remain inside a component because it is
inseparable from React state, effects or wiring. Derived logic that
**can** move to `lib/` moves there and is tested behaviorally — the
2026-10-23 closing rule ("logic that CAN be imported from `lib/` is still
tested behaviorally") is now the requirement, and the lift-and-evaluate
form is the fallback for the remainder, not a peer choice. The word-card
tickets (01–03 and their review pass) are the case that forced the
reading: the word card's whole derived surface — the provenance badge,
the effective familiarity, the Anki label chain and color token, the
explain gating, the ask-AI label and availability, the missing card's
wordless icons, the per-sense usage chips and dims, the Models used
popup's props — left `Components/WordPopup.jsx` for one pure function,
`lib/popupModel.js`'s `buildCardModel`, which takes the card's state
object plus `t` and returns toolbar descriptors, the header model, the
entry list (each entry with its senses), the Models used props, the body
line and the info popup's notices. The shell's card-status selector moved
beside it (`cardStatus`, table-tested), and the effective familiarity the
shell's Anki send needs is one helper, `lib/wordFamiliarity.js`'s
`effectiveFamiliarity`, which the model calls too. The component
(`Components/WordCard.jsx`) maps descriptors to JSX and derives nothing,
and the shell holds no copy of a derivation, so there is nothing left in
either to lift.

The exemplar changes accordingly. The 2026-10-23 exemplar,
`Components/WordPopup.sourceBadge.test.js`'s regex-lifted badge
derivation evaluated via `new Function`, retired with word-card ticket
02: the derivation lives in `lib/popupModel.js` and is asserted
behaviorally in `lib/popupModel.test.js` ("the provenance badge" — the
importer verbatim, the trailing "ai" part, "ai" alone, the adopt-on-ask
dedupe that keeps "ai · ai" out, the foreign importer kept honest, the
empty badge, and the missing card never badged). That suite is the
behavioral exemplar now — the same "ai · ai" regression that motivated
the 2026-10-23 split is the test a lift could only approximate. What
`WordPopup.sourceBadge.test.js` holds today is structure only: the card
renders the model's badge (`card.header.sourceBadge.map(`), one chip per
part, at one site inside a section label. **No lift-and-evaluate pin
remains in the repo.** The last one, `Components/WordPopup.lookup.test.js`'s
`effectiveFamiliarity` lift over the shell's send-cluster copy, retired
in the word-card review pass: the derivation was liftable to `lib/` after
all, so it moved (`lib/wordFamiliarity.js`, asserted behaviorally in
`lib/wordFamiliarity.test.js` — the penalty response wins, a resolved 0
sticks, an unmarked word is null) and both the shell and the model call
it. The exemplar for derived logic is therefore the behavioral `lib/`
suites — `lib/popupModel.test.js`, `lib/wordFamiliarity.test.js` —
and the lift form stays on the books only as the fallback for a future
derivation that genuinely cannot leave a component.

Everything else stays as written. Decision 1 is unchanged: vitest runs
pure-logic tests in Node, no jsdom, no component rendering. Decision 2 is
unchanged and, if anything, sharpened: pure logic lives in `lib/`, and a
view-model with an injected `t` (the `lib/ankiTemplate.js` `uiLabels(t)`
precedent) is pure logic. The two-kind rule itself is unchanged —
verbatim-contains pins stay allowed for **wiring/structure** only
(pass-throughs, class hooks, presence/absence; exemplar still
`Components/WordPopup.ankiSend.test.js`), and a verbatim pin over a
derivation is still the failure mode the 2026-10-23 incident named. The
2026-11-02 clarifications (page-local components under the same rule;
hooks never pinned) are unchanged.
