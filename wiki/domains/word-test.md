---
type: Word Test
title: Word Test
description: CEFR-anchored adaptive placement test — a 60-word sample over the CEFR tag groups, scored against fixed band anchors on the 0-20000 scale, persisted raise-only in users.vocabulary_level, writing a raise-only presumed-known familiarity baseline.
tags: [word-test, placement, cefr, familiarity, frequency, inertia, react]
status: stable
stale_after: 2027-01-26
generated: { by: agent:zcode, at: 2026-10-27T23:15:00+03:00 }
verified: { by: agent:zcode, at: 2026-10-26T23:23:00+03:00 }
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
    resource: docs/adr/0090-cefr-anchored-word-test.md
    title: ADR 0090 The word test samples CEFR tag groups and persists a vocabulary level
  - id: adr-baseline
    resource: docs/adr/0071-word-test-presumed-known-baseline.md
    title: ADR 0071 Word test writes a presumed-known familiarity baseline (marking + server-held samples)
---

# What it is

A self-assessment page (`/word-test`, navbar link "Word test") that places
a learner on a language's frequency scale. It draws a fresh **Placement
sample** — 60 headwords evenly over the language's CEFR tag groups
(`a1`…`c2`, seeded by `CefrWordTagSeeder`, ADR 0076) — renders it as a flat
shuffled checklist, and on submit computes the **Word test score** (base +
per-band credits against fixed anchors, ADR 0090), persists the stored
**Vocabulary level** raise-only (`users.vocabulary_level`), and writes the
**Presumed-known baseline** (familiarity 60, raise-only, ADR 0071) over
every tested-language word at rank ≤ score. Retakes adapt: only the bands
at or above the stored level are tested. The vocabulary lives in
CONTEXT.md's Word Test context; the sampling/scoring/persistence decisions
in ADR 0090, the baseline-marking and server-held-sample decisions in
ADR 0071.

# Routes

- `GET /word-test` (`word-test.show`, `?lang=` optional enabled-language
  code, default `en`) — samples adaptively for the user's stored level;
  page props are `{languages, language, level, sample}`. `sample` is null
  when the language has no CEFR-tagged headwords (unseeded tags or a
  language without the lists) or the scale is exhausted — the page renders
  the "no CEFR data" empty state (every language but en today). There is
  no fallback sampler: one sampling path.
- `POST /word-test/submit` (`word-test.submit`) — `{token, known: int[]}`;
  the token resolves the server-cached sample (24 h TTL) and `known` must
  be a subset of it (Form Request closures; 422
  `wordtest.validation.expired` / `wordtest.validation.foreign_word`).
  Returns `{data: {score, level, marked}}` — `score` is this run's
  computed level, `level` the stored post-max value (≥ `score`), `marked`
  the affected `user_word` rows.

Both live in the `auth` + `approved` group of `routes/web.php`.

# Sampling and scoring

The anchors are fixed constants (`WordTestService::CEFR_ANCHORS`):
`a1→1000, a2→3000, b1→6000, b2→10000, c1→15000, c2→20000` — a band's width
is its anchor minus the previous one (sums to exactly 20 000); never
derived from the corpus (data-derived anchors would drift with seeder
updates and silently re-mean stored levels).

`WordTestService::sample(language, currentLevel)`:

- Tested bands = the slugs whose anchor is ≥ the stored level (all six at
  level 0; `b2`/`c1`/`c2` at 10 000; nothing above 20 000 → null).
- Quota = `SAMPLE_SIZE (60) / count(tested)` → 10/12/15/20/30/60 per band.
- One query per band (`taggedHeadwords()`): `tags.slug = $slug` joined
  through `tagged_words`, `DISTINCT ON (l_word)` keeping each spelling's
  most common word-class row. **No frequency filter** — tag membership
  defines the pool, unranked words are legitimate.
- Lower bands claim spellings first, so a spelling tagged in two levels is
  drawn at most once, in the lower band that claimed it.
- Any tested band with zero tagged headwords → null (test unavailable).
- Cache payload `{language_id, base, bands: slug=>word ids, all_word_ids}`
  under `word-test:sample:{uuid}` (24 h); the page receives only
  `{token, words: [{id, word}]}` flat-shuffled plus `groups` (the tested
  slugs, for its adaptive-retake note) — never the band layout.

`score()` is pure over the payload: `base + Σ round(width × known/drawn)`
per tested band, clamped to 20 000. `base` is the anchor before the lowest
tested band (0 when a1 is tested; 6 000 for a 10 000 retake). A checked
word above the final score is not marked — its band's partial credit still
lifts the score (ADR 0071's frontier rule, kept).

# Marking

`mark()` runs one set-based Postgres upsert-select:

```sql
INSERT INTO user_word (user_id, word_id, familiarity, created_at, updated_at)
SELECT ?, words.id, ?, now(), now() FROM words
WHERE words.language_id = ? AND words.frequency <= ?
ON CONFLICT (user_id, word_id) DO UPDATE
SET familiarity = GREATEST(user_word.familiarity, excluded.familiarity), updated_at = now()
```

with 60 = `UserWord::PLACEMENT_BASELINE`. Raise-only: higher existing
familiarity (organic reads/lookups/crosswords) survives; rows below 60 lift
to it; no rows, no history, no provenance (ADR 0071, decision 2 — the half
ADR 0090 preserves). Score 0 marks nothing. Sampling is tag-defined but
marking is rank-defined: a checked unranked word (1 100 000 sentinel)
lifts its band's credit yet is never marked — `≤ level` never matches it.

# Persistence and the level

`users.vocabulary_level` (`unsignedSmallInteger`, default 0 — safely holds
0–20 000) is the placement record. Submit persists
`max(old, score)` — a bad retake never lowers the stored level, the same
raise-only rule as familiarity marking. Consumers:

- the page: header current-level chip (once `level > 0`) and the result
  panel's primary number;
- adaptive sampling (the `sample(language, level)` input);
- the "hasn't taken the test" nudges: `has_no_familiarity` in
  `EntityController::show` and `RecommendationsController::index` is now
  `(int) user->vocabulary_level === 0` — a crossword-only user with plenty
  of `user_word` rows has never been placed (the prop key is unchanged;
  frontends render it read-only).

The column is deliberately language-agnostic because only English carries
CEFR tags; revisit per-user-per-language if another language gains lists.

# Frontend

`Pages/WordTest/WordTest.jsx` (`WordTest.layout` = Main): language select
(`router.visit('/word-test?lang=xx')`), 60 `CheckboxInput` rows, checked
counter, submit via `Pages/WordTest/api.js` (fetch + CSRF + Accept JSON,
Crossword pattern). A current-level chip (`wordtest.current_level` + the
number) sits next to the title once `level > 0`; an adaptive retake whose
`sample.groups` cover fewer than all six bands gets the
`wordtest.covers` note under the intro (slugs uppercased into a first–last
span, "B2–C2"). The result panel replaces the list: primary number is the
stored level `result.level / 20 000` (`wordtest.level_label`), a muted
`wordtest.kept_previous` line when this run scored below it
(raise-only made visible), `wordtest.marked {count}` secondary,
"take a new test" → `router.reload()`. State resets on `sample.token`
change because Inertia reuses the component across visits. Nav link "Word
test" is a separate top-level entry before Resources (`nav.word_test`);
all strings are DB-backed UI strings seeded from
`seeders/ui-strings/wordtest.php` (en/ru/be; `score_label` was removed
with the level-first result panel).

# Tests

- `tests/Unit/WordTestScoringTest.php` — the pure scorer (all-known →
  20 000, none → base, per-band rounding, unknown ids ignored, empty band
  skipped, clamp).
- `tests/Feature/WordTestPageTest.php` — guest redirect + unknown-lang,
  fresh 6×10 = 60 draw, page props (`level`, `sample.groups`), adaptive
  redistribution at a stored level (10 000 → b2/c1/c2 × 20, base 6 000),
  top-band-only draw at the scale top, cross-band spelling dedupe,
  no-CEFR-tags and empty-band null samples, exact score + marked set,
  raise-only persistence in both directions (weak retake keeps the stored
  level, strong one raises it), and the stale-token / foreign-word 422s.
