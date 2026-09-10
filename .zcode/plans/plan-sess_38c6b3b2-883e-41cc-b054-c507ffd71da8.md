# Works + unified language-keyed tables — fresh schema on `new-database-structure`

User directives incorporated: **data in current DBs is disposable** (no backfill — delete freely), **drop all unused/legacy tables**, **squash migrations** (delete old ones, write a fresh set), branch already created (`new-database-structure`, clean tree).

## Settled design (from the grilling session)
- `works` (title, author null, description null, original_language_id → languages) — replaces `is_original_en`; truth survives when the original text is never uploaded.
- Unified `entities` (work_id NOT NULL cascade, language_id, name, label null for same-language variants, description, signature, file_path, is_restricted) + `entity_sentences` (unique(entity_id, order)) + `entity_user` grants (similarity pivot) — grants stay **per-entity**, ADR 0013/0014/0015 semantics unchanged.
- Unified a/b alignment chain: `entity_matches(a_entity_id, b_entity_id, unique pair, canonical a<b, counters a_/b_ prefixed)` → `meaning_matches` → one `sentence_meaning_matches(entity_sentence_id, meaning_match_id, side char(1))`.
- Original side derived at runtime (side language == work.original_language_id); **neither side original ⇒ cover both sides** with skip rows + finalize repairs (translation↔translation matches are first-class).
- Match creation validates: same work, different languages. Filament "Suggest works" action via signature cosine (0.95).
- Unified dictionaries: `word_classes` + `transcription_types` with language_id; `words` + one of each satellite (forms, definitions, transcriptions, etymologies, pronunciations, examples, tags pivots); one directed `word_translations(from_word_id, to_word_id)`.
- UI stays language-first, work-aware (work column/filter, work picker on create, is_original_en radio removed). No language #3 yet — schema is ready, nothing script-specific.

## 1. Migration squash (delete all 23 files, write fresh consolidated set)
Delete everything in `database/migrations/`. New set (Laravel baselines kept, domain migrations consolidated):
1. Keep `0001_01_01_*` users/cache/jobs baselines, with users table **including** role/approval columns (folds 2026_08_18).
2. `create_languages_and_user_settings_table` — languages (code unique, name, native_name, is_enabled, sort_order) + user_settings (native_language_id FK; no backfill — nullable).
3. `create_ai_tables` — ai_providers, ai_models (provider_id NOT NULL folded), user_api_keys.
4. `create_works_and_entities_tables` — works, sentence_types, entities, entity_sentences (unique(entity_id, order) inline), entity_user (unique(entity_id, user_id), similarity) — all indexes/uniques from the 08_25/08_26/09_09 migrations folded into creation.
5. `create_alignment_tables` — entity_matches (a/b FKs, unique(a,b), index(b, status), counters/cursors a_/b_), meaning_matches (unique(entity_match_id, order), index(entity_match_id, alignment_chunk)), sentence_meaning_matches (side, both FK indexes, unique(entity_sentence_id, meaning_match_id, side)).
6. `create_dictionary_tables` — word_classes/transcription_types (unique(language_id, slug)), words (unique(word, language_id, word_class_id), index(l_word, word_class_id), translations JSON), 7 satellites + tags/word_tags with current uniques, word_translations (unique(from_word_id, to_word_id)).
Nothing from `2025_07_08_create_legacy_tables` is recreated — the whole legacy vocabulary domain is dropped.

## 2. Legacy vocabulary domain — deleted entirely (user-approved "drop it all")
Verified: current SimulatorController/ReaderController have zero references; legacy migration creates only drop-set tables.
- **Tables gone** (via squash): words, books, book_word, book_text_files*, definitions, etymologies, transcriptions, translations, forms, saved_phrases (*book_text_files if created there/elsewhere — verify at delete time).
- **Models deleted**: Word, Book, BookWord, BookTextFile, Definition, SavedPhrase.
- **Routes/endpoints deleted**: `/test`, `/crossword`, `/crossword-react/{lang}`, `/get-crossword`, `/get-texts` (both), `/word/upvote|acknowledge|dismiss|ask-ai`, `/dictionary/selection/save`, `/dictionary/interactions/save` + their requests (DictionaryInteractionsSaveRequest, GetCrosswordRequest, Word{Upvote,Acknowledge,Dismiss}Request).
- **Code deleted**: Test.php legacy endpoints (preserve the current Reader page route if it routes through Test::reader — repoint to ReaderController first), BilingualsController (current SimulatorController in Bilinguals/ is untouched), Crossword class, Livewire Crossword + WordsSearch components/views, KaikkiParser.
- **Filament deleted**: BookResource, BookTextFileResource, DefinitionResource, WordResource, SavedPhraseResource.
- **Frontend deleted**: crossword React pages/components + word-interaction UI + any fetch calls to removed endpoints (grep resources/js for each URL); remove nav links.
- Rule for this pass: verify remaining references before each deletion; Reader and current Simulator features must keep working.

## 3. Unified schema cutover (code)
- **Models**: new Work, Entity, EntitySentence (single booted() orphan-GC + linked_count refresh), EntityMatch (+ `originalSide(): 'a'|'b'|null`), MeaningMatch, SentenceMeaningMatch (side), Word, WordTranslation + merged satellites. Delete En*/Ru* twins.
- **EntityController**: `queryForLanguage()` → `Entity::where('language_id', …)`; delete match-statement/sentenceClass/entityForeignKey helpers; `/entities/{lang}` route shape unchanged; store() gains work pick-or-create; uploads `entities/{code}`.
- **EntityAccessService**: single readableQuery/readableMatchQuery, semantics unchanged.
- **Jobs** (ProcessEntityFile, GenerateEntitySignature, AlignEntitySentences): drop LANG_MODELS maps; sides as `['a'=>…,'b'=>…]`; skip/finalize via originalSide(), null ⇒ both sides. TextSignatureService + SentenceSplitter pass entity language to python /embed + /split.
- **SentenceAlignmentService**: a_sentences/b_sentences payload, skip_a/skip_b steps, a_start… landmarks. **Alignment editor stack** (Controller/Persister/Presenter/ApiPresenter/DraftStore): side dispatch 'a'/'b'; SentenceLangRequest → side in:a,b; Filament EditEntityAlignment draft keys a/b.
- **SimulatorController/MeaningMatchPresenter/ReaderController**: rows from side column; reader route constraint `en|ru` → `[a-z]{2}` + resolveLanguage.
- **EntitySentenceImporter**, Import/Rebalance/GenerateSignatures commands: single generic pass.
- **StoreEnRuEntityMatchRequest → StoreEntityMatchRequest** (same work, distinct languages); AlignmentController drops is_original_en.
- **Dictionary import**: WiktionaryParser/ImportWiktionaryCommand/LinkTranslationsCommand → unified tables (--lang exists); merge En/Ru word-class + transcription-type seeders with language_id.
- **User**: grantedEntities() replaces grantedEn/RuEntities.

## 4. Filament
New `WorkResource` (+ Entities relation manager); `EntityResource` replaces the twin entity resources (language select, work select, language-aware Find Match); `EntityMatchResource` (original-language badge from work; Re-align/Run-from-scratch preserved; "Suggest works" signature-hint action); `WordResource` replaces twin word resources (+ merged RelationManagers); LanguageResource unchanged.

## 5. Frontend (Inertia/React)
Entities list: work column + filter; create: work dropdown with inline new-work (title/author/original language). Alignments/Create.jsx: work picker filters both language dropdowns; original-language radio removed (read-only display). Editor payloads/Show.jsx: a/b side keys, display labels from language codes. Nav cleanup after legacy deletion.

## 6. Python service (docker-compose/python/ai)
`api/schemas.py` + `api/align.py`: en_sentences→a_sentences, ru_sentences→b_sentences, en_start/en_end→a_start/a_end, unmatched_en/ru→unmatched_a/b, landmark keys likewise; response matches a_/b_ spans; bilingual_aligner internals renamed only where contract names leak (logic untouched). No compat shim — single coordinated deploy.

## 7. Tests (Pest, in docker)
Mechanically port existing entity/alignment/editor/access-control tests to unified models + sides. New tests: same-work + distinct-language validation; canonical a<b pair; originalSide derivation incl. neither-original ⇒ cover-both-sides; ADR 0013/0014 grant parity. Delete tests covering legacy endpoints. `composer run test` + `vendor/bin/pint --dirty` green.

## 8. Database reset + rollout
- Dev + test DBs: `migrate:fresh --seed` (user-authorized wipe; per AGENTS.md verify resolved DB is `ext_app_test` when using DB_CONNECTION=testing; never two concurrent test runs).
- Consequence of squash (stated plainly): any deployed environment (prod) must be rebuilt fresh at next `./deploy.sh` — old migration history won't match; prod data is forfeit per your directive.

## 9. Docs (repo maintenance rules)
- `docs/adr/0018-works-and-unified-language-keyed-tables.md` — decision, duplication-tax evidence, alternatives (per-language mirrors, partitioning), consequences (language = INSERT, canonical pairs, derived original side, migration squash + fresh-baseline rollout).
- `CONTEXT.md`: add **Work**, **A-side/B-side**, **Cover-both-sides**; update **Entity**, **Original text**; drop legacy terms if any.
- wiki: rewrite `database/schema-overview.md` (new table set), `database/entities-alignment.md`, `database/en-ru-dictionary.md` (→ unified dictionary), `domains/entities.md`, `domains/sentence-alignment.md`, `domains/dictionary-import.md`, `domains/reader.md`; retire crossword/words-search/legacy-vocabulary concepts; update playbooks (run-alignment, import-dictionary-data); bump generated.at + log.md entries; regenerate reference via `php artisan wiki:sync`; `wiki:validate` passes.

## Commit sequence on `new-database-structure`
1. Squashed migrations + legacy domain deletion (schema fresh start)
2. Models + app/services/jobs/controllers cutover
3. Filament rework
4. Frontend rework
5. Python a/b contract rename
6. Tests green + pint
7. ADR 0018 + CONTEXT.md + wiki updates + wiki:sync

## Out of scope
Language #3 enablement (INSERT later), work-first browsing UI, work-level/hybrid grants, Postgres partitioning, non-vocabulary legacy cleanup beyond §2.