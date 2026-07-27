---
type: Playbook
title: Running Tests
description: How to run the Pest test suite against the dedicated ext_app_test database.
tags: [testing, pest]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-27T17:30:00Z }
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

* Framework: **Pest 4** on PHPUnit 12.
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
