# ADR 0052: Local-only sentence enrichment stored beside content

Date: 2026-09-29
Status: Accepted

## Context

Learners reading Russian and English texts need per-sentence pronunciation
aids the app did not compute: stress marks (Russian ударение — combining
acute U+0301; English primary stress derived from IPA), phrasal verbs
(English), and — requested during design — sentence intonation. The grilling
session that shaped this decision settled four constraints:

- **Everything runs locally.** No paid LLM calls, nothing through the
  AiProvider layer. The only acceptable engines are locally-hosted models and
  the app's own dictionary data (cost and reproducibility).
- **`entity_sentences.content` must never be mutated** for enrichment: every
  sentence mutation bumps `sentences_updated_at` (staling the word index,
  frequency and text hash — the exact-copy reuse breaks), changes `text_hash`
  in flight, and flips entity matches back to `pending` (ADR 0015).
- The Python service is the established home for model-backed sentence
  processing and is deliberately offline/local-ML-only; Laravel owns all DB
  writes.
- Useful data already sits in the dictionary: Russian headwords and inflected
  `forms` keep U+0301, English `transcriptions` keep IPA with the
  primary-stress ˈ, and multi-word verb headwords ("give up", ~3k rows in the
  imported English dictionary) are stored but unreachable — the entity
  word-linker only ever sees single-word tokens, so phrasal verbs were inert
  rows.

Homographs (за́мок/замо́к) can only be resolved with sentence context, which
a bare dictionary lookup cannot do; Silero Stress (MIT, torch-only, ~50 MB,
fully offline) bundles a ~4M-form dictionary plus a context homograph solver
(F1 0.92) and already converts е→ё where ё belongs — 2 ms/sentence on CPU.
No good local text→prosody model exists, so intonation is a deterministic
heuristic, not model output.

## Decision

- **Engines (all local, python service `/enrich` endpoint):**
  - Russian stress: Silero Stress primary (context homographs, е→ё
    conversion, acute on stressed vowels; acute omitted on ё), dictionary
    (`words`/`forms`) fallback for tokens Silero leaves unmarked — applied
    only when all candidates agree on the stress position.
  - English stress: the caller-supplied Wiktionary IPA per token; the
    stressed syllable index (nuclei before ˈ) maps proportionally onto the
    word's vowel letters and gets U+0301 (béautiful, pronóunces,
    pronunciátion). Words without a ˈ-carrying transcription stay plain.
  - Phrasal verbs: dictionary n-grams — 2/3-token windows whose lead token is
    verb-classed and whose `lemma + surfaces` match a multi-word verb
    headword. The lemma covers inflected leads ("gave up" → "give up").
  - Intonation: heuristic only — nuclear stress on the last content word
    (word-class aware, English auxiliaries skipped), terminal rise for yes/no
    questions (final "?" and no wh-initial), fall otherwise. Documented as
    approximate.
- **Storage — beside content, never inside it:** `entity_sentences.stressed_content`
  (display variant; spans in the other columns index `content`, not this
  column), `phrasal_verbs` jsonb (`{verb, particles, start, end}` char spans
  into `content`), `intonation` jsonb (`{nuclear, terminal}`); staleness via
  `entities.enriched_at` (null or older than `sentences_updated_at` ⇒ stale —
  the `words_indexed_at` pattern).
- **Quiet-write invariant:** enrichment writes use base-builder updates (no
  Eloquent model events, no `updated_at`). Model events would call
  `touchSentencesFor`, which bumps `sentences_updated_at` and would make
  enrichment mark the entity stale forever — an infinite re-enrichment loop.
  A regression test pins `updated_at`/`sentences_updated_at`/`text_hash`
  unchanged after enrichment.
- **Orchestration:** `EnrichEntitySentences` (low lane, self-re-dispatching,
  2×75 sentences per run) dispatched at the end of the upload pipeline
  (`FinalizeEntityDerivations`), by the 5-minute `entities:enrich` sweep for
  stale entities, and by a Filament action. Non-enrichable entities (other
  languages, no sentences) are stamped `enriched_at` so they never count as
  stale.
- **Tokenizer keys strip combining marks** on both sides (PHP
  `WordTokenizer::lookupKey`, JS `wordTokenizer.mjs`) — matching the
  dictionary's mark-free `l_word` normalization — so stressed source text and
  the reader's stress-toggled rendering still resolve the word map and
  popups. `TokenizerParityTest` carries stress-marked samples.
- **Rendering:** a per-user `stress_marks` preference (reader + simulator
  sections of `ui_settings`) toggles swapping `content` → `stressed_content`
  and showing a subtle ↗/↘ terminal marker. Payloads ship `stressedRows` /
  `intonationRows` parallel to the existing rows (same "\n"-joined,
  side-flipped shape). Phrasal-verb data is DB + Filament preview only this
  iteration.

## Consequences

- Re-enrichment is free and idempotent; any sentence edit re-stales the
  entity and the sweep rebuilds it. No paid API usage anywhere.
- `silero-stress` joins `docker-compose/python/requirements.txt` — a
  container-definition change that requires an image rebuild and
  `./deploy.sh --stamp` on deploy.
- English stress quality is bounded by the imported Wiktionary IPA; words
  without a transcription render unmarked (dictionary import growth quietly
  improves them after re-enrichment). Dictionary fallback for Russian only
  fires when the dev dictionary actually carries stressed forms — with the
  current 3-word Russian dictionary, Silero carries everything.
- Intonation annotations are heuristics and must not be presented as
  authoritative prosody; the nuclear-stress heuristic needs word-class links
  (`entity_words`) and degrades to `nuclear: null` without them.
- The multi-word dictionary entries remain unreachable by the single-token
  entity word-linker; phrasal detection is a read-side consumer of the
  lexicon, not a linking change.
