---
type: Playbook
title: Running Tests
description: How to run the Pest test suite against the dedicated ext_app_test database.
tags: [testing, pest]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
sources:
  - id: phpunit
    resource: laravel/phpunit.xml
    title: PHPUnit configuration (test DB name)
  - id: composer
    resource: laravel/composer.json
    title: Composer scripts (test script)
---

# Facts

* Framework: **Pest 4** on PHPUnit 12.
* Test database: **`ext_app_test`** (set in `phpunit.xml`, not the default
  `testing` DB). Tests use `QUEUE_CONNECTION=sync`, array cache/session.
* `composer run test` clears the config cache first, then runs the suite.

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

* If a migration was just added, the test DB needs it too; Laravel's test
  runner migrates when tests use the `RefreshDatabase` trait — check sibling
  tests for the convention.
* New tests are created with `php artisan make:test --pest <Name>`
  (`--unit` for unit tests).
* The wiki bundle itself is covered by `tests/Feature/WikiTest.php` — OKF
  conformance failures show up in the normal suite.
