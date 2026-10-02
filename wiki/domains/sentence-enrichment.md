---
type: Pipeline
title: Sentence enrichment (stress marks, phrasal verbs)
description: Local-only per-sentence enrichment — Russian/English stress marks and English phrasal-verb hits — computed by the Python service (Silero Stress + caller-supplied dictionary data incl. CMUdict), orchestrated as declaratively language-scoped enrichers with per-enricher staleness stamps, stored beside sentence content, and rendered behind per-user toggles on the reading surfaces (ADR 0052, ADR 0053, ADR 0057).
tags: [enrichment, stress-marks, phrasal-verbs, enrichers, python-service, reader, simulator, silero]
status: stable
stale_after: 2026-12-31
generated: { by: agent:zcode, at: 2026-10-02T00:00:00Z }
sources:
   - id: service
     resource: laravel/app/Classes/SentenceEnrichmentService.php
     title: SentenceEnrichmentService
   - id: enricher
     resource: laravel/app/Classes/Enrichment/Enricher.php
     title: Enricher interface
   - id: registry
     resource: laravel/app/Classes/Enrichment/EnricherRegistry.php
     title: EnricherRegistry
   - id: job
     resource: laravel/app/Jobs/EnrichEntitySentences.php
     title: EnrichEntitySentences
   - id: command
     resource: laravel/app/Console/Commands/EnrichEntitiesCommand.php
     title: EnrichEntitiesCommand
   - id: endpoint
     resource: docker-compose/python/ai/api/enrich.py
     title: Python /enrich router
   - id: ru
     resource: docker-compose/python/ai/enrichment/ru_stress.py
     title: ru_stress
   - id: en
     resource: docker-compose/python/ai/enrichment/en_stress.py
     title: en_stress
   - id: phrasal
     resource: docker-compose/python/ai/enrichment/phrasal.py
     title: phrasal
   - id: adr
     resource: docs/adr/0052-local-sentence-enrichment.md
     title: ADR 0052
   - id: adr57
     resource: docs/adr/0057-enricher-registry.md
     title: ADR 0057
---

# Sentence enrichment (stress marks, phrasal verbs)

Every sentence of a ru/en entity gets two enrichment payloads, computed
**entirely locally** (ADR 0052: no LLM, no paid calls — Silero Stress +
dictionary data only):

| Payload | Column | Content |
|---|---|---|
| Stressed variant | `entity_sentences.stressed_content` | Sentence with U+0301 combining acutes on stressed vowels (Russian also е→ё); acute omitted on ё |
| Phrasal verbs | `entity_sentences.phrasal_verbs` (jsonb) | English hits `{verb, particles[], start, end, phrase}` — char spans into `content`, `phrase` the matched headword |

`content` is **never mutated**: enrichment that wrote into content would bump
`sentences_updated_at` (staling word index/frequency/text hash), change the
text hash under exact-copy detection, and flip entity matches to `pending`
(ADR 0015). All spans index `content`, so they stay valid forever.

## Enrichers (ADR 0057)

Each analysis is an `Enricher` (`App\Classes\Enrichment`): `key()` (python
dispatch key + stamp key), `languages()` (declared applicability),
`column()`, `tokenHints()` (per-token dictionary hints), `requestExtras()`
(request-level payload), `toStorage()`. `EnricherRegistry::forLanguage(code)`
answers "what should be included in the enrichment process" for a language:
`ru` → `ru_stress` (RussianStressEnricher), `en` → `en_stress`
(EnglishStressEnricher) + `en_phrasal` (EnglishPhrasalVerbEnricher); any
other language → nothing (never dispatched, never stale). Adding an analysis
is one class + one registry entry. The service resolves the shared base
per chunk (entity word link wins, then class-priority direct match;
`cls`/`lemma`/`headword`/`word_id` per key) and merges each enricher's
contributions into one python payload.

## Engines (Python service `POST /enrich`)

`{language, enrichers: ["ru_stress"|"en_stress"|"en_phrasal"], phrasal_lexicon:
[headwords], sentences: [{id, text, tokens: [{surface, start, end, cls, lemma,
ipa, parts, stressed}]}]}` → `{results: [{id, output: {[key]: value}]}}` —
the request's `enrichers` (pydantic `Literal`, validity = dispatchability)
selects the modules and each result's output is keyed by enricher key. One
HTTP round trip per batch; a partial run (only the stale enrichers) skips the
others. Laravel owns the dictionary and sends everything the service needs as
per-token hints; Python owns model inference and writes nothing anywhere —
the same split as `/split` and `/align`.

- **ru stress** (`ai/enrichment/ru_stress.py`): Silero Stress
  (`pip silero-stress`, weights bundled, lazy-loaded via `ModelCache`)
  accentor on the mark-stripped sentence — context resolves homographs
  (за́мок/замо́к) and places ё. Its `+` markers are converted to U+0301 after
  the stressed vowel and the final string is rebuilt by replacing each token
  span, keeping offsets stable. Tokens Silero leaves unmarked fall back to
  the dictionary candidates (the enricher's `stressed` hints: forms-table
  stressed forms, then the headword), applied only when all candidates agree
  on the stress position (ambiguous homograph ⇒ unmarked).
- **en stress** (`ai/enrichment/en_stress.py`): the ˈ in a hint variant gives
  the stressed-syllable index (vowel nuclei before it). Placement walks two
  paths (ADR 0053): **pyphen** orthographic syllables aligned by count with
  the IPA nuclei — acute on the stressed syllable's first vowel letter —
  then a fallback proportional map that excludes a word-final silent "e" and
  anchors final-nucleus stress on the last vowel-letter run (*advánce,
  becáuse, afráid* instead of the old *advancé/becausé* saturation).
  Monosyllables ARE marked when a variant carries ˈ (CMUdict: *cát, túrned*).
  Hyphenated compounds with no whole-token IPA are marked per part from the
  caller's `parts` hint (*SÉVEN-SÍDED*). Words with no ˈ in any variant
  (unstressed function words) stay plain.
- **English stress sources** (ADR 0053): Wiktionary/kaikki IPA **plus**
  CMUdict imported by `dictionary:import-cmudict` (BSD, ARPAbet→IPA at
  import). Rows already carrying a ˈ-marked transcription are skipped
  (kaikki wins); rows with only unstressed IPA gain the CMUdict variant;
  all class rows of a word get it (the entity link may point at any);
  missing words are created under the `unknown` class; function-word
  citation-form stressed variants ("of AH1 V") are dropped so closed-class
  words never carry a mark. The enricher orders variants stress-first
  (`ipaByWordId()`: ˈ-bearing variants before the rest, deterministic
  `transcriptions.id` tiebreak) before applying the 3-variant cap.
- **phrasal verbs** (`ai/enrichment/phrasal.py`): 3- then 2-token windows
  whose lead is verb-classed and whose `lemma + surfaces` match a multi-word
  verb headword (the dictionary's previously-inert phrasal rows; the
  lexicon rides `requestExtras()`). Longest match wins; hits never overlap;
  each hit carries the matched `phrase`.

Plain-python tests: `docker exec ext_python python
/app/ai/enrichment/test_enrichment.py` (Silero-dependent ru tests skip with a
notice when the package is absent).

## Orchestration (Laravel)

- `SentenceEnrichmentService` (`create()` factory like the other python
  clients): builds token spans via `WordTokenizer::tokenizeWithSpans`,
  resolves the shared base hints, merges the active enrichers' hints/extras,
  calls `/enrich` with `Http::retry` on connection errors, and persists each
  enricher's output into its column.
- **Quiet writes**: `EntitySentence::query()->whereKey()->toBase()->update()`
  — no model events, no `updated_at`. Model events would `touchSentencesFor`
  and make enrichment mark the entity stale forever (infinite re-enrich
  loop). A regression test pins `updated_at`/`sentences_updated_at`/
  `text_hash` unchanged.
- **Per-enricher staleness** (ADR 0057): `entities.enrichment_stamps` jsonb
  `{enricher key: ISO timestamp}`. `EnricherRegistry::staleFor(entity)`
  returns the language's enrichers whose stamp is missing (a newly
  registered enricher backfills itself — no manual reset, the ADR 0053
  choreography) or older than the last sentence change. A language with no
  enrichers is never stale; its job run stamps `{}` once. Stamps merge via
  jsonb `||` so concurrent stampers cannot clobber each other.
- `EnrichEntitySentences` job (low lane, self-re-dispatching, 2×75 sentences
  per run) dispatched from three places: the end of
  `FinalizeEntityDerivations` and the Filament "Enrich" action (`begin()` —
  a FULL run of the language's enrichers), and the 5-minute `entities:enrich`
  sweep (`beginEnrichers()` — only the stale set; `--enricher=` forces one
  key across its languages, `--dry-run` reports). Empty-content sentences
  never reach Python (schema rejects empty text) and keep null columns.
- Re-enrichment is idempotent and free — sentence edits re-stale the
  entity's enrichers and the sweep rebuilds it.

## Rendering

- Reader (`ReaderController`) and simulator (`SimulatorController::text`)
  ship `stressedRows` AND `phrasalRows` parallel to the rows, flipped with
  the reading/learning side. Stress marks are a **per-user preference**
  (`stress_marks` in the reader + simulator `ui_settings` sections,
  autosaved, default off): it swaps `content` → `stressed_content`. Phrasal
  verbs are the same kind of preference (`phrasal_verbs`, default off): it
  underlines the tokens each hit's span covers with a dotted verdigris
  underline (`phrasal-hit` class) and shows the matched `phrase` as tooltip;
  spans index `content`, so token indexes are computed from the original
  sentence and transfer to the stressed variant (the token sequence is
  unchanged). Both toggles render only when data exists; toolbar controls
  draw from the shared grey line-art icon set (`icons.jsx`; WordText is the
  shared renderer). Filament previews both columns in
  SentencesRelationManager (visible by default).
- **Tokenizer keys strip combining marks** on both sides (`WordTokenizer::
  lookupKey`, `wordTokenizer.mjs`) — the dictionary's `l_word` normalization
  — so stressed tokens still resolve the word map and popups.
  `TokenizerParityTest` carries stress-marked samples. `WordController::show`
  strips marks from popup surfaces defensively.

## Deployment note

`silero-stress==1.5` (ADR 0052) and `pyphen>=0.18` (ADR 0053) are in
`docker-compose/python/requirements.txt` — container-definition changes:
rebuild the python image
(`docker compose build python && docker compose up -d python`) and
`./deploy.sh --stamp` on the Docker-era prod path. On the native prod path
(the one `.github/workflows/deploy.yml` actually runs) `deploy-native.sh`
handles this automatically: it pip-syncs the machine-local venv from
`requirements.txt` on every deploy and restarts + health-checks
`ext-python` whenever the pull touched `docker-compose/python/`. After a
prod deploy the CMUdict file (`laravel/kaikki/cmudict.dict`) must be fetched
once and `dictionary:import-cmudict` run (kaikki-sourced rows are preserved
on re-import). ADR 0057 touched only python code (no dependency change) —
a plain deploy restarts the service, no rebuild.
