---
type: Playbook
title: Running Tests
description: How to run the Pest test suite against the dedicated ext_app_test database, the vitest suite for pure JS logic, and the ESLint static-analysis pass.
tags: [testing, pest, vitest, eslint]
status: stable
generated: { by: agent:zcode, at: 2026-10-23T13:20:00+03:00 }
verified: { by: agent:zcode, at: 2026-10-15T15:45:00+03:00 }
sources:
  - id: phpunit
    resource: laravel/phpunit.xml
    title: PHPUnit configuration (test DB name, forced env)
  - id: composer
    resource: laravel/composer.json
    title: Composer scripts (test script)
  - id: testcase
    resource: laravel/tests/TestCase.php
    title: Base test case (test-database guard, withoutVite)
  - id: fakepython
    resource: laravel/tests/Support/FakePythonClient.php
    title: FakePythonClient (the in-memory python transport adapter for tests)
  - id: envtesting
    resource: laravel/.env.testing.example
    title: Testing environment file template (tracked; .env.testing is gitignored)
  - id: ciworkflow
    resource: .github/workflows/tests.yml
    title: Tests workflow (CI env provisioning, wiki mtime skip, frontend vitest + eslint lint jobs)
  - id: wikiconfig
    resource: laravel/config/wiki.php
    title: Wiki validation config (skip_mtime_staleness flag)
  - id: wikibaseline
    resource: wiki/validate-baseline.json
    title: Acknowledged-warning baseline (exact-message entries, owner+date; --prune keeps it current)
  - id: sourcebadgepin
    resource: laravel/resources/js/Components/WordPopup.sourceBadge.test.js
    title: The lift-and-evaluate source pin (derived logic evaluated against payload states)
  - id: ankisendpin
    resource: laravel/resources/js/Components/WordPopup.ankiSend.test.js
    title: The contains-only wiring source pin (send-path pass-throughs)
  - id: packagejson
    resource: laravel/package.json
    title: NPM scripts (vitest run, eslint)
  - id: eslintconfig
    resource: laravel/eslint.config.js
    title: ESLint flat config (correctness rules over resources/js, warn-tier carve-outs)
  - id: pytestini
    resource: docker-compose/python/ai/pytest.ini
    title: Python service pytest config (markers, pythonpath)
  - id: pyconftest
    resource: docker-compose/python/ai/conftest.py
    title: Python suite shared fixtures (StubModel, cache resets, api_client)
---

# Facts

* Framework: **Pest 5** on PHPUnit 13 (bumped from Pest 4 / PHPUnit 12 to
  enable the TIA engine).
* Test database: **`ext_app_test`** (set in `phpunit.xml` and `.env.testing`,
  not the default `testing` DB). Tests use `QUEUE_CONNECTION=sync`, array
  cache/session.
* **The suite is hermetic — and green.** `tests/Pest.php` arms
  `Http::preventStrayRequests()` for every Feature test, and `phpunit.xml`
  force-blanks the external API keys (`OPENROUTER_API_KEY`,
  `GOOGLE_TRANSLATE_API_KEY`, `YANDEX_TRANSLATE_API_KEY`), so an unfaked
  network call fails fast and "no provider configured" is the default test
  world (a leaked real Google key used to keep 10 tests permanently red with
  `API_KEY_SERVICE_BLOCKED` — fixed 2026-10-14). **Any failure on a clean
  tree is yours**: never wave it off as pre-existing. Tests opt into the
  translate providers per test via `enableTranslationProviders()` +
  `Http::fake()`.
* **CI needs a `.env` file.** Laravel boots off `.env` via phpdotenv's
  `@file_get_contents('.env')`; with no env file at all, PHPUnit flags a
  warning on roughly every test and (under the CI runtime) ~45 of them
  escalate to failures. `.env` is gitignored, so the Tests workflow
  (`tests.yml`) provisions it with `cp .env.example .env` before running —
  `.env.example` is secret-free, and `phpunit.xml` (force) plus the workflow
  `env:` (APP_KEY + DB connection) supply the real test values.
* **Tests run without Vite.** CI never builds frontend assets and
  `public/build/` is gitignored, so `Tests\TestCase::setUp()` calls
  `$this->withoutVite()`: every `@vite` / `@viteReactRefresh` in a Blade
  layout renders as empty tags instead of throwing
  `ViteManifestNotFoundException` (which turned ~40 page-render tests into
  500s in CI). A test that needs real asset resolution calls
  `$this->withVite()` — that requires a built manifest (or a running dev
  server), so it only works locally.
* **CI mutes wiki mtime staleness warnings.** A fresh git checkout stamps
  every file with mtime = checkout time, so `wiki:validate`'s "source
  changed after generated.at" signal would fire on every CI run for every
  source. The Tests and TIA Baseline workflows set
  `WIKI_SKIP_MTIME_CHECKS=1`, read via
  `config('wiki.skip_mtime_staleness')` in `WikiValidator`. Local runs keep
  the signal, and it is a gate: `wiki:validate` exits 1 on any warning not
  acknowledged in `wiki/validate-baseline.json` (entries matched by exact
  message, each requiring non-empty owner+date; `--prune` drops entries
  that no longer match, refuses to run under the mtime skip, and writes
  nothing when nothing would change; a malformed baseline is an error).
  CI stays trivially green — the
  muted check covers every currently baselined warning — and the gate still
  fails on any non-mtime warning.
* `composer run test` clears the **config and route caches** first, then runs
  the suite **in parallel** (Pest `--parallel`, one process per CPU core —
  8 in the dev container). Each parallel worker runs against its own
  temporary database `ext_app_test_test_{N}`; the base `ext_app_test` is only
  used by serial runs (`artisan test --filter=…`, single-file runs).
  Routing the test run through `route:clear` is required because
  `deploy.sh` runs `php artisan route:cache`, which bakes the production
  `APP_KEY` into the cached routes; Livewire derives its update endpoint from
  `APP_KEY`, so a stale route cache built under a different key makes every
  interactive Livewire/Filament test 404 (`mountedActions on null`).
* **Safety layers against wiping the real `ext_app` database:**
  * A dedicated **`testing` connection** (a `pgsql` clone with
    `database => env('DB_TEST_DATABASE', 'ext_app_test')`) is defined in
    `config/database.php`. Tests are bound by connection **name**, not by
    mutating `DB_DATABASE` on the shared `pgsql` connection.
  * `phpunit.xml` sets `APP_ENV=testing`, `DB_CONNECTION=testing`,
    `DB_TEST_DATABASE=ext_app_test` and `DB_DATABASE=ext_app_test` all with
    `force="true"`, so container-level env vars cannot shadow them.
  * `.env.testing` pins the same test DB for `--env=testing` artisan runs.
  * `tests/TestCase.php::createApplication()` throws if the **resolved**
    default connection is not `testing` or its database is not
    `ext_app_test`. This runs before `RefreshDatabase`, so a stale config
    cache (which would bake in `ext_app`) aborts the suite instead of wiping
    data.
  * `tests/Pest.php` adds a `beforeEach` asserting
    `DB::connection()->getName() === 'testing'` as a second guardrail.
* **Parallel workers self-clean — but only because the scripts export
  `LARAVEL_PARALLEL_TESTING=1` and `LARAVEL_PARALLEL_TESTING_DROP_DATABASES=1`
  as real environment variables.** Pest's `--drop-databases` flag alone is
  NOT enough: Pest's Laravel handler copies the flag into `$_ENV`, but
  Laravel's `ParallelTesting` reads the options from `$_SERVER`
  (`ParallelTesting::option()` / `inParallel()`), and PHP never merges `$_ENV`
  into `$_SERVER`. Result: worker databases `ext_app_test_test_{N}` are
  created and used on every parallel run, but silently never dropped — this
  is what caused the orphaned-DB accumulation cleaned up on 2026-08-03, and
  `test:tia` leaked them for months despite passing the flag. Exporting the
  two variables (in the composer `test` and `test:tia` scripts, prefixed
  inside `sh -c 'VAR=1 …'` the same way as the TIA ini scan-dir) makes them
  visible via `variables_order=EGPCS` in every process — the parent runner
  then drops all worker DBs in its `finally` teardown, verified
  2026-10-03 (899 tests, 0 leftovers). First run after a drop pays worker-DB
  creation (~20s); every run recreates them since they are dropped at the
  end.
* **Cross-file helpers live in `tests/Pest.php`:** parallel workers each
  load only a subset of test files, so a helper defined (even under
  `function_exists`) in one test file but called from another works in
  serial runs and fails with "Call to undefined function" under `--parallel`
  when the two files land in different workers. If more than one file uses
  a helper, define it in `tests/Pest.php`.

# Frontend (vitest, pure JS logic only)

* `docker exec ext_app_laravel npm run test` (= `vitest run`) executes
  `resources/js/**/*.test.{js,mjs}` in Node. The suite pins pure `lib/`
  modules (SSE parsing, payload mapping, clamps, position store, error
  chains, and the anki JS trio — wire-shape client, template generator, sync
  orchestration, exercised with `vi.stubGlobal('fetch', …)` since the
  browser-side AnkiConnect client is pure request/response logic) plus the
  blessed Components-side source pins, which read popup JSX as text
  (ADR 0066's exception to the lib/-only rule). Every source pin obeys one
  split — **a pin over DERIVED logic lifts the real expression out of the
  component source and evaluates it against payload states; a
  verbatim-contains pin is allowed only for wiring/structure**
  (pass-throughs, class hooks, presence/absence). The split's two
  exemplars, blessed by name:
  - `Components/WordPopup.ankiSend.test.js` — the contains-only pin. Reads
    the popup's `sendToAnki` JSX as text and pins the send-path's two
    pass-throughs — the `labels` (the note must be built with
    `uiLabels(t)`, so cards carry the sender's UI language — ADR 0077
    addendum point E) and, since the Route A body toggles went send-time,
    the resolved `fields` map (the body toggles plus the `body` master must
    shape the Body value at send time). Pass-through wiring:
    byte-for-byte is exactly right here.
  - `Components/WordPopup.sourceBadge.test.js` — the lift-and-evaluate
    pin. Regex-lifts the header badge's real `sourceBadge` derivation out
    of `WordPopup.jsx` and evaluates it via `new Function` against the
    `{source, ai_asked}` payload states. Rationale: the "ai · ai" badge
    bug passed its own original pin — that pin asserted the buggy
    expression byte-for-byte, so red-green was impossible for that logic
    error and only the review pass caught it (fixed 2026-10-20, `cab5280`).
    A pin that asserts what an expression RETURNS never matches its text.
  **No jsdom, no React/component tests**: pages and hooks are wiring,
  covered by Pest and manual verification (ADR 0066); source pins assert
  the component's text, they never render it.
* `vitest.config.js` is standalone and deliberately does NOT load the
  laravel-vite plugin or the app's `vite.config.js` — keep it that way, or
  the suite becomes a build.
* CI runs it as the **`frontend`** job in `tests.yml` (`npm ci` + `npm run
  test`; Node with npm cache off `package-lock.json` — the lockfile is
  load-bearing). `composer run test` deliberately does NOT chain it: Pest
  runs without Vite/npm and must stay PHP-only.
* Test files are colocated next to the module they pin
  (`resources/js/lib/*.test.*`); the standing exception is the blessed
  Components-side source pins above, which pin assertions inside the
  component files they read. When extracting logic from a page, put the
  pure part in `lib/` (`.mjs` if it must stay framework-free) so it has a
  home to test in.
* A module with its own memo cache (e.g. `lib/ankiSync.js` memoizes ensures
  in a module-level Set) is tested with static single-generation imports and
  a unique memo key per test, NOT `vi.resetModules()` + dynamic import:
  re-evaluating the module chain per test mints fresh error classes, so
  `instanceof` against the test file's static imports fails, and a per-test
  port/config counter that resets re-mints keys an earlier test already
  memoized (ensures silently become memo hits). Reuse one config object
  within a test to exercise the shared-session memo; mint a fresh one for a
  cold session.

# Frontend static analysis (ESLint, correctness only)

* `docker exec ext_app_laravel npm run lint` (= `eslint resources/js`)
  statically checks every `resources/js/**/*.{js,mjs,jsx}` for **correctness,
  not style**: `no-undef` (the guard this pass exists for — a missing import
  once sailed through `vite build`; only a manual Node smoke caught it),
  the core recommended set, and `eslint-plugin-react-hooks` (rules-of-hooks
  + the v7 compiler-backed set). Errors fail the run; warnings don't.
* `laravel/eslint.config.js` is flat config (eslint 10). Rule tiers are
  deliberate and commented in-file: genuine-violation rules stay `error`;
  legacy-but-working patterns the v7 hook analyzer flags
  (`refs`, `set-state-in-effect`, `immutability`,
  `preserve-manual-memoization`) sit at `warn`; two deliberate patterns have
  file-scoped carve-offs (`no-control-regex` in `lib/markdown.js` — the
  \u0000/\u0001 sentinels ARE the mechanism; `react-hooks/globals` in
  `i18n.jsx` — the module-level strings cache IS the t() mechanism). A new
  warning is review signal, not a reformat mandate: fix real bugs in place,
  never mass-edit to silence a rule.
* CI runs it as the **`lint`** job in `tests.yml` (`npm ci` + `npm run
  lint`, Node with npm cache off `package-lock.json`), mirroring the
  `frontend` job.

# Commands

```bash
# Full suite, parallel (config+route clear first; ~1.5-2min for 899 tests
# vs ~5min serial before 2026-10-03)
docker exec ext_app_laravel composer run test

# One file
docker exec ext_app_laravel php artisan test tests/Feature/ExampleTest.php

# By name (recommended after a change)
docker exec ext_app_laravel php artisan test --filter=testName

# One suite
docker exec ext_app_laravel php artisan test --testsuite=Unit
docker exec ext_app_laravel php artisan test --testsuite=Feature

# TIA — only tests affected by your changes (Pest 5 Tia Engine)
# First run records the baseline graph under Xdebug coverage (~50s);
# subsequent runs replay cached results and re-run only tests touched
# by changed files. Comment-only/whitespace edits trigger zero tests.
docker exec ext_app_laravel composer run test:tia

# Force re-record the TIA graph after large refactors (~80s under PCOV;
# also required once after a container rebuild, when the graph is gone and
# `gh` cannot fetch the CI baseline unauthenticated)
docker exec ext_app_laravel sh -c 'cd /var/www && PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d:/var/www/scripts/php-ini php vendor/bin/pest --parallel --tia --drop-databases --coverage --fresh'

# Frontend: pure-logic JS tests (vitest, Node only — no DB, no build)
docker exec ext_app_laravel npm run test

# Frontend static analysis: ESLint over resources/js (correctness only —
# errors fail, warnings don't)
docker exec ext_app_laravel npm run lint

# Python service (pytest, inside the ext_python container; ~5s, no weights)
docker exec ext_python python -m pytest /app/ai -q
```

# Python service (pytest)

The python microservice has its own pytest suite (`ai/pytest.ini`,
`ai/conftest.py`; pytest is in `docker-compose/python/requirements.txt`):

* Run the whole suite with the command above, or one file with
  `docker exec ext_python python -m pytest /app/ai/alignment/test_aligner.py -q`.
  `-k name` filters. **Manual only for now** — no CI job.
* **No model weights needed**: the aligner/embedding tests drive the pipeline
  through a deterministic `StubModel` (`ai/conftest.py`) with hand-picked
  vectors, and the API tests plant stubs on `app.state` the way `ModelCache`
  caches real models. The suite runs in ~5s.
* An autouse fixture clears the aligner's module-level `_EMBEDDING_CACHE`
  around every test (its keys include `id(model)`, so a freed stub's recycled
  id would leak vectors between tests) and pins `CACHE_MODELS=1` (that key is
  read from `os.environ`, not the env file). The spaCy singleton
  (`phrasal._NLP`) is deliberately NOT reset — reloading `en_core_web_md`
  per test would dominate the runtime.
* config's live accessors read the bind-mounted env **file** first
  (`os.environ` only as fallback), so tests that steer `SPLITTER_ENGINE` etc.
  use the `env_file` fixture, which points `config.ENV_FILE` at a tmp file.
* Optional dependencies skip cleanly (`silero`/`spacy` markers,
  `pytest.importorskip("fastapi")` in the api tests): a partial host-venv run
  (`docker-compose/python/ai/venv/bin/python -m pytest docker-compose/python/ai`)
  works but lacks fastapi/pydantic/razdel/spacy — run the suite in the
  container for full coverage.
* The splitter regression fixtures are committed synthetic texts
  (`ai/splitting/fixtures/*.txt`, exempted from the `*.txt` gitignore rule).
* Laravel-side tests never call this service: they bind `fakePython()`
  (`tests/Pest.php`) or `Http::fake()` the wire — see "Fake every HTTP
  boundary" below.

# Gotchas

* **Never run tests with a cached config.** If `php artisan config:cache` was
  run in the container, `DB_CONNECTION`/`DB_DATABASE` resolve to the dev
  values and the `TestCase` guard will abort every test with a
  `RuntimeException` — fix with `php artisan config:clear`.
* **Never run tests with a cached route table.** `deploy.sh` runs
  `php artisan route:cache` during deploy, baking the prod `APP_KEY` into
  `bootstrap/cache/routes-v7.php`. Livewire's `/livewire-<hash>/update`
  endpoint is derived from `APP_KEY`, so a baked route cache under a different
  key returns 404 for every Livewire/Filament interaction (surfacing as
  "Attempt to read property 'mountedActions' on null"). The `test` and
  `test:tia` composer scripts now run `route:clear`; if a suite starts failing
  with null `instance()` errors, run `php artisan route:clear` first.
* **Never run destructive artisan commands against the default `pgsql`
  connection.** It points at the dev `ext_app` database. For a destructive
  reset of the test DB use `composer run test` / `composer run test:tia`, or
  pin the testing connection explicitly:
  `DB_CONNECTION=testing php artisan migrate:fresh`.
* **Never start two test runs concurrently** — parallel workers name their
  databases `ext_app_test_test_{N}` per process slot, so two runs collide on
  the same worker databases and corrupt each other's `RefreshDatabase` state.
  Run suites sequentially. (`--filter`/single-file runs also spin up 8
  workers by default; pass `--processes=2` to a small run if you need the
  machine for something else.)
* If a migration was just added, the test DB needs it too; Laravel's test
  runner migrates when tests use the `RefreshDatabase` trait — check sibling
  tests for the convention.
* New tests are created with `php artisan make:test --pest <Name>`
  (`--unit` for unit tests).
* The wiki bundle itself is covered by `tests/Feature/WikiTest.php` — OKF
  conformance failures show up in the normal suite.
* Page-render tests depend on `Tests\TestCase::setUp()` calling
  `$this->withoutVite()` — without it, any suite run on a machine with no
  `public/build/manifest.json` (fresh clone, CI) fails ~40 feature tests
  with `ViteManifestNotFoundException`.

# Keeping tests fast

`RefreshDatabase` re-migrates per test, so every Feature test pays a fixed
floor; what makes runs crawl is test-data volume and leaked network calls.
Rules for any new or modified test:

* **Bound the fixture.** Seed only the rows the assertions need — a handful
  per side. Go bigger only when a scale boundary itself is the subject (e.g.
  chunk-size seams), and then the smallest size that crosses it. Inline
  `range()` seeding is the norm (factories barely exist); keep the counts
  small.
* **Fake every HTTP boundary.** Tests run with `QUEUE_CONNECTION=sync`, so
  code executing *before* a dispatch runs inline: a test with only
  `Bus::fake()` still makes real network calls if any pre-dispatch code
  touches the Python service — and `SentenceAlignmentService` timeouts reach
  600s with retries, i.e. minutes per leaked call. Python calls go through
  the `PythonClient` seam (ADR 0061), so bind the in-memory adapter instead
  of faking the wire: `fakePython()` (tests/Pest.php) binds
  `Tests\Support\FakePythonClient` for the current test; give it canned
  responses (`->aligning([...])`, `->aligningDiagonal()`, `->embedding([...])`,
  `->enrichingNothing()`) and read the recorded payloads
  (`$fake->alignPayloads`, `enrichPayloads`, `embedPayloads`). Unconfigured
  endpoints throw `PythonClientException` — the same failure shape a 5xx
  maps to in production — so an unexpected call fails loudly. Wire-level
  `Http::fake()` lives only in `PythonClientTest` (the adapter's own tests);
  a file-level `beforeEach(fn () => fakePython())` suffices when tests only
  assert dispatches (see `FilamentReAlignActionTest.php`). Since 2026-10-14
  the guard is suite-wide: `tests/Pest.php` arms
  `Http::preventStrayRequests()` for every Feature test, so a leaked wire
  call throws immediately instead of hanging (Google/Yandex keys are
  force-blanked in `phpunit.xml`; opt in with
  `enableTranslationProviders()` + `Http::fake()`).
* **Cap drain loops.** A `while` loop that re-runs a job until the match
  leaves `aligning` needs a small guard (`$guard < 10`) *and* a convergence
  assertion after the loop (`status === 'completed'`) so a stalled job fails
  the test instead of silently burning iterations.
* **Bound pagination in tests.** Pass `per_page`/`page` on paginated
  endpoints and assert the returned `meta`; never write a test that assumes
  an endpoint returns everything.
* **Run TIA first.** After changes prefer `composer run test:tia` — it
  re-runs only tests touched by changed files; the full `composer run test`
  belongs to pre-merge/CI verification. Never run two test commands at once
  (shared `ext_app_test`).

# Pest TIA Engine

[Pest 5 TIA](https://pestphp.com/docs/tia) (Test Impact Analysis) re-runs only
the tests affected by your latest changes, replaying the rest from cache.

* **Config**: `tests/Pest.php` calls `pest()->tia()->filtered()->baselined()`.
  `filtered()` narrows PHPUnit to affected test files; `baselined()` opts in
  to fetching a shared baseline from CI when the local graph drifts.
* **Coverage driver**: PCOV is installed but **disabled by default** (fast
  normal CLI; no `pcov.ini` in the container's conf.d). The `test:tia`
  composer script loads it via
  `PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d:/var/www/scripts/php-ini`
  pointing at the tracked `scripts/php-ini/tia.ini` (pcov extension +
  `pcov.enabled=1` + `memory_limit=1G`). The scan-dir route is required
  because Pest runs parallel through the **paratest** binary: its worker
  processes do not inherit `php -d` ini flags from the parent invocation —
  only environment-propagated ini scanning reaches every worker. Plain `-d
  extension=pcov.so` fails with "No code coverage driver is available", and
  the graph merge OOMs at the CLI-default 128M limit without the memory
  line.
* **Baseline source**: `baselined()` first tries to fetch the shared CI
  baseline through `gh`; with an unauthenticated `gh` in the container it
  errors — record locally with `--fresh` instead (once per container; ~80s).
* **Container-local git repo**: because `/var/www` (Laravel project) is bind-
  mounted separately from `/var/repo` (the git repo root), Pest sees no git
  context at `/var/www`. `scripts/tia-setup.php` initialises a container-local
  git repo at `/var/www` with a single baseline commit, copying the remote URL
  from `/var/repo` so the project key matches across team members. The commit
  is created once and is left untouched afterwards — user edits stay
  uncommitted so TIA can detect them. Repo presence is detected via
  `.git/HEAD` (not directory existence — see the volume mask below).
  **Host-side isolation (2026-09-20)**: the compose `app` service mounts the
  named volume `tia-git` at `/var/www/.git`, so the repo lives only in the
  container volume and never materialises in the `./laravel` bind mount (it
  used to appear on the host as `laravel/.git` — a phantom second repo on
  branch `master` in git GUIs; it kept returning after every deletion because
  `test:tia` re-created it). A `post_start` hook chowns the volume mountpoint
  to `alex` (fresh docker volumes are root-owned; git runs as `alex`). The
  host may still show an **empty** root-owned `laravel/.git` directory —
  Docker Desktop's mountpoint artifact, inert: git inside `laravel/` resolves
  to the outer repo. A *populated* `laravel/.git` means the volume mask was
  reverted — delete it (nothing unique lives in it; `test:tia` re-creates the
  repo in the volume). Regardless: always run git from the repo root, never
  `push`/`pull`/`clean`/`stash` from inside `laravel/` — the container-side
  repo's `origin` points at the real GitHub remote. Removing the volume only
  resets the TIA baseline → one `--fresh` graph re-record on the next run.
* **Storage**: `~/.pest/tia/<project-key>/` inside the container. Lost on
  `docker compose down` (container removal); `scripts/tia-setup.php` re-creates
  the git context (the `tia-git` volume with the baseline commit itself
  survives `down` — only `down -v` / volume deletion wipes it) and
  `--tia --fresh` re-records the graph. CI baseline sharing
  (`.github/workflows/tia-baseline.yml`) lets new checkouts download a
  pre-recorded baseline instead of paying the local recording cost.
