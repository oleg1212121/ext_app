---
type: Pipeline
title: Sentence enrichment (stress marks, phrasal verbs)
description: Local-only per-sentence enrichment — Russian/English stress marks and English phrasal-verb hits — computed by the Python service (Silero Stress + caller-supplied dictionary data incl. CMUdict), stored beside sentence content, refreshed by staleness sweeps, and rendered behind a per-user stress-marks toggle on the reading surfaces (ADR 0052, ADR 0053).
tags: [enrichment, stress-marks, phrasal-verbs, python-service, reader, simulator, silero]
status: stable
stale_after: 2026-12-29
generated: { by: agent:zcode, at: 2026-09-30T12:00:00Z }
sources:
   - id: service
     resource: laravel/app/Classes/SentenceEnrichmentService.php
     title: SentenceEnrichmentService
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
---

# Sentence enrichment (stress marks, phrasal verbs)

Every sentence of a ru/en entity gets two enrichment payloads, computed
**entirely locally** (ADR 0052: no LLM, no paid calls — Silero Stress +
dictionary data only):

| Payload | Column | Content |
|---|---|---|
| Stressed variant | `entity_sentences.stressed_content` | Sentence with U+0301 combining acutes on stressed vowels (Russian also е→ё); acute omitted on ё |
| Phrasal verbs | `entity_sentences.phrasal_verbs` (jsonb) | English hits `{verb, particles[], start, end}` — char spans into `content` |

`content` is **never mutated**: enrichment that wrote into content would bump
`sentences_updated_at` (staling word index/frequency/text hash), change the
text hash under exact-copy detection, and flip entity matches to `pending`
(ADR 0015). All spans index `content`, so they stay valid forever.

## Engines (Python service `POST /enrich`)

`{language: ru|en, phrasal_lexicon: [headwords], sentences: [{id, text,
tokens: [{surface, start, end, cls, lemma, ipa, stressed}]}]}` →
`{results: [{id, stressed, phrasal_verbs}]}`. Laravel owns the
dictionary and sends everything the service needs as per-token hints
(word-class slug from `entity_words` links, dictionary lemma, IPA variants,
Russian stressed-form candidates); Python owns model inference and writes
nothing anywhere — the same split as `/split` and `/align`.

- **ru stress** (`ai/enrichment/ru_stress.py`): Silero Stress
  (`pip silero-stress`, weights bundled, lazy-loaded via `ModelCache`)
  accentor on the mark-stripped sentence — context resolves homographs
  (за́мок/замо́к) and places ё. Its `+` markers are converted to U+0301 after
  the stressed vowel and the final string is rebuilt by replacing each token
  span, keeping offsets stable. Tokens Silero leaves unmarked fall back to
  the dictionary candidates, applied only when all candidates agree on the
  stress position (ambiguous homograph ⇒ unmarked).
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
  words never carry a mark.
- **phrasal verbs** (`ai/enrichment/phrasal.py`): 3- then 2-token windows
  whose lead is verb-classed and whose `lemma + surfaces` match a multi-word
  verb headword (the dictionary's previously-inert phrasal rows). Longest
  match wins; hits never overlap.

Plain-python tests: `docker exec ext_python python
/app/ai/enrichment/test_enrichment.py` (Silero-dependent ru tests skip with a
notice when the package is absent).

## Orchestration (Laravel)

- `SentenceEnrichmentService` (`create()` factory like the other python
  clients): builds token spans via `WordTokenizer::tokenizeWithSpans`,
  resolves dictionary hints (entity link wins, then class-priority direct
  match; linked headwords outside the token window are pulled in), calls
  `/enrich` with `Http::retry` on connection errors, and persists. English
  IPA hints are selected stress-first: `ipaByWordId()` orders variants
  containing the primary-stress mark ˈ before the rest (deterministic
  `transcriptions.id` tiebreak) before applying the 3-variant cap, so the
  cap can never cut off the only variant python could mark with.
- **Quiet writes**: `EntitySentence::query()->whereKey()->toBase()->update()`
  — no model events, no `updated_at`. Model events would `touchSentencesFor`
  and make enrichment mark the entity stale forever (infinite re-enrich
  loop). A regression test pins `updated_at`/`sentences_updated_at`/
  `text_hash` unchanged.
- **Staleness**: `entities.enriched_at` (the `words_indexed_at` pattern) —
  null or older than `sentences_updated_at` ⇒ stale. Entities of other
  languages or without sentences are stamped enriched so they never count as
  stale.
- `EnrichEntitySentences` job (low lane, self-re-dispatching, 2×75 sentences
  per run) dispatched from three places: the end of
  `FinalizeEntityDerivations` (upload pipeline), the 5-minute
  `entities:enrich` sweep (`routes/console.php`), and a Filament action on
  `EntityResource`. Empty-content sentences never reach Python (schema
  rejects empty text) and keep null columns.
- Re-enrichment is idempotent and free — sentence edits re-stale the entity
  and the sweep rebuilds it.

## Rendering

- Reader (`ReaderController`) and simulator (`SimulatorController::text`)
  ship `stressedRows` parallel to the rows — same "\n"-joined per-side
  shape, flipped with the reading/learning side. Stress marks are a
  **per-user preference** (`stress_marks` in the reader + simulator
  `ui_settings` sections, autosaved, default off): it swaps `content` →
  `stressed_content`. The toggle renders only when stressed data exists;
  both pages draw their toolbar controls from the shared grey line-art icon
  set (`resources/js/Components/icons.jsx`). Phrasal-verb data is DB +
  Filament preview only (SentencesRelationManager columns).
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
`./deploy.sh --stamp` on the prod machine. After a prod deploy the CMUdict
file (`laravel/kaikki/cmudict.dict`) must be fetched once and
`dictionary:import-cmudict` run (kaikki-sourced rows are preserved on
re-import).
