---
type: Pipeline
title: Sentence enrichment (stress marks, phrasal verbs, intonation)
description: Local-only per-sentence enrichment — Russian/English stress marks, English phrasal-verb hits and heuristic intonation — computed by the Python service (Silero Stress + caller-supplied dictionary data), stored beside sentence content, refreshed by staleness sweeps, and rendered behind a per-user reader toggle (ADR 0052).
tags: [enrichment, stress-marks, phrasal-verbs, intonation, python-service, reader, simulator, silero]
status: stable
stale_after: 2026-12-29
generated: { by: agent:zcode, at: 2026-09-29T19:00:00Z }
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
   - id: intonation
     resource: docker-compose/python/ai/enrichment/intonation.py
     title: intonation
   - id: adr
     resource: docs/adr/0052-local-sentence-enrichment.md
     title: ADR 0052
---

# Sentence enrichment (stress marks, phrasal verbs, intonation)

Every sentence of a ru/en entity gets three enrichment payloads, computed
**entirely locally** (ADR 0052: no LLM, no paid calls — Silero Stress +
dictionary data only):

| Payload | Column | Content |
|---|---|---|
| Stressed variant | `entity_sentences.stressed_content` | Sentence with U+0301 combining acutes on stressed vowels (Russian also е→ё); acute omitted on ё |
| Phrasal verbs | `entity_sentences.phrasal_verbs` (jsonb) | English hits `{verb, particles[], start, end}` — char spans into `content` |
| Intonation | `entity_sentences.intonation` (jsonb) | Heuristic `{nuclear: {start,end}\|null, terminal: rise\|fall}` — spans into `content` |

`content` is **never mutated**: enrichment that wrote into content would bump
`sentences_updated_at` (staling word index/frequency/text hash), change the
text hash under exact-copy detection, and flip entity matches to `pending`
(ADR 0015). All spans index `content`, so they stay valid forever.

## Engines (Python service `POST /enrich`)

`{language: ru|en, phrasal_lexicon: [headwords], sentences: [{id, text,
tokens: [{surface, start, end, cls, lemma, ipa, stressed}]}]}` →
`{results: [{id, stressed, phrasal_verbs, intonation}]}`. Laravel owns the
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
- **en stress** (`ai/enrichment/en_stress.py`): pure string work over the
  Wiktionary IPA hint — nuclei before ˈ give the stressed syllable index,
  mapped proportionally onto the word's vowel letters (digraph-aware: "ay"
  marks the a). Words without a ˈ-carrying transcription stay plain.
- **phrasal verbs** (`ai/enrichment/phrasal.py`): 3- then 2-token windows
  whose lead is verb-classed and whose `lemma + surfaces` match a multi-word
  verb headword (the dictionary's previously-inert phrasal rows). Longest
  match wins; hits never overlap.
- **intonation** (`ai/enrichment/intonation.py`): heuristic — nuclear = last
  content word (English auxiliaries skipped), terminal = rise for yes/no
  questions (final `?`, no wh-initial), fall otherwise. No local
  text→prosody model exists; treat as approximate.

Plain-python tests: `docker exec ext_python python
/app/ai/enrichment/test_enrichment.py` (Silero-dependent ru tests skip with a
notice when the package is absent).

## Orchestration (Laravel)

- `SentenceEnrichmentService` (`create()` factory like the other python
  clients): builds token spans via `WordTokenizer::tokenizeWithSpans`,
  resolves dictionary hints (entity link wins, then class-priority direct
  match; linked headwords outside the token window are pulled in), calls
  `/enrich` with `Http::retry` on connection errors, and persists.
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
  ship `stressedRows` / `intonationRows` parallel to the rows — same
  "\n"-joined per-side shape, flipped with the reading/learning side. A
  per-user `stress_marks` preference (reader + simulator `ui_settings`
  sections, autosaved) toggles the swap `content` → `stressed_content` and a
  subtle ↗/↘ terminal marker; the toggle only renders when the page actually
  carries stressed data. Phrasal-verb data is DB + Filament preview only
  (SentencesRelationManager columns).
- **Tokenizer keys strip combining marks** on both sides (`WordTokenizer::
  lookupKey`, `wordTokenizer.mjs`) — the dictionary's `l_word` normalization
  — so stressed tokens still resolve the word map and popups.
  `TokenizerParityTest` carries stress-marked samples. `WordController::show`
  strips marks from popup surfaces defensively.

## Deployment note

`silero-stress==1.5` was added to `docker-compose/python/requirements.txt` —
a container-definition change: rebuild the python image
(`docker compose build python && docker compose up -d python`) and
`./deploy.sh --stamp` on the prod machine.
