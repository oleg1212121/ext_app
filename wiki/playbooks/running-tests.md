---
type: Playbook
title: Running Tests
description: How to run the Pest test suite against the dedicated ext_app_test database.
tags: [testing, pest]
status: stable
generated: { by: human:opencode, at: 2026-09-03T20:30:00Z }
sources:
  - id: phpunit
    resource: laravel/phpunit.xml
    title: PHPUnit configuration (test DB name, forced env)
  - id: composer
    resource: laravel/composer.json
    title: Composer scripts (test script)
  - id: testcase
    resource: laravel/tests/TestCase.php
    title: Base test case (test-database guard)
  - id: envtesting
    resource: laravel/.env.testing
    title: Testing environment file
---

# Facts

* Framework: **Pest 5** on PHPUnit 13 (bumped from Pest 4 / PHPUnit 12 to
  enable the TIA engine).
* Test database: **`ext_app_test`** (set in `phpunit.xml` and `.env.testing`,
  not the default `testing` DB). Tests use `QUEUE_CONNECTION=sync`, array
  cache/session.
* **CI needs a `.env` file.** Laravel boots off `.env` via phpdotenv's
  `@file_get_contents('.env')`; with no env file at all, PHPUnit flags a
  warning on roughly every test and (under the CI runtime) ~45 of them
  escalate to failures. `.env` is gitignored, so the Tests workflow
  (`tests.yml`) provisions it with `cp .env.example .env` before running —
  `.env.example` is secret-free, and `phpunit.xml` (force) plus the workflow
  `env:` (APP_KEY + DB connection) supply the real test values.
* `composer run test` clears the **config and route caches** first, then runs
  the suite. Routing the test run through `route:clear` is required because
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
* **Parallel workers self-clean:** `composer run test:tia` passes
  `--drop-databases` to Pest, so each parallel worker drops its temporary
  `ext_app_test_test_{N}` database after the run and orphaned test DBs must
  not accumulate.

# Commands

```bash
# Full suite (config:clear first)
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

# Force re-record the TIA graph after large refactors
docker exec -e XDEBUG_MODE=coverage ext_app_laravel sh -c \
  'cd /var/www && php scripts/tia-setup.php && php vendor/bin/pest --parallel --tia --fresh'
```

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
* **Never start two test runs concurrently** — they share `ext_app_test` and
  corrupt each other's `RefreshDatabase` state. Run suites sequentially.
* If a migration was just added, the test DB needs it too; Laravel's test
  runner migrates when tests use the `RefreshDatabase` trait — check sibling
  tests for the convention.
* New tests are created with `php artisan make:test --pest <Name>`
  (`--unit` for unit tests).
* The wiki bundle itself is covered by `tests/Feature/WikiTest.php` — OKF
  conformance failures show up in the normal suite.

# Pest TIA Engine

[Pest 5 TIA](https://pestphp.com/docs/tia) (Test Impact Analysis) re-runs only
the tests affected by your latest changes, replaying the rest from cache.

* **Config**: `tests/Pest.php` calls `pest()->tia()->filtered()->baselined()`.
  `filtered()` narrows PHPUnit to affected test files; `baselined()` opts in
  to fetching a shared baseline from CI when the local graph drifts.
* **Coverage driver**: PCOV is installed but **disabled by default** (fast
  normal CLI). The `test:tia` composer script enables it only for the baseline
  run via `-d extension=pcov.so -d pcov.enabled=1 --coverage`, so all parallel
  workers inherit coverage for the baseline recording only.
* **Container-local git repo**: because `/var/www` (Laravel project) is bind-
  mounted separately from `/var/repo` (the git repo root), Pest sees no git
  context at `/var/www`. `scripts/tia-setup.php` initialises a container-local
  git repo at `/var/www` with a single baseline commit, copying the remote URL
  from `/var/repo` so the project key matches across team members. The commit
  is created once (or after a container rebuild wipes `.git`) and is left
  untouched afterwards — user edits stay uncommitted so TIA can detect them.
* **Storage**: `~/.pest/tia/<project-key>/` inside the container. Lost on
  `docker compose down` (container removal); `scripts/tia-setup.php` recreates
  the git context and `--tia --fresh` re-records the graph. CI baseline sharing
  (`.github/workflows/tia-baseline.yml`) lets new checkouts download a
  pre-recorded baseline instead of paying the local recording cost.
