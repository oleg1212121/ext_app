# ADR index

Architecture decision records, grouped by area. All are `Status: accepted`
unless noted. Numbering is append-only; 0088/0089 were renumbered from
0001/0002 on 2026-10-25 (their original numbers collided with the older
alignment pair — see the renumber notes inside those files).

## Sentence alignment & the editor

| # | Title |
|---|-------|
| [0001](0001-empty-meaning-matches-persist.md) | Empty meaning matches persist |
| [0002](0002-react-editor-immediate-persistence.md) | React alignment editor with immediate persistence |
| [0003](0003-self-restarting-alignment-job.md) | Self-restarting alignment job replaces `Bus::chain()` fan-out |
| [0004](0004-sequential-ru-alignment-window.md) | Sequential RU alignment window replaces drift-aware overlap |
| [0005](0005-junction-order-independent-of-sentence-order.md) | Junction order is independent of sentence order — **superseded by [0016](0016-drop-position-wins-alignment-editor.md)** |
| [0016](0016-drop-position-wins-alignment-editor.md) | Document order is the single ordering truth in the alignment editor |
| [0019](0019-same-language-entity-matches.md) | Same-language entity matches |
| [0033](0033-exact-copy-cloning-and-hash-based-alignment-reuse.md) | Exact-copy cloning and hash-based alignment reuse |
| [0036](0036-alignments-live-under-work.md) | Alignments live under their work |
| [0048](0048-strict-alignment-invariants.md) | Strict alignment invariants — junction uniqueness, total completeness, weak-pair rescue |
| [0049](0049-human-confirmed-single-sided-leave-needs-review.md) | Human-confirmed single-sided meaning matches leave Needs review |
| [0051](0051-scheduler-resume-preserves-landmarks.md) | Scheduler resume preserves landmarks for set-up matches |
| [0055](0055-stale-status-no-auto-realign.md) | Sentence edits mark matches stale; only explicit Re-align re-aligns |
| [0062](0062-one-alignment-editing-domain.md) | One alignment-editing domain — amends 0002's two-editor split |
| [0063](0063-meaning-match-store.md) | MeaningMatchStore — the pipeline's write path, split from the adapter |
| [0064](0064-sentence-order-service.md) | SentenceOrderService — one placement pipeline, one cosine |
| [0065](0065-entity-sentence-store.md) | EntitySentenceStore — one sentence-mutation flow |
| [0072](0072-work-nested-alignment-editor-routes.md) | The alignment editor moves onto the work branch |

## Works, entities & access control

| # | Title |
|---|-------|
| [0008](0008-admin-bypass-via-gate-before.md) | Admin bypass via Gate::before |
| [0013](0013-default-restricted-uploads-and-per-entity-grants.md) | Default-restricted uploads with per-entity access grants |
| [0014](0014-per-entity-grants-require-both-sides-for-simulator.md) | Per-entity grants require both sides to read a simulator match |
| [0015](0015-granted-users-edit-entities-and-sentences.md) | Granted users may edit entities and sentences in the entities frontend |
| [0018](0018-works-and-unified-language-keyed-tables.md) | Works and unified language-keyed tables replace the mirrored EN/RU schema |
| [0021](0021-works-are-a-public-catalog.md) | Works are a public catalog |
| [0034](0034-entity-uploader-tracking-and-approved-edit-lock.md) | Entity uploader tracking and the approved edit lock |
| [0073](0073-work-nested-entity-routes.md) | Entity routes move onto the work branch |
| [0088](0088-languages-standalone-registry.md) | Languages table is a standalone admin registry (renumbered from 0001) |
| [0089](0089-languages-enabled-first-consumer.md) | Language::enabled() is wired into the front-end entities surface (renumbered from 0002) |

## AI providers & models

| # | Title |
|---|-------|
| [0006](0006-db-backed-ai-model-catalog.md) | DB-backed AI model catalog replaces hardcoded lists |
| [0007](0007-shared-model-sync-for-all-providers.md) | Shared model-sync for all AI providers |
| [0009](0009-class-map-driven-provider-registry.md) | Provider registry is class-map-driven, not DB-driven |
| [0010](0010-api-keys-in-env-not-db.md) | API keys stay in .env, not the ai_providers table |
| [0011](0011-sync-ignores-provider-enabled.md) | Sync ignores the provider enabled flag by design |
| [0012](0012-per-user-api-keys.md) | Per-user API keys replace the env-only key model |
| [0035](0035-per-user-ai-model-preferences.md) | Per-user AI model preferences, resolved server-side |
| [0040](0040-server-assembled-assessment-question.md) | Server-assembled assessment question from DB prompt templates |

## Queues & processing limits

| # | Title |
|---|-------|
| [0042](0042-two-lane-strict-priority-queues.md) | Two-lane strict queue priority with a per-job lane property |
| [0043](0043-bounded-job-runs.md) | Bounded job runs with durable progress markers |
| [0044](0044-per-user-processing-limits.md) | Per-user processing limits |

## Dictionary import & word storage

| # | Title |
|---|-------|
| [0020](0020-symmetric-word-translations.md) | Symmetric word translations: one row per pair |
| [0029](0029-import-raw-dump-via-per-language-extraction.md) | Import the monolithic raw wiktextract dump via per-language extraction |
| [0059](0059-spacy-multiword-verb-matching.md) | Multi-word verbs via spaCy dependency parsing |
| [0070](0070-frequency-lists-min-merge.md) | Band word lists min-merge onto frequency ranks |
| [0078](0078-goldendict-plain-words-link-only-translations.md) | GoldenDict imports store plain words, keep phrases out of translations, and never create target words |
| [0079](0079-form-of-relays-demote-to-forms-stage-link-prune.md) | Form-of relays demote to forms, every word is its own lemma form, translations follow one stage → link → prune lifecycle |
| [0080](0080-per-edition-wiktionary-sourcing-merge-on-overlap.md) | Each language's dictionary sources from its own Wiktionary edition dump; editions merge on overlap |
| [0081](0081-word-l-word-contract-uniqueness-on-l-word.md) | The word/l_word contract — plain display text, one ё-aware lookup key, uniqueness on (l_word, language, class) |
| [0084](0084-word-source-provenance.md) | Word rows carry an insert-stamped source; first importer wins, re-runs fill nulls only |

## Word popup, Anki & word enrichment

| # | Title |
|---|-------|
| [0030](0030-lookup-requires-ctrl-click.md) | Opening the word popup requires Ctrl+click |
| [0031](0031-popup-typography-follows-page-font.md) | Word-popup typography follows the host page's font setting |
| [0045](0045-word-popup-aggregates-the-word-family.md) | Word popup aggregates the word family |
| [0046](0046-per-user-popup-section-visibility.md) | Per-user popup section visibility |
| [0047](0047-class-scoped-word-family-base-groups.md) | Class-scope word-family base groups |
| [0077](0077-browser-side-ankiconnect.md) | Anki export is browser-side — the browser calls AnkiConnect, Laravel only stores settings |
| [0082](0082-ask-ai-enrichment-merge-inline-lock-on-success.md) | Ask-AI word enrichment merges inline with per-row provenance, locks on success, keeps translations link-only (re-askable since the 2026-10-20 amendment) |
| [0083](0083-usage-labels-and-completeness-v2.md) | Usage labels are a closed, going-forward set; completeness re-weights to 0–9 with live entries before demoted ones |
| [0085](0085-staged-translations-popup-raw-line.md) | The popup surfaces staged translations as a language-less raw line; Anki keeps linked translations only |

## Familiarity & word knowledge

| # | Title |
|---|-------|
| [0028](0028-numeric-word-familiarity.md) | Numeric word familiarity (0–100) replaces the ternary word status |
| [0041](0041-frequency-rank-semantics-and-entity-correction.md) | Frequency rank semantics and the entity corpus correction |
| [0071](0071-word-test-presumed-known-baseline.md) | Word test writes a presumed-known familiarity baseline — sampling/scoring superseded by [0090](0090-cefr-anchored-word-test.md) |
| [0074](0074-entity-word-knowledge.md) | Entity pages show a per-user word-knowledge percentage |
| [0075](0075-recommendations-page.md) | A recommendations page re-ranks the viewer's opened texts by word knowledge |
| [0090](0090-cefr-anchored-word-test.md) | The word test samples CEFR tag groups and persists a vocabulary level — fixed band anchors, adaptive retakes, raise-only `users.vocabulary_level` |

## Reader & reading surfaces

| # | Title |
|---|-------|
| [0027](0027-render-time-word-segmentation.md) | Render-time word segmentation (no persisted word positions) |
| [0032](0032-reader-pagination-and-reading-position.md) | Reader pagination and reading position |
| [0037](0037-reading-side-rule-and-reader-route.md) | Reading side defaults to the native language; the reader route drops its language segment |
| [0038](0038-practice-menu-revives-reader-index-and-simulator-picker.md) | The Practice menu revives the reader index and the simulator picker |
| [0039](0039-library-dropdown-works-branches.md) | The Library dropdown and the works URL branches |
| [0050](0050-illustrations-are-sentences.md) | Illustrations are sentences |
| [0060](0060-reading-rows-one-row-object-payload.md) | Reading rows — one row-object payload for the reading surfaces |
| [0068](0068-reading-surface-chrome-kit.md) | Reading-surface chrome kit |
| [0069](0069-one-json-envelope-reading-surfaces.md) | One JSON envelope for the reading-surface endpoints |

## Sentence enrichment

| # | Title |
|---|-------|
| [0052](0052-local-sentence-enrichment.md) | Local-only sentence enrichment stored beside content |
| [0053](0053-english-stress-v2.md) | English stress marking v2 — syllable-aligned placement + CMUdict |
| [0057](0057-enricher-registry.md) | Enricher registry — per-language algorithms and per-enricher staleness |
| [0058](0058-phrasal-lead-verb-lemmas.md) | Phrasal lead resolution via verb-lemma candidates |
| [0067](0067-annotation-descriptor-and-python-version-parity.md) | Annotation descriptor and python version parity |

## Crossword

| # | Title |
|---|-------|
| [0025](0025-crossword-word-index-and-progress.md) | Crossword word index and progress on the new schema |
| [0026](0026-background-word-list-refresh.md) | Background word-list refresh via scheduled sweep and queue |

## Localization & user settings

| # | Title |
|---|-------|
| [0017](0017-user-settings-table.md) | User settings live in a dedicated table, not on `users` |
| [0022](0022-ui-strings-in-db-behind-group-translation-loader.md) | UI strings live in the database behind a group translation loader |
| [0023](0023-interface-language-setting-with-native-fallback.md) | Interface language is a user setting with native-language fallback |
| [0024](0024-split-persistence-for-simulator-ui-state.md) | Split persistence for simulator UI state |

## Phonemes & pronunciation

| # | Title |
|---|-------|
| [0054](0054-cc0-sagittal-phoneme-diagrams.md) | Phoneme reference — parametric CC0-lineage diagrams, no audio, no external media |
| [0056](0056-pronunciation-guide-page-under-resources-dropdown.md) | Pronunciation guide becomes a page under a Resources dropdown |

## Infrastructure & testing

| # | Title |
|---|-------|
| [0061](0061-one-python-service-client.md) | One client for the python service |
| [0066](0066-vitest-for-pure-logic-js-tests.md) | vitest for pure-logic JS tests |
| [0076](0076-per-type-tag-pools.md) | Tag pools are per-type — four definition tables and `tagged_<model>` pivots |
