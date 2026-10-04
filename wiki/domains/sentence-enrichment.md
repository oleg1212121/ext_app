---
type: Pipeline
title: Sentence enrichment (stress marks, multi-word verbs)
description: Local-only per-sentence enrichment — Russian/English stress marks and English multi-word-verb hits — computed by the Python service (Silero Stress, spaCy dependency parsing, caller-supplied dictionary data incl. CMUdict), orchestrated as declaratively language-scoped, version-stamped enrichers with per-enricher staleness, stored beside sentence content, and rendered behind per-user toggles on the reading surfaces (ADR 0052, ADR 0053, ADR 0057, ADR 0059).
tags: [enrichment, stress-marks, multi-word-verbs, enrichers, python-service, spacy, reader, simulator, silero]
status: stable
stale_after: 2026-12-31
generated: { by: agent:zcode, at: 2026-10-04T00:00:00Z }
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
   - id: shape
     resource: laravel/app/Classes/MultiwordVerbShape.php
     title: MultiwordVerbShape
   - id: reclass
     resource: laravel/app/Console/Commands/ReclassMultiwordWordsCommand.php
     title: words:reclass-multiword
   - id: adr
     resource: docs/adr/0052-local-sentence-enrichment.md
     title: ADR 0052
   - id: adr57
     resource: docs/adr/0057-enricher-registry.md
     title: ADR 0057
   - id: adr58
     resource: docs/adr/0058-phrasal-lead-verb-lemmas.md
     title: ADR 0058
   - id: adr59
     resource: docs/adr/0059-spacy-multiword-verb-matching.md
     title: ADR 0059
---

# Sentence enrichment (stress marks, multi-word verbs)

Every sentence of a ru/en entity gets two enrichment payloads, computed
**entirely locally** (ADR 0052: no LLM, no paid calls — Silero Stress,
spaCy and dictionary data only):

| Payload | Column | Content |
|---|---|---|
| Stressed variant | `entity_sentences.stressed_content` | Sentence with U+0301 combining acutes on stressed vowels (Russian also е→ё); acute omitted on ё |
| Multi-word verbs | `entity_sentences.phrasal_verbs` (jsonb) | English hits `{verb, particles[], start, end, phrase}` — char spans into `content`, `phrase` the matched headword (or the normalized lemma phrase for parser-only particle hits) |

`content` is **never mutated**: enrichment that wrote into content would bump
`sentences_updated_at` (staling word index/frequency/text hash), change the
text hash under exact-copy detection, and flip entity matches to `pending`
(ADR 0015). All spans index `content`, so they stay valid forever.

## Enrichers (ADR 0057)

Each analysis is an `Enricher` (`App\Classes\Enrichment`): `key()` (python
dispatch key + stamp key), `version()` (algorithm version — a bump re-stales
the whole corpus; ADR 0059), `languages()` (declared applicability),
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
ipa, parts, stressed, verb_lemmas}]}]}` → `{results: [{id, output: {[key]: value}]}}` —
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
- **multi-word verbs** (`ai/enrichment/phrasal.py`, ADR 0059): spaCy
  (`en_core_web_md`, lazy singleton, `nlp.pipe` over the request, ~10k
  words/sec CPU — a 75-sentence chunk parses well under a second)
  parses the raw sentence; the matcher walks VERB tokens (AUX excluded —
  "could have given" can never match) and combines the lemma with
  `prt`/`prep` children. Particle verbs ("gave up", separated "looked
  it up") hit on parser evidence alone — unless the particle is
  immediately followed by a goal/path preposition
  (to/toward/into/onto/through/across/past), the directional reading
  ("swung over toward Max", "followed down to the basement"); locative
  prepositions ("looked it up on the network") and infinitival "to"
  (tagged PART, not ADP — "looked it up to check") still hit.
  Prepositional ("depend on") and phrasal-prepositional ("come up with")
  matches are dictionary-gated against the caller's lexicon (the
  shape-curated multi-word verb headwords riding `requestExtras()`),
  tried before the directional guard so lexiconed combos survive it,
  with ADR 0058's `verb_lemmas` as extra lemma candidates. One hit per
  verb; the most specific candidate wins; a verb with particles skips
  bare-prep candidates (the "on" of "looked it up on the network"
  belongs to a following phrase). A missing model/package fails loudly
  (503) — empty enrichment is never written and stamped. Sense ambiguity
  is a documented limitation ("sat in the car" hits when "sit in" is
  lexiconed).

Plain-python tests: `docker exec ext_python python
/app/ai/enrichment/test_enrichment.py` (Silero-dependent ru tests skip with a
notice when the package is absent).

## Orchestration (Laravel)

- `SentenceEnrichmentService` (`create()` factory like the other python
  clients): builds token spans via `WordTokenizer::tokenizeWithSpans`,
  resolves the shared base hints, merges the active enrichers' hints/extras,
  calls `/enrich` through `PythonClient::enrich()` (ADR 0061 — the shared
  transport seam owns retries and the error envelope), and persists each
  enricher's output into its column.
- **Quiet writes**: `EntitySentence::query()->whereKey()->toBase()->update()`
  — no model events, no `updated_at`. Model events would `touchSentencesFor`
  and make enrichment mark the entity stale forever (infinite re-enrich
  loop). A regression test pins `updated_at`/`sentences_updated_at`/
  `text_hash` unchanged.
- **Per-enricher staleness** (ADR 0057, versioned in ADR 0059):
  `entities.enrichment_stamps` jsonb `{enricher key: {v, at}}` (v1-era
  bare ISO strings read as version 1). `EnricherRegistry::staleFor(entity)`
  returns the language's enrichers whose stamp is missing (a newly
  registered enricher backfills itself), whose recorded version is older
  than the enricher's `version()` — a bump re-stales the whole corpus so
  the sweep re-runs the analysis everywhere with no manual reset — or
  older than the last sentence change. A language with no enrichers is
  never stale; its job run stamps `{}` once. Stamps merge via jsonb `||`
  so concurrent stampers cannot clobber each other.
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
  ship enrichment inside the Reading rows (ADR 0060): each sentence object
  carries `stressed` and `phrasal` keys — present only when the data
  exists — beside its `text`. Stress marks are a **per-user preference**
  (`stress_marks` in the reader + simulator `ui_settings` sections,
  autosaved, default off): it swaps `text` → `stressed`. Multi-word
  verbs are the same kind of preference (`phrasal_verbs`, default off): it
  underlines the tokens each hit's span covers with a dotted verdigris
  underline (`phrasal-hit` class) and shows the matched `phrase` as tooltip;
  spans index the plain text, so marks are computed per sentence from the
  original and transfer to the stressed variant (the token sequence is
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

`silero-stress==1.5` (ADR 0052), `pyphen>=0.18` (ADR 0053) and
`spacy==3.8.16` + the `en_core_web_md` model wheel (ADR 0059) live in
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
on re-import). After the ADR 0059 deploy: run
`php artisan words:reclass-multiword` (dry-run first) to clean the English
verb rows, then let the sweep grind the v2 `en_phrasal` backfill or
accelerate it with `entities:enrich --enricher=en_phrasal --limit=N`.
