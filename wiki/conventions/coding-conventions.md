---
type: Convention
title: Coding Conventions
description: Project-wide rules for code style, structure, testing, and agent behavior in this repository.
tags: [conventions, style, testing]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-27T17:30:00Z }
verified: { by: human:alex, at: 2026-07-26T18:45:00Z }
sources:
  - id: agents
    resource: AGENTS.md
    title: Agent guidelines (canonical conventions)
    author: human:alex
---

# Commands & environment

* All PHP/Composer/NPM commands run via `docker exec ext_app_laravel ...` —
  never on the host. See [Docker & Services](/architecture/docker-services.md).
* Run `docker exec ext_app_laravel vendor/bin/pint --dirty` before committing.

# Laravel 12+ structure

* No `app/Http/Middleware/` — register middleware in `bootstrap/app.php`.
* No `app/Console/Kernel.php` — commands in `app/Console/Commands/`
  **auto-register**; scheduling lives in `routes/console.php`.
* Use `php artisan make:*` generators (with `--no-interaction`) for new files.

# Validation & config

* Validation always via Form Request classes in `app/Http/Requests/` — never
  inline `$request->validate()` in controllers.
* `config()` everywhere; `env()` only inside `config/*.php` files.

# Models & database

* Casts in a `casts()` method, not the `$casts` property.
* Prefer `Model::query()` over `DB::`; use eager loading (`with(...)`) to avoid
  N+1 queries.
* Eloquent relationship methods with return type hints.
* Create factories/seeders alongside new models.

# Frontend

* New pages: Inertia/React JSX in `resources/js/Pages/` — not Livewire, not
  Blade. See [Frontend](/architecture/frontend.md).
* Tailwind 4: CSS-first config only (`resources/css/app.css`); gap utilities
  for spacing; support `dark:` where existing pages do.
* Livewire events: `$this->dispatch()`, never `emit`.

# Testing

* Pest syntax only; tests in `tests/Feature` / `tests/Unit`.
* Use specific assertions: `assertForbidden()`, `assertNotFound()`,
  `assertSuccessful()` — not `assertStatus(403)`.
* Test database is `ext_app_test` (from `phpunit.xml`) — never the dev DB.
* Run the minimal set: `php artisan test --filter=testName` after a change.
* Every change must be covered by a test that runs green; see
  [Running Tests](/playbooks/running-tests.md).

# Documentation freshness trap

`laravel/CLAUDE.md` (Boost guidelines) and `.github/copilot-instructions.md`
list **outdated** package versions (Laravel 12, Filament 3, Livewire 3, Pest 3,
Tailwind 3). Trust `composer.lock`/`package.json` and
[Application Overview](/architecture/overview.md#version-truth) instead.
