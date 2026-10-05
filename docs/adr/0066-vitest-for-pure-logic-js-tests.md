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
