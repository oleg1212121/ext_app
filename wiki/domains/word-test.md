---
type: Word Test
title: Word Test
description: Frequency-rank placement test — a 50-word sample over the ranked inventory, scored 0-20000, writing a raise-only presumed-known familiarity baseline.
tags: [word-test, placement, familiarity, frequency, inertia, react]
status: stable
stale_after: 2026-12-26
generated: { by: agent:zcode, at: 2026-10-06T18:00:00+03:00 }
sources:
  - id: controller
    resource: laravel/app/Http/Controllers/WordTestController.php
    title: WordTestController
  - id: service
    resource: laravel/app/Classes/WordTestService.php
    title: WordTestService (sampling, scoring, marking)
  - id: page
    resource: laravel/resources/js/Pages/WordTest/WordTest.jsx
    title: Inertia page
  - id: adr
    resource: docs/adr/0071-word-test-presumed-known-baseline.md
    title: ADR 0071 Word test writes a presumed-known familiarity baseline
---

# What it is

A self-assessment page (`/word-test`, navbar link "Word test") that places
a learner on a language's frequency scale. It draws a fresh **Placement
sample** — 50 headwords spread over 20 equal-count **Buckets** of the
language's ranked inventory (`words.frequency` ≤ 20 000, distinct per
`l_word`) — renders it as a flat shuffled checklist, and on submit computes
the **Word test score** (each bucket credits 1000 × its known share) and
writes the **Presumed-known baseline** (familiarity 50, raise-only) over
every tested-language word at rank ≤ score. See ADR 0071 for the trade-offs
and CONTEXT.md's Word Test context for the vocabulary.

# Routes

- `GET /word-test` (`word-test.show`, `?lang=` optional enabled-language
  code, default `en`) — draws the sample; languages without enough ranked
  headwords (< `WordTestService::MIN_HEADWORDS`) serve a null sample, which
  the page renders as the "no frequency data" empty state (ru today).
- `POST /word-test/submit` (`word-test.submit`) — `{token, known: int[]}`;
  the token resolves the server-cached sample (24 h TTL) and `known` must
  be a subset of it (Form Request closures), else 422. Returns
  `{data: {score, marked}}`.

Both live in the `auth` + `approved` group of `routes/web.php`.

# Sampling and scoring

`WordTestService::sample()` orders the language's ranked headwords by
frequency (Postgres `DISTINCT ON (l_word)`, rank cast to float8 for
sorting), cuts them into 20 equal-count buckets and draws 2–3 random
headwords per bucket (round-based sizes summing to 50). The bucket layout
— `language_id`, per-bucket word ids, the flat id list — goes into the
cache under `word-test:sample:{uuid}`; the page receives only
`{token, words: [{id, word}]}` shuffled.

`score()` is pure over that payload: per bucket
`round(1000 × known/sampled)`, summed and clamped to 20 000. A checked word
above the final score is not marked — its bucket's partial credit still
lifts the score (ADR 0071, decision 1).

# Marking

`mark()` runs one set-based Postgres upsert-select:

```sql
INSERT INTO user_word (user_id, word_id, familiarity, created_at, updated_at)
SELECT ?, words.id, ?, now(), now() FROM words
WHERE words.language_id = ? AND words.frequency <= ?
ON CONFLICT (user_id, word_id) DO UPDATE
SET familiarity = GREATEST(user_word.familiarity, excluded.familiarity), updated_at = now()
```

with 50 = `UserWord::PLACEMENT_BASELINE`. Raise-only: higher existing
familiarity (organic reads/lookups/crosswords) survives; rows below 50 lift
to it; no rows, no history, no provenance (ADR 0071, decision 2). Unranked
words (1 100 000) are excluded by the `≤ 20 000` comparison. Score 0 marks
nothing.

# Frontend

`Pages/WordTest/WordTest.jsx` (`WordTest.layout` = Main): language select
(`router.visit('/word-test?lang=xx')`), 50 `CheckboxInput` rows, checked
counter, submit via `Pages/WordTest/api.js` (fetch + CSRF + Accept JSON,
Crossword pattern). The result panel replaces the list (score, marked
count, "take a new test" → `router.reload()`); state resets on
`sample.token` change because Inertia reuses the component across visits.
Nav link "Word test" is a separate top-level entry before Resources
(`nav.word_test`); all strings are DB-backed UI strings seeded from
`seeders/ui-strings/wordtest.php`.

# Tests

- `tests/Unit/WordTestScoringTest.php` — the pure scorer (credits,
  rounding, unknown ids, empty buckets).
- `tests/Feature/WordTestPageTest.php` — guest redirect, page props
  (sample of 50, bucket layout in cache), headword dedupe and language
  isolation of the sample, empty state, exact score + marked set for a
  crafted 120-headword inventory, raise-only marking, empty-known submit,
  stale-token and foreign-word 422s.
