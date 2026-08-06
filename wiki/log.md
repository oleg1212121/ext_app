# Directory Update Log

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
