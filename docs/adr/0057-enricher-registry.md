# ADR 0057: Enricher registry — per-language algorithms and per-enricher staleness

Date: 2026-10-02
Status: Accepted

## Context

ADR 0052 shipped sentence enrichment (stress marks ru/en, phrasal verbs en)
as a single hardwired pipeline: the `ENRICHABLE_LANGUAGES` const plus inline
`if ($language === ...)` branches in `SentenceEnrichmentService`, the job,
the sweep command, and the python `/enrich` router (which also hardcoded the
`^(ru|en)$` request pattern). The behavior — phrasal verbs only for English
— was correct, but the structure had two problems:

- **Adding an algorithm meant editing shared code in several layers.** The
  planned example: a new English analysis (say, more phrasal-verb nuance or
  an additional English word-level study) must be excluded from Russian
  processing, and nothing in the code said where to slot it in.
- **Retro-processing was manual.** ADR 0053's v1→v2 switchover had to
  instruct operators to reset `entities.enriched_at` so already-enriched
  entities would re-run. The single staleness stamp cannot distinguish
  "stress marks computed under v1" from "phrasal verbs computed at all" —
  any new processor either re-runs everything (wasteful on a large corpus)
  or nothing.

Phrasal verbs were also still invisible outside the Filament preview (ADR
0052 scoped reader/simulator rendering to "DB + Filament preview only this
iteration") — no controller selected `phrasal_verbs`, no prop carried it,
no React component rendered it.

## Decision

**1. Enrichers (the unit of variation).** Each enrichment analysis is its
own class in `App\Classes\Enrichment` implementing the `Enricher` interface:
`key()` (python dispatch key + stamp map key), `languages()` (the declared
applicability), `column()` (the `entity_sentences` column it writes),
`tokenHints()` (per-token dictionary hints it contributes), `requestExtras()`
(request-level payload, e.g. the phrasal lexicon), and `toStorage()`
(python output → column value). The manifest lives in `EnricherRegistry`:
`forLanguage(code)` answers "what should be included in the enrichment
process for this language" — Russian entities get `ru_stress`, English
entities get `en_stress` + `en_phrasal`, and a new English-only class is
one file plus one registry entry. Selection is declarative in code: no
config files, no DB flags.

**2. One keyed python call per chunk.** The request gains
`enrichers: ["ru_stress" | "en_stress" | "en_phrasal"]` (pydantic `Literal`
— validity = dispatchability) and drops the `language` pattern; each key
dispatches to its module and the response returns `results[].output` keyed
by enricher key. One HTTP round trip per batch is preserved, and a partial
run (only the stale enrichers) skips the other modules' work. The internal
`ru_stress` / `en_stress` / `phrasal` modules are unchanged except that a
phrasal hit now also carries `phrase` — the lexicon headword the match came
through ("gave up" → "give up") — for the reader tooltip.

**3. Per-enricher staleness.** The single `entities.enriched_at` becomes
`entities.enrichment_stamps` jsonb: `{enricher key: ISO timestamp}`. The
registry's `staleFor(entity)` returns the language's enrichers whose stamp
is missing (a newly registered enricher is automatically stale) or older
than the last sentence change. The 5-minute sweep dispatches exactly the
stale set (via `EnrichEntitySentences::beginEnrichers`); the Filament
"Enrich" action and the upload pipeline keep full runs (`begin()`). Marks
are written with a jsonb `||` merge so concurrent stampers cannot clobber
each other. Backfill: already-enriched entities stamp every enricher of
their language at the old timestamp; never-enriched enrichable entities
stay null (the sweep rebuilds them); languages with no enrichers get `{}`.
`enriched_at` is dropped. New-enricher rollout is now automatic — no manual
stamp reset, and unrelated enrichers do not re-run.

**4. Phrasal verbs render on the reading surfaces.** Reader and simulator
ship `phrasalRows` parallel to `stressedRows` (per side: one hit list per
sentence, or null when the side has none), flipped with the reading/learning
side like every row-aligned payload. WordText marks the tokens each hit's
span covers with a dotted underline (`phrasal-hit`, verdigris) and the
matched `phrase` as tooltip; hit spans index `content`, so token indexes
are computed from the original sentence and transfer to the stressed
variant (marks attach inside tokens — the token sequence is unchanged).
It is a per-user preference (`phrasal_verbs` in the reader + simulator
`ui_settings` sections, autosaved, default off), gated like stress marks on
data existing at all. The Filament Sentences "Phrasal verbs" column is now
visible by default.

## Consequences

- Adding an enrichment algorithm = one `Enricher` class + one registry
  entry; the sweep backfills it for its languages on the next tick without
  re-running the others. A migration is only needed when the algorithm
  writes a new `entity_sentences` column.
- The python request schema is now driven by a `Literal` union — a Laravel
  registry key without a python module fails validation at the first
  request instead of silently skipping.
- Spans on hits index `content` (never `stressed_content`), and the two
  stress enrichers share the `stressed_content` column — only one can apply
  to a given language, which the registry structure makes visually obvious.
- No python dependency change: no image rebuild / `--stamp` for this ADR.
- Tests: registry language mapping, per-enricher retro-run, ru-never-gets-
  phrasal payload, phrasal-only run skipping stress hints, reader/simulator
  shipping `phrasalRows`, and the python keyed dispatch (module tests
  updated for the `phrase` field).
