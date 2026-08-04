---
type: Playbook
title: Running Tests
description: How to run the Pest test suite against the dedicated ext_app_test database.
tags: [testing, pest]
status: stable
generated: { by: human:opencode, at: 2026-08-04T19:50:00Z }
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
* `composer run test` clears the config cache first, then runs the suite.
* **Safety layers against wiping the real `ext_app` database:**
  * `phpunit.xml` sets `APP_ENV=testing` and `DB_DATABASE=ext_app_test` with
    `force="true"`, so container-level env vars cannot shadow them.
  * `.env.testing` pins the same test DB for `--env=testing` artisan runs.
  * `tests/TestCase.php::createApplication()` throws if the **resolved**
    `pgsql` database is not `ext_app_test`. This runs before
    `RefreshDatabase`, so a stale config cache (which would bake in
    `ext_app`) aborts the suite instead of wiping data.

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
  run in the container, `DB_DATABASE` resolves to `ext_app` and the
  `TestCase` guard will abort every test with a `RuntimeException` — fix with
  `php artisan config:clear`.
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
* **Coverage driver**: Xdebug is installed but `xdebug.mode = off` by default
  (fast normal CLI). The `test:tia` composer script sets `XDEBUG_MODE=coverage`
  so all parallel workers inherit coverage mode for the baseline run only.
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
* **5 pre-existing test failures** (`OpenRouterTest`, `WiktionaryParserTest`,
  `ImportSimulatorEntitiesCommandTest`) are always re-run by TIA because failed
  tests cannot be cached/replayed. Fixing them will shrink replay runs further.
