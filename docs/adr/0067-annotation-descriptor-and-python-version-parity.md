# ADR 0067: Annotation descriptor and python version parity

Date: 2026-10-05
Status: Accepted

## Context

ADR 0057 made the enrichment *analysis* unit declarative — one `Enricher`
class plus one registry entry — but only for the analysis layer. The
*display* layer stayed hand-wired: each annotation's value traveled under
five independently declared names (the `entity_sentences` column, the
Reading-row payload key, the `reader.*` and `simulator.*` ui_settings keys,
the camelCased page prop), joined only by matching strings. Adding a third
annotation meant touching ~22 sites across PHP, Python and JS, of which
~14 were pure wiring: validation rules, controller preference seeding, the
presenter's payload guard, the Filament preview columns, the frontend
toggles, the test fake's key list.

Two further frictions compounded it:

- **Version drift across the python boundary.** `Enricher::version()` (PHP)
  encoded facts about an algorithm living in another container — the v3
  directional guard of ADR 0059 exists as a PHP number and a
  `phrasal.py` change that only co-locate by convention. A python edit
  without the PHP bump produced different output for new sentences while
  already-stamped sentences silently kept old results: the stamp trusted
  the PHP number alone.
- **No drift guard.** Nothing failed when a layer's name diverged — except
  a pydantic 422 at first request, which catches only the dispatch key.

## Decision

**1. The Annotation is the display-side unit.** A readonly
`App\Classes\Enrichment\Annotation` DTO declares the reader-facing vertical
of one analysis: `payloadKey` (the Reading-row sentence key), `column`
(the `entity_sentences` column), `settingKey` (the ui_settings key both
surfaces read), and `adminPreview` (the Filament Sentences preview column
closure). `Enricher::column()` becomes `Enricher::annotation()`. One
Annotation can be fed by two Enrichers — the Russian and English stress
analyses share the stress-marks annotation, declared once as
`Annotation::stress()`. `EnricherRegistry::annotations()` hands out the
deduplicated set, and every derived site loops it instead of hand-listing:
`UpdateUiSettingsRequest` rules, `ReaderController`/`SimulatorController`
preference seeding (props keep today's names — camelCased from the setting
key), the single-language column select, `ReadingRowsPresenter`'s payload
guard (present only when the column holds data), the SentencesRelationManager
columns, and `SentenceEnrichmentService`'s write loop and token-payload
hints (a hint field no active enricher contributes is no longer sent — the
hardcoded `ipa`/`parts`/`stressed`/`verb_lemmas` list is gone).

**2. Python owns its algorithm versions; Laravel declares what it expects.**
Each python enrichment module exports `ALGORITHM_VERSION` (ru_stress 1,
en_stress 1, phrasal 3); `/enrich` responses carry
`versions: {enricher key: int}` alongside `results`. Each enricher declares
`pythonVersion(): int` — the value Laravel expects. `PythonClient::enrich()`
returns `{results, versions}`; `enrichChunk()` returns
`{written, versions}`, warns loudly on any mismatch, and the job stamps
what actually ran: stamps are now `{v, pv, at}`.

**3. Staleness compares both versions.** `staleFor()` additionally reads
the stamp's `pv` against the enricher's declared python version: a reported
version *older* than declared re-stales the entity (the sweep re-runs until
the two sides agree — bounded work, correct pressure); a *newer* reported
version is fine (newer results than declared are strictly more advanced).

**4. One-time corpus re-run.** Stamps written before this ADR carry no
`pv`; missing reads as 0, so the whole corpus goes stale exactly once at
deploy and the bounded five-minute sweep rewrites every stamp in the
versioned shape — accepted deliberately, and it end-to-end-validates the
parity machinery.

## Consequences

- Adding an annotation's display vertical is one descriptor; the ~14
  mechanical sites derive. What remains genuinely per-annotation: the
  analysis class, the python module, the i18n label text, the icon, the
  WordText rendering strategy, CSS.
- A python-side algorithm change without the matching `pythonVersion()`
  bump can no longer pass silently: the response mismatch warns, the stamp
  records what ran, and staleness re-derives — self-healing instead of
  silent drift. The mismatch log is the review signal; reconcile the two
  constants in the same PR.
- The frontend keeps hand-wired toggles this iteration (props, autosave
  keys, buttons unchanged) — the reading-surface chrome kit is a separate
  candidate; this ADR deliberately stops at the wire, which does not move.
- Test fakes report the declared versions; the vertical's drift guard is
  `tests/Feature/EnrichmentVerticalTest.php` — a new annotation missing
  from any derived site fails there.
- Deploy: python code changes without a `requirements.txt` change ship via
  the normal native-path deploy (venv pip-sync + `ext-python` restart);
  after it, expect the one-time sweep re-run across the corpus.
