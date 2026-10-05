# ADR 0069: One JSON envelope for the reading-surface endpoints

Date: 2026-10-05
Status: Accepted

## Context

The reading surfaces' JSON endpoints answered in two shapes. `POST
/word-events` was flat: `{'data': {...}}` on success, HTTP status for
errors. Everything else — `POST /text`, `POST /ai/question`,
`/ai/question/stream` (pre-stream), `/ai/word-explain` — wrapped twice,
`['data' => ['data' => ..., 'code' => ...]]`, a shape no ADR ever decided:
errors landed at `data.data.error` while successes read `data.answer`, the
mirrored `code` key duplicated the HTTP status, and four client sites
(`lib/http.js`, `lib/simulatorText.mjs`, `useAiStream.js`, `WordPopup.jsx`)
each dug out the double envelope.

Separately, `SimulatorController` had become four controllers in one —
page props, the `/text` payload, the assessment ask/stream, and the word
explain — of which only the first two are actually simulator-specific; the
word explain serves the reader's word popup identically.

## Decision

**One envelope for the reading-surface JSON endpoints, matching the flat
`/word-events` convention:**

- Success: `{'data': {...}}` with the HTTP status carrying the code — the
  mirrored `code` key is gone.
- Errors: `{'error': message}` plus the proper status. In-stream SSE errors
  stay SSE events (`data: {"error": ...}`), as before.
- The `/text` payload's inner keys are untouched (ADR 0060's wire:
  `rows`, `word_maps`, `highlightable`, `explainable`, `languages`,
  `default_learning_side` — snake_case, the client renames); only the
  wrapping changed.

**The AI answers get their own controller.** `askAi`, `askAiStreamed`, and
`explainWord` move from `SimulatorController` to
`App\Http\Controllers\ReadingAiController` — the reading surfaces' shared
AI-answer seam. Routes keep byte-identical paths, names, and throttle
middleware; only the handler string changed, so no client, bookmark, or
literal-path test is affected. `SimulatorController` keeps the page props
and `/text`, and both surface controllers constructor-inject
`EntityAccessService` instead of instantiating it per call.

**Saved-settings seeding is one module.** `App\Support\SavedUiSettings`
owns the section read, the clamped int seeding, the bool seeding, and the
ADR 0067 annotation-preference loop that both surfaces previously
duplicated; the exact defaults and clamping ranges are unchanged.

## Consequences

- A caller learns one response shape: `data` on success, `error` on
  failure, status everywhere. The client dig sites collapsed to
  `json?.error ?? json?.message`.
- Success and error shapes no longer disagree per endpoint — previously the
  same endpoint answered `data.answer` on success and `data.data.error` on
  failure.
- The AI answer endpoints are named for what they serve (reading
  surfaces), not where they were first wired; the wiki's entry-point
  tables follow.
- `lib/http.js`'s `responseErrorMessage` dig is pinned by its vitest test;
  envelope and dig move together in one commit, which this ADR requires
  for any future change.
