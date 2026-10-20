---
type: Database Schema
title: Unified Dictionary Tables
description: One words table (+ satellites) keyed by language and unique per (l_word, language_id, word_class_id) — cross-source spellings of one word collapse into one row (ADR 0081), per-language word classes and transcription types, a symmetric word_translations pivot (one row per pair), the word_translation_fetches ledger driving auto-fetched translations (Yandex→Google, link-only since 2026-10-18 — unmatched candidates stage, no word rows) that doubles as the exclusions list, and the word_ai_fetches ledger locking the popup's one-shot ask-AI enrichment (merged inline under per-row source columns, ADR 0082).
tags: [database, schema, dictionary, words]
status: stable
stale_after: 2027-01-19
generated: { by: agent:zcode, at: 2026-10-20T14:08:00+03:00 }
sources:
   - id: migration
     resource: laravel/database/migrations/2026_09_10_000005_create_dictionary_tables.php
     title: Unified dictionary creation (squashed baseline)
   - id: l-word-uniqueness
     resource: laravel/database/migrations/2026_10_19_000001_move_word_uniqueness_to_l_word.php
     title: Word uniqueness moved to (l_word, language_id, word_class_id)
   - id: adr-0081
     resource: docs/adr/0081-word-l-word-contract-uniqueness-on-l-word.md
     title: ADR 0081 — the word/l_word contract, uniqueness on (l_word, language, class)
   - id: word-lookup-key
     resource: laravel/app/Support/WordLookupKey.php
     title: Shared ё-aware l_word normalization (trim → lowercase → mark strip → zero-width strip → ё→е fold)
   - id: tag-pivot-rename
     resource: laravel/database/migrations/2026_10_11_000001_rename_word_tags_to_tagged_words.php
     title: Words-tag pivot rename to tagged_words
   - id: fetch-migration
     resource: laravel/database/migrations/2026_09_29_000001_create_word_translation_fetches_table.php
     title: Auto-fetch ledger creation
   - id: importer
     resource: laravel/app/Classes/WiktionaryParser.php
     title: Wiktionary (Kaikki) import writer
   - id: stress-marks
     resource: laravel/app/Support/StressMarks.php
     title: Shared Cyrillic stress-mark normalizer (ru/be/uk list + stripping)
   - id: lemma-forms
     resource: laravel/app/Support/LemmaForms.php
     title: Shared lemma-form rule and form-row hygiene (cap/strip/empty-guard/dedupe)
   - id: pos-aliases
     resource: laravel/app/Support/PosAliases.php
     title: Shared pos alias table (dictionary abbreviations + kaikki slugs → canonical class slugs)
  - id: word-id-indexes
    resource: laravel/database/migrations/2026_10_18_000001_add_word_id_indexes_to_forms_transcriptions_and_word_children.php
    title: word_id-leading indexes on forms/transcriptions/examples/pronunciations/user_word/user_word_event
  - id: fetch-counts-migration
    resource: laravel/database/migrations/2026_10_18_000002_add_fetch_outcome_counts_to_word_translation_fetches.php
    title: linked_count/staged_count outcome columns on word_translation_fetches
  - id: translation-targets
    resource: laravel/app/Support/TranslationTargets.php
    title: Shared link-only target-word resolver (matches candidates against existing rows)
  - id: adr-0079
    resource: docs/adr/0079-form-of-relays-demote-to-forms-stage-link-prune.md
    title: ADR 0079 — relays demote to forms, lemma-as-form, stage → link → prune
  - id: completeness-migration
    resource: laravel/database/migrations/2026_10_19_000002_add_word_completeness_to_words_table.php
    title: words.completeness + words.completeness_at (popup-ai-enrich ticket 02)
  - id: word-completeness
    resource: laravel/app/Classes/WordCompleteness.php
    title: WordCompleteness (the 0-7 matrix + lazy staleness recalc)
  - id: recalc-command
    resource: laravel/app/Console/Commands/WordsRecalcCompletenessCommand.php
    title: words:recalc-completeness (chunked set-based backfill, --stale-only)
  - id: ai-fetches-migration
    resource: laravel/database/migrations/2026_10_19_000003_create_word_ai_fetches_table.php
    title: word_ai_fetches — the ask-AI enrichment ledger (popup-ai-enrich ticket 04)
  - id: source-migration
    resource: laravel/database/migrations/2026_10_19_000004_add_source_to_word_content_tables.php
    title: nullable source columns on definitions/transcriptions/examples/etymologies
  - id: enrich-service
    resource: laravel/app/Classes/WordAiEnrichmentService.php
    title: WordAiEnrichmentService (the transactional merge writer)
  - id: adr-0082
    resource: docs/adr/0082-ask-ai-enrichment-merge-inline-lock-on-success.md
    title: ADR 0082 — ask-AI enrichment merges inline, locks on success, translations link-only
---

# Tables

| Table | Role |
|-------|------|
| `word_classes` | Parts of speech **per language** (`language_id`, `slug`, `title`, unique `(language_id, slug)`); seeded for en/ru |
| `transcription_types` | Transcription kinds per language (ipa, enpr for English; МФА for Russian) |
| `words` | A base-form word in one language: `language_id`, `word`, `l_word` (the `WordLookupKey` lookup key), `frequency` (rank: lower = more common, `numeric(12,2)`; 1,100,000 = unranked, `Word::FREQUENCY_UNRANKED` — see ADR 0041), `word_class_id`, staged translation candidates as a flat JSON string list (`translations` — imports stage them, and since 2026-10-18 the popup auto-fetch stages its unmatched candidates here too; the link pass converts them into `word_translations` rows and prunes what it linked), **`completeness`** (0–7: linked translations 2, definitions/transcriptions/examples/etymologies 1 each, a real frequency rank 1 — `WordCompleteness`, recalculated lazily on popup open and swept by `words:recalc-completeness`; drives the popup's entry ordering) with **`completeness_at`** (the recalc stamp — missing or older than the word row or any content child ⇒ stale). **Unique `(l_word, language_id, word_class_id)`** (2026-10-19, migration `2026_10_19_000001` — was `(word, language_id, word_class_id)`): cross-source spellings of one word (до́м/дом, ёлка/елка, March/march) are ONE row per class — the first source's display spelling stands, later sources attach to it and refresh only the key and the staged JSON |
| `forms` / `definitions` / `etymologies` / `examples` | Per-word satellites (unique `(form, word_id)` / `(example, word_id)`) each carrying a nullable **`source`** (migration `2026_10_19_000004`: null = imported, `'ai'` = the popup's ask-AI enrichment, ADR 0082 — DB-only provenance, no popup marker); `forms` carries `l_word` (indexed, `idx_forms_l_word`) and is the runtime link target for inflected tokens — the linker's forms pass in [Crossword](/domains/crossword.md) — and the source of the Word popup's base-word groups ([Interactive words](/domains/interactive-words.md), ADR 0045). Every imported word also stores **itself** as its lemma form (shared `App\Support\LemmaForms` rule), so the forms table alone resolves lookups; every ё-form additionally carries its **е-folded alias row** (ёлка AND елка, both keyed `елка`, 2026-10-19) — display `form` keeps the source spelling, only `l_word` folds ё→е. **Definitions** import only when written in the word language's own script (the `DefinitionLanguage` majority-script guard, 2026-10-19 — import time, and the ask-AI enrichment merge since ticket 08, 2026-10-20; existing rows untouched); **etymologies** are never script-guarded (import and AI alike) |
| `transcriptions` | Written phonetic notations per word + `transcription_type_id` (ipa, enpr, …; unique triple) || `pronunciations` | Audio files with pronunciation examples per word (`path` on the public disk, unique `(path, word_id)`); uploaded via the admin |
| `tags` / `tagged_words` | The **words tag pool** (ADR [0076](../../docs/adr/0076-per-type-tag-pools.md)): language-neutral `slug`+`name` labels curated in the admin (`TagResource`, nav group Words, `words_count` usage column, edit/delete + bulk delete) and attached to words via `Word::tags()` (`withTimestamps`) through the pivot — renamed from the dormant `word_tags` (migration `2026_10_11_000001`, index `uk_tagged_words_pair`). Attached through the Words form's searchable tag multi-select with inline create; shown as a badge column and a multi-select table filter on the Words list. The works/entities/alignments pools copy this shape (`work_tags`/`tagged_works`, `entity_tags`/`tagged_entities`, `alignment_tags`/`tagged_alignments`) |
| `word_translations` | **One row per word pair** (symmetric, ADR 0020): `word_a_id`, `word_b_id` with canonical order `a < b` (entity-match convention), unique `(word_a_id, word_b_id)`, index on `word_b_id`. A link is usable from either word; links connect words of **different languages** only (enforced in the admin attach action). Supersedes the directed pivot of ADR 0018 |
| `word_translation_fetches` | Auto-fetch ledger, one row per `(word_id, target_language_id)` (unique): `provider` (yandex/google), `status` (`pending`/`succeeded`/`empty`/`failed`), `linked_count`/`staged_count` (candidates the link-only fetch linked vs staged, migration `2026_10_18_000002`), `attempts`, `last_attempted_at` (migration `2026_09_29_000001`). `status = 'empty'` is the **exclusions list** — every provider answered "no translation", so the pair is never looked up again |
| `word_ai_fetches` | Ask-AI enrichment ledger, one row per `word_id` (unique, migration `2026_10_19_000003`): `provider`/`model` (the resolved `provider:model` halves), `status` (`pending`/`succeeded`/`failed`), `attempts`, `error` (the latest failure, ≤ 500 chars), `asked_at`, `succeeded_at`. **A `succeeded` row is the one-ask-per-word lock** — the endpoint rejects further asks (409) and the payload's `ai_asked` greys the popup's ask icon; `failed` (and `pending`) attempts stay retryable at any count (popup-ai-enrich ticket 04, ADR 0082) |

# Notes

* **Word completeness** (2026-10-19, popup-ai-enrich ticket 02): a stored
  0–7 score of how much is known about a word — ≥ 1 linked translation 2;
  definitions, transcriptions, examples, etymologies 1 each (existence,
  never row counts); a real frequency rank 1 (0 < rank < the sentinel — 0
  is the never-ranked column default, `Word::FREQUENCY_UNRANKED` the
  explicit unranked stamp). `WordCompleteness::score` is the matrix,
  `WordCompleteness::recalcFamily` the lazy recompute: when the popup
  family resolves (`WordFamily::resolve`), a word whose `completeness_at`
  is missing or older than its own `updated_at` or the newest stamp of its
  content children (definitions/transcriptions/examples/etymologies/forms
  — all carry `created_at`+`updated_at` — plus the `word_translations`
  pivot, which carries 2 of the 7 points) is recomputed and re-stamped;
  fresh words are never rewritten. `words:recalc-completeness
  {--stale-only} {--chunk=}` sweeps the same matrix set-based in SQL —
  authoritative full sweep by default (writes only changed values, so a
  re-run is a no-op), `--stale-only` rewrites just what the staleness
  predicate flags (the mode for scheduled sweeps). The dev DB backfill ran
  2026-10-19: 1,494,357 words scanned and stamped in 3m50s, a second
  --stale-only pass rewrote nothing (numbers in `wiki/log.md`). The score
  feeds only the popup's entry ordering — see
  [Interactive words](/domains/interactive-words.md).

* **word_id-leading indexes** (2026-10-18, migration
  `2026_10_18_000001`): every child table carrying a `word_id` FK (`ON DELETE
  CASCADE`) now has a btree leading with `word_id` — `idx_forms_word_id`,
  `idx_transcriptions_word_id`, `idx_examples_word_id`,
  `idx_pronunciations_word_id`, `idx_user_word_word_id`,
  `idx_user_word_event_word_id`, joining the pre-existing
  `idx_definitions_word_id`, `idx_etymologies_word_id`,
  `idx_entity_words_word_id`, `tagged_words`' `(word_id, tag_id)` pair and
  `word_translation_fetches`' `(word_id, target_language_id)` pair. Before,
  `forms` and `transcriptions` only had word_id *buried* behind a leading
  text column (`uk_forms_form_word_id (form, word_id)`,
  `uk_transcriptions_triple (transcription, word_id, transcription_type_id)`),
  so per-word joins, recount batches and FK cascade deletes seq-scanned — the
  root cause of the 17-minute wipe crawl on the 3M-row `forms` table
  (`EXPLAIN` of `WHERE word_id = …` now shows an index scan at ~0.2 ms).
  Coverage is pinned by `tests/Feature/WordIdLeadingIndexesTest.php`; follow
  the `idx_<table>_word_id` naming convention for any future child table.

* The popup aggregates the **word family** per surface token
  (`WordFamily::resolve`, ADR 0045): the linked headword's group plus every
  base headword's group from `forms`. **Relay entries** (form-of relays) —
  Wiktionary's standalone inflected variants ("четвереньках — prepositional
  of четвере́ньки", "simple past and past participle of melt") — are no
  longer word rows: an entry whose every sense carries the dump's
  sense-level `form_of`/`form-of` signal demotes to a **form of its base**
  at import, and the pre-rule rows were demoted in place by
  `wiktionary:demote-form-of` (ru 363,446 + en 500,776 rows deleted,
  2026-10-18 — ADR 0079), so the base's group carries the real content and
  the relay surface resolves to it through `forms`. Mixed entries (real
  senses + relay senses) stay words; the anchored relay-gloss pattern list
  in `WordFamily` stays as the read-layer filter for their relay senses
  (harmless where no relay gloss exists). Escape hatch if patterns misfire:
  an `is_form_of` column set at import + backfill.
* Runtime consumers now exist: `WordController` serves the word popup
  (`GET /words/{word}`, definitions/transcriptions/native-first
  translations/examples) and reads/writes `user_word` progress on behalf of
  the reader and simulator surfaces — see
  [Interactive words](/domains/interactive-words.md). The crossword was the
  first consumer; Filament admin
  (`WordResource` + relation managers, `WordClassResource`,
  `TranscriptionTypeResource`) and the import/link commands remain.
* `wiktionary:import {file} --lang= --target-lang=` fills `words` and
  satellites for one language (any code in the languages registry),
  storing staged translations as JSON; `dictionary:link-translations` then
  resolves them into `word_translations` rows for **every language pair**
  (one canonical row per pair; candidates resolve through the shared
  `App\Support\TranslationTargets` resolver — same class, `phrase` for
  multi-word, else lowest id — with stress-mark stripping at pairing time
  per the `App\Support\StressMarks` list) and **prunes** every linked
  candidate from the JSON, so unmatched candidates are all that stays
  staged between runs. The admin **Translations** tab
  continues the linking
  by hand: attach an existing word, or **Create word & link** for words
  missing from the target language.
* The import auto-creates missing per-language lookups: a dump `pos` first
  canonicalizes through the shared `App\Support\PosAliases` alias table
  (dictionary abbreviations and kaikki slugs → canonical class slugs —
  "adj" joins "adjective" instead of minting a parallel taxonomy), then an
  unseen dump
  `pos` becomes a `word_classes` row and an unseen sound type a
  `transcription_types` row, both with the slug as placeholder `title` —
  nothing is skipped for a missing lookup, so a new language needs no
  seeders. The seeders remain the source of curated en/ru titles.
* `l_word` keys (words and forms) run the shared `App\Support\WordLookupKey`
  pipeline — **trim → lowercase → strip combining marks → strip zero-width
  format characters (`\p{Cf}`) → ё→е fold** — so Russian Wiktionary headwords
  with stress marks (`свобо́дный`), zero-width artifacts and ё/е spellings
  all share one key (`ёлка` and `елка` both key `елка`), and every matcher
  (the entity-word linker's exact pass,
  `words:import-frequency`) compares against the normalized key. The words
  unique keys on this triple (see the table above), so a display spelling
  never forks a word; display text **never folds and never loses ё**
  (ADR
  [0081](../../docs/adr/0081-word-l-word-contract-uniqueness-on-l-word.md)).
  Stored display words are plain letters on BOTH import paths now (the ADR 0078
  kaikki display carve-out is reversed, 2026-10-18): the kaikki importer
  strips its ru/be/uk headwords and inflected forms, the GoldenDict
  importer strips its headwords, forms and staged candidates outright — so
  stressed variants never sit beside their plain twins in the word-family
  views. Definition/example prose keeps its marks. Rows stored before the
  formula reached its current shape are recomputed in place by
  `words:normalize` phase 2 — `words.l_word`, `forms.l_word` and the
  entity tokenizer's `entity_words.l_word` stamps, only rows whose value
  changes — and the duplicate `(l_word, language_id, word_class_id)` rows
  the recompute exposes merge onto one survivor (ё-display first, then
  most definitions, then lowest id) under either schema, which is what
  lets `migrate` build the unique on a pre-migrate database. The dev DB
  ran exactly that switchover on 2026-10-19 — normalize absorbed 20,000
  duplicate rows, the zero-duplicate gate held, and the unique built in
  1s (numbers and decisions in ADR
  [0081](../../docs/adr/0081-word-l-word-contract-uniqueness-on-l-word.md)) —
  so on current databases the recompute/merge phases are no-ops by
  construction. Every imported word
  stores itself as its lemma form (`App\Support\LemmaForms`), a
  headword/form that strips to the empty string stores nothing, and every
  ё-form additionally carries its е-folded alias form row (2026-10-19).
* **Auto-fetched translations** (2026-09-29, link-only since 2026-10-18):
  when a popup word family has no translation link at all,
  `WordController::show` quietly queues `FetchWordTranslations` through the
  `WordTranslationFetchService::dispatchIfEligible` gate; the job walks the
  `WordTranslationResolver` chain — Yandex Cloud Dictionary Lookup first
  (dictionary-grade candidates with parts of speech), Google Translate v2 as
  a single-candidate fallback (`config/services.php`
  `yandex_translate`/`google_translate`, both optional) — and then **never
  creates a word**: candidates normalize (trimmed, stress marks stripped for
  the Cyrillic-list targets ru/be/uk at the job layer too,
  `App\Support\StressMarks`; sentence-punctuation and overlong candidates
  dropped), resolve against the target language's EXISTING words through
  the shared `App\Support\TranslationTargets` resolver, and matching
  candidates become `word_translations` links; unmatched candidates stage
  into the source word's `translations` JSON through the shared
  `App\Support\StagedTranslations` merge for the later link pass, and the
  fetch ledger row records the outcome (`linked_count`/`staged_count`).
  `failed` lookups retry up to `MAX_ATTEMPTS` (6) behind a 24h cooldown;
  `empty` never re-checks; `succeeded` never re-fetches either — staged
  candidates wait for the link pass, not a re-query. The
  `EntityWordAdoption` pass (the 5-minute word-list refresh, or
  `words:adopt-from-entities`) feeds the same pipeline with entity tokens —
  see [Interactive words](/domains/interactive-words.md) and
  [Crossword](/domains/crossword.md).
* **Ask-AI enrichment** (2026-10-19, popup-ai-enrich ticket 04, ADR
  0082): the popup's Ask-AI icon asks the user's explanation model
  (`POST /ai/word-enrich`, BYO key) to fill a sparse word, and the
  leniently-parsed strict-JSON reply (`App\Support\EnrichmentReply` —
  fences/prose stripped, shapes coerced, counts capped 12/8/4/4/4,
  lengths truncated to the column limits) merges **inline** in one
  transaction (`App\Classes\WordAiEnrichmentService`): definitions /
  transcriptions / examples / etymologies exact-text deduped with new
  rows carrying `source = 'ai'`, translations **link-only** per ADR 0078
  (shared `TranslationTargets` + `StagedTranslations` toolkit — zero word
  rows), and the `word_ai_fetches` ledger locking the word on the first
  success (failed attempts stay retryable). The word is touched so the
  stored completeness recalculates; provenance is DB-only — the popup
  renders no marker. See [Interactive words](/domains/interactive-words.md).
* **Tag pools** (2026-10-11, ADR
  [0076](../../docs/adr/0076-per-type-tag-pools.md)): admin-curated labels,
  four separate pools (one definition table + `tagged_<model>` pivot each),
  no shared vocabulary, no polymorphism, no inheritance (a tag applies only
  to the exact row it is attached to), single language-neutral slug+name per
  tag, shipped empty — vocabulary authored in the Filament panel. All four
  pools are live — words (`TagResource`), works (`WorkTagResource`),
  entities (`EntityTagResource`), and alignments (`AlignmentTagResource`) —
  each with its Filament tag resource and its attached model's tags
  multi-select with inline create, badge column, and multi-select filter.
* The legacy 2025 vocabulary domain (`words` in the old shape, `books`,
  `book_word`, `saved_phrases`) was deleted with the 2026-09 rework.
* `words.frequency` is populated by `words:import-frequency {source}`
  (local `rank,word` CSV or named download — OpenSubtitles 2018 for en,
  RNC lemmas for ru; authoritative overwrite) and clamped down by
  `words:import-frequency-lists` (curated band lists, `least(current,
  band)` through direct and form matches, frequency column only), then
  nudged per entity by `WordFrequencyAccrual`. Ranks that move between
  rows — a relay's rank folding into its base at demotion, a merged word's
  rank folding into its survivor — fold as **LEAST** (lower = more
  common, never a worse rank; the 1,100,000 unranked sentinel and the 0
  column default on cmudict/adoption rows are guarded out of the fold) —
  semantics, sources and the correction math in ADR
  [0041](../../docs/adr/0041-frequency-rank-semantics-and-entity-correction.md)
  and [0070](../../docs/adr/0070-frequency-lists-min-merge.md), the
  pipeline in [Crossword](/domains/crossword.md).
