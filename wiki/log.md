# Directory Update Log

## 2026-08-10

* **Fix: chunk-seam head garbage by commit-rollback of the last two
  matches.** Trim-to-last-anchor (above) only drops the *tail* of the current
  chunk; the strict 1:1 RU window gives the DP no backward reach, so the
  garbage re-appears at the *head* of the next chunk (a 1:5 span that scores
  as an anchor and survives trim). `AlignEntitySentences::handle()` now
  rolls back the last `ROLLBACK_MATCHES` (2) committed meaning matches
  before aligning: `rollbackPriorMatches()` deletes their rows (junction
  rows cascade via FK), rewinds the cursor to the first sentence those
  matches covered via `rollbackOffset()` (position in the `order, id`
  sequence), and grows `en_limit`/`ru_limit` by the rolled-back spans so the
  forward reach is unchanged — the DP re-aligns that region with fresh
  forward context. Only matches with junction rows on **both** sides are
  candidates (skip steps and human-edit `alignment_chunk = -1` rows are
  never rolled back). A monotone-cursor safety net force-advances EN past
  the pre-rollback stored offset if a rolled-back commit would otherwise
  not move forward, so the job cannot pinwheel. Each match is rewritten at
  most twice before stabilizing (bounded churn, no schema change). Updated
  ADR 0004 and `domains/sentence-alignment.md` stage 3.
* **Fix: chunk-seam garbage (5:1 / 1:5 mis-pairs) by trim-to-last-anchor.**
  The reworked self-restarting job advanced its cursor by the full chunk
  size and committed every DP match, but the DP force-aligns every sentence
  in the window — so at each seam the EN tail got jammed onto the RU tail
  of the current window (5:1) and the next window's head did the reverse
  (1:5). `AlignEntitySentences::handle()` now commits only matches up to
  and including the last match with `score >= ANCHOR_SCORE_THRESHOLD`
  (0.40) and advances the cursor to that anchor's `en_end`/`ru_end`
  instead of `offset + chunk_size`; the dropped tail is re-aligned with
  fresh context by the next invocation (a port of
  `BilingualAligner._trim_to_last_anchor`). No-anchor chunks commit
  everything (forward-progress guarantee); the final chunk (both windows
  reach their totals) commits everything and completes. Because the cursor
  no longer advances by a fixed chunk size, `alignment_chunk` is now a
  monotonic per-run id (`MAX + 1`, never the human-edit `-1` sentinel) via
  `nextAlignmentChunk()` instead of `intdiv(enOffset, chunkSize)`, so the
  per-chunk idempotent delete can never wipe a previous chunk.
  `alignChunkRemote()` returns the raw python `matches` alongside the
  adapted links/dpPath; `storeAlignmentSegmentFromMatches()` persists only
  the committed prefix (skip-fill bounded by the last anchor, or by the
  full window on the last chunk). `storeAlignmentSegment()` kept for the
  legacy full-alignment path. Updated ADR 0004 (drift handled by trim, not
  overlap) and `domains/sentence-alignment.md` stage 3.
* **Rework: alignment pipeline is now a single self-restarting job driven
  by a 5-minute command.** `AlignEntitySentences::handle()` reads its chunk
  slice from a new per-match cursor (`last_en_sentence_offset` /
  `last_ru_sentence_offset` on `en_ru_entity_matches`) and
  `self::dispatch()`es the next invocation until the cursor reaches
  `en_total_sentences`, then flips to `completed`. The standalone
  `AlignEntitySentenceChunk` job and the `Bus::chain()` fan-out are deleted
  (ADR 0003). Verify + cursor reset + transition run in a shared
  `AlignEntitySentences::begin($id)` static called by all Filament dispatch
  sites and a new `alignments:resume` console command (scheduled
  `everyFiveMinutes()->withoutOverlapping()` in `routes/console.php`). On a
  transient chunk failure `tries=5` with backoff heals in-process; on a hard
  failure `failed()` is terminal and the cursor is **left untouched** so a
  resume is possible. `db_path` column is dropped (it was dead — read by
  nothing). `entity_similarity` is now set by `begin()` rather than the
  chunk chain. `DB_QUEUE_RETRY_AFTER=900` shipped in `.env.example`.
* **Rework: RU alignment window now tracks EN strictly (no overlap).**
  `AlignEntitySentences::RU_WINDOW_OVERLAP = 25` and `ruWindowForEnRange()`
  are deleted. The RU window uses the same offset and limit as the EN
  window, assuming the importer pins EN/RU sentence orders 1:1 (ADR 0004).
  Filament status badge colors and the Alignment view Blade template no
  longer reference the `verifying` state; the status set is
  `pending | aligning | completed | failed`.

## 2026-08-09

* **Fix: alignments editor sentence keys are now language-scoped.**
  `AlignmentEditorApiPresenter::sentencePayload` emitted keys of the form
  `s-{id}` ignoring language; since `en_entity_sentences` and
  `ru_entity_sentences` are separate tables their ids can overlap, so the
  Inertia editor's shared sentence lookup (keyed by that key) let a RU
  sentence clobber an EN sentence with the same id — rendering Russian text
  in the EN column ("ru/ru"). Keys are now `{lang}:s-{id}` (e.g. `en:s-6776`),
  making them unique across the whole drag-and-drop surface. No schema change.

* **Alignments editor (Inertia/React) replaces the Filament entry point for
  pair editing.** New `/alignments` (pair list) → `/alignments/{id}` (editor)
  pages in `resources/js/Pages/Alignments/`, linked from the NavBar instead of
  `/admin/sentence-alignments`. The editor shows paginated EN/RU pair rows with
  @dnd-kit drag-and-drop, inline add/edit/delete, an "Unmatched" pool
  (collapsible, 15/lang, draggable in/out, trash = permanent delete), and
  immediate persistence (optimistic updates reconciled from server responses,
  revert + error banner on failure). Backed by the existing surgical
  `AlignmentEditorController` endpoints (rows/unmatched pagination, create/
  delete pair, add/edit/unlink/hard-delete sentence, `sentences/move`).
  `AlignmentEditorApiPresenter::unmatchedPayload` now nests `{items, meta}`
  including `last_page`; `AlignmentController::index` passes the paginator
  `items()` as a plain array so Inertia props stay a list. Deleted the legacy
  blade `resources/views/alignments/*` + `resources/js/pages/alignments/*`
  (incl. vite entries). Added `@dnd-kit/core|sortable|utilities` to
  `package.json`. New `AlignmentEditorApiTest` (20 cases: pair CRUD, add
  placement, within-row reorder, cross-row relink, unmatched moves, trash,
  pagination, counts) and `AlignmentPagesTest` switched to `assertInertia`
  prop assertions. Filament admin pages untouched.

## 2026-08-06

* **Redesign: Crossword workbench migrated to the wbench design system.**
  Rebuilt `/crossword-react/{lang}` (`CrosswordApp.jsx` and all components:
  `CrosswordHeader`, `CrosswordGrid`, `RightPanel`, `TabContent`,
  `UnsolvedModal`) on `--wbench-*` tokens so it reads as a sibling of the
  Bilinguals simulator instead of the legacy vellum + vermilion + verdigris
  palette. `resources/css/crossword.css` rewritten end-to-end: cold paper
  `--wbench-paper` board, `--wbench-rule` hairlines, Source Serif 4 cell
  letters and definitions, JetBrains Mono coordinate markers and eyebrows,
  IBM Plex Sans chrome. One accent role on this surface — the active
  (currently-selected) word reads as `color-mix(--wbench-accent 14%,
  --wbench-paper)`; solved cells go ink-on-paper (`--wbench-ink` bg + paper
  text), quiet and inactive, so the accent stays exclusive to the active
  word. Both arrow cells now share `--wbench-paper-deep` with a 2px
  accent leading edge — direction reads from layout, not from a second
  hue (the old across=vermilion / down=verdigris split was the loudest
  inconsistency with the system's "one accent job per surface" rule).
  Drag handles flip to `--wbench-accent` on hover, matching the
  `simulator.css` pattern. Added a local `.xword-edge` rule in
  `crossword.css` instead of retinting the global `.ribbon-mark` (which stays
  vermilion — shared with the simulator's `TextContent` and the Reader
  index, so both are untouched). Implemented the four-state contract on the
  grid surface: `CrosswordGrid` now returns a centered serif invitation
  under a mono `NO CROSSWORD LOADED` eyebrow when no crossword is built,
  and a serif `--wbench-danger` line + `Retry` control (re-calling
  `getCrossword`) when `isError` is set — instead of returning `null`.
  Definition rows tightened (`text-lg`, `py-2`, mono zero-padded index
  `01`, `02`); tab strip rewritten with the simulator's `tabClass` + 2px
  accent `Underline`; `RightPanel` ghost buttons retinted to
  `--wbench-accent` on hover. `CrosswordHeader` reworked: compact `py-2`,
  mono `CROSSWORD · EN` eyebrow + serif `Workbench` name, hairline divider
  before the `Build` control (which becomes an ultramarine CTA on
  hairline, not vermilion). No controller, route, request/response, or
  prop shape changed; `useCrossword.js` and `constants.js` untouched.
  Follow-ups noted: (1) `useCrossword` does not expose a build-pending
  flag, so the grid's loading state currently collapses into the empty
  state — adding a `pending` flag would let a `WORKING · BUILDING` mono
  label + `.ai-loader-rule` fill sit here per the four-state contract;
  (2) the global `.ribbon-mark` in `app.css`, and the shared
  `Select` / `Button` / `SelectGroup` components, are still on
  `--color-vellum*` / vermilion — migrating the simulator only (the
  shared form controls are used by no other page) and the global
  ribbon-mark (which would also retint Reader) is left for a later pass
  to keep this change self-contained to the crossword. Bumped
  `conventions/design-system.md` `generated.at`.

* **Redesign: Reader index migrated to the wbench design system.**
  Rebuilt `resources/js/Pages/Reader/ReaderIndexApp.jsx` (the
  `/reader-react/{lang}` index) on `--wbench-*` tokens so it reads as a sibling
  of the Bilinguals simulator instead of the legacy vellum palette. Removed the
  editorial display treatment (italic "Antiphonal" eyebrow, 7xl serif heading,
  italic gloss, vermilion flourish, decorative aside, the `→` arrow belt) and
  replaced it with a single compact hairline toolbar (mono `READER · EN ↔ RU` +
  `Parallel Library` eyebrow, `ml-auto` underline tabs), a single-column dense
  list with mono row numbers `01 02 …` and the existing `.ribbon-mark` hover
  edge. Implemented the four-state contract on the entity list — the page's one
  signature: while Inertia navigates between EN/RU libraries a `.ai-loader-rule`
  fills under the toolbar and a mono `LOADING · {Lang}` label sits in the
  content area (reduced-motion defanged by the existing
  `simulator.css` media query); empty state is the mono `NO TEXTS IN THIS
  LIBRARY` eyebrow + serif invite. No controller, request/response, or prop
  shape changed; `ReaderReactIndex.jsx` (the Inertia wrapper) untouched. The
  entity reader at `/reader-react/{lang}/{entityId}` (`ReaderApp.jsx` /
  `ReaderRow.jsx`) is still on `--color-vellum/*` — tracked as a follow-up in
  `domains/reader.md` so a library switch does not cross palettes when entering
  a text. Bumped `conventions/design-system.md` and `domains/reader.md`.

* **Redesign: Bilinguals simulator + design system convention.** Redesigned
  `/bilinguals/en/ru/simulator` with a cold-paper palette (`--wbench-paper`
  #FBFAF8 / `--wbench-ink` #0D0D0F / `--wbench-accent` ultramarine #1F3DDB) and
  three typefaces (Source Serif 4 reading / IBM Plex Sans chrome / JetBrains
  Mono marginalia), all scoped via new `--wbench-*` tokens in
  `resources/css/app.css` so other app pages keep the `--color-vellum/*`
  identity. Densified the layout (22px reading, `py-2` rows, 168px workplace),
  added an empty/loading/answer/error four-state AI Response rail (signature
  surface), retinted drag handles and `.ribbon-mark`, and removed the legacy
  red/blue/yellow literals from `public/css/simulator.css`. Controllers and
  request/response shapes untouched. Added `conventions/design-system.md`
  documenting tokens, type roles, compact scale, layout principles, and the
  four-state contract for request-replaceable surfaces; linked from
  `conventions/index.md` and root `index.md`.

## 2026-08-04

* **Pest TIA Engine**: Bumped Pest 4 → 5 and PHPUnit 12 → 13
  (`composer.json` `pestphp/pest: ^5.0`, `pestphp/pest-plugin-laravel: ^5.0`,
  `phpunit/phpunit: ^13.0`) to enable the [Pest Tia Engine](https://pestphp.com/docs/tia)
  (Test Impact Analysis — re-run only tests affected by your changes). Added
  `composer run test:tia` (uses `XDEBUG_MODE=coverage` env var so parallel
  workers inherit Xdebug coverage for the baseline run) and
  `laravel/scripts/tia-setup.php` (initialises a container-local git repo at
  `/var/www` with a baseline commit, since the Laravel project is bind-mounted
  separately from the git repo root at `/var/repo`). Configured
  `pest()->tia()->filtered()->baselined()` in `tests/Pest.php`. Added
  `.github/workflows/tia-baseline.yml` for CI baseline sharing and installed
  `gh` CLI in `LaravelDockerfile`. Updated `playbooks/running-tests.md`.

## 2026-08-03

* **Python service: two-model split + no-rebuild iteration**. Split the single
  BGE-M3 model into two: BGE-M3 (1024-dim) stays for signatures (`/embed`,
  `/embed/batch`, `/cosine/batch`); a new smaller
  `paraphrase-multilingual-MiniLM-L12-v2` (384-dim) powers `/align` for ~2.5–3×
  faster CPU alignment. Coral score distribution differs from BGE-M3 — the
  `ALIGN_DEFAULT_THRESHOLD` likely needs ~0.55 (tunable live). Refactored
  `ext_python` so the image only carries Python+pip packages; source code
  (`ai/`, bind-mounted) and model weights (named Docker volume `ai_models`
  at `/app/models`) are external — rebuilding is only needed for new
  `requirements.txt` packages. Models load lazily (empty FastAPI lifespan +
  `ai/models_cache.py`) so `uvicorn --reload` restarts in ~1–2s after a `.py`
  edit; the first request lazy-loads the model it needs. `docker-compose/python/
  env/.env` is bind-mounted at `/app/env/.env` (directory mount, not single-file)
  and live per-request accessors in `ai/config.py` re-read it on each call, so
  threshold/window/model-path edits apply without container recreate or restart.
  Added `docker-compose/python/scripts/download_model.py` for adding/swapping
  models into the volume. Removed `docker-compose/python/ai/bge_m3_local/` from
  the source tree (now in the named volume). Updated `docker-compose.yml`
  (python service: `command:` `--reload`, `env_file:`, new volumes, top-level
  `ai_models` volume), `Dockerfile` (no `COPY ai/`, no hardcoded `MODEL_PATH`),
  `ai/main.py` (empty lifespan), `ai/config.py` (live accessors), new
  `ai/models_cache.py`, and all `ai/api/*.py` handlers. Updated
  `domains/sentence-alignment.md`.
  * Also updated `architecture/docker-services.md` (python service description,
    new mounts, `ai_models` volume).

* **Database safety hardening.** A real dev-database wipe (`migrate:fresh`
  against the default `pgsql` connection) triggered a review of the test
  database setup. Changes:
  * `config/database.php`: added a dedicated `testing` connection (pgsql clone
    with `database => env('DB_TEST_DATABASE', 'ext_app_test')`). Tests are now
    bound by connection *name*, not by mutating `DB_DATABASE` on the shared
    `pgsql` connection.
  * `phpunit.xml`: `DB_CONNECTION=testing` + `DB_TEST_DATABASE=ext_app_test`
    (force), keeping `DB_DATABASE=ext_app_test` as a backstop.
  * `tests/TestCase.php` guard tightened: refuses to boot unless the resolved
    default connection is `testing` and its database is `ext_app_test` (was
    only checking the `pgsql` connection's database name).
  * `tests/Pest.php`: added `beforeEach` assertion that
    `DB::connection()->getName() === 'testing'`.
  * `composer.json`: `test:tia` now passes `--drop-databases` so parallel
    Pest workers drop their `ext_app_test_test_{N}` DBs after each run (they
    were accumulating as orphans).
  * Cleaned up 16 orphaned `ext_app_test_test_{N}` databases on the dev
    Postgres instance.
  * `AGENTS.md`: documented the `testing`-connection rule for destructive ops.
  * The main dev DB `ext_app` is currently empty (from the wipe); restore via
    the alignment pipeline when needed.

## 2026-07-28

* **Entity sentence editing**: Added *Sentences* Filament relation managers to
  `EnEntityResource` and `RuEntityResource`, reachable from each entity list
  via a new Sentences row action. Sentences can be created, edited, deleted,
  and reordered with sparse order gaps maintained by `SparseOrderService`.
  `SentenceType` defaults to `sentence`. Deleting a sentence removes its per-side
  meaning matches and cleans up empty `EnRuMeaningMatch` rows, updating the
  parent match's `linked_count`. Added `EntitySentencesRelationManagerTest`.
  Updated `database/entities-alignment.md`, `domains/sentence-alignment.md`,
  `reference/models.md` (via `wiki:sync`).

## 2026-07-27

* **Python service**: Replaced the `ext_embedding` container with `ext_python`
  (same host port 8001): a restructured FastAPI package under
  `docker-compose/python/ai/` (`api/`, `splitting/`, `signatures/`,
  `similarity/`, `alignment/`) serving BGE-M3 (1024-dim, bind-mounted from
  `ai/bge_m3_local`, `HF_HUB_OFFLINE=1`). Sentence splitting and DP alignment
  moved from PHP into python (`/split`, `/align`); Laravel orchestrates chunks
  and owns all DB writes. Config renamed `services.embedding.*` →
  `services.python.*` (new `align_timeout`, dropped dead batch keys); old
  384-dim e5-small signatures must be regenerated. Updated
  `architecture/docker-services.md`, `architecture/overview.md`,
  `domains/sentence-alignment.md`, `playbooks/run-alignment.md`,
  `database/entities-alignment.md`, `AGENTS.md`.
* **Test safety**: Hardened the test environment against wiping the real
  `ext_app` database: `TestCase::createApplication()` guard aborts the suite
  if the resolved DB is not `ext_app_test`, `phpunit.xml` env entries gained
  `force="true"`, and a committed `.env.testing` pins the test DB. Updated
  the Running Tests playbook.

## 2026-07-26

* **Initialization**: Created the OKF v0.2 bundle: architecture, feature
  domains, database, playbooks, conventions sections, plus auto-generated
  `reference/` concepts via `php artisan wiki:sync`.
* **Tooling**: Added `php artisan wiki:sync` and `php artisan wiki:validate`
  (OKF conformance, broken links, staleness) and a Pest test enforcing
  conformance in the test suite.
* **Integration**: Root `AGENTS.md` gained a "Knowledge base" section routing
  agents here; `wiki/` is bind-mounted into the app container at `/var/wiki`.
