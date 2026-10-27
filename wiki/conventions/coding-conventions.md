---
type: Convention
title: Coding Conventions
description: Project-wide rules for code style, structure, testing, and agent behavior in this repository.
tags: [conventions, style, testing]
status: stable
generated: { by: agent:zcode, at: 2026-10-25T13:10:00+03:00 }
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

* Middleware classes live in `app/Http/Middleware/` but there is **no
  `Http/Kernel.php`** — they are registered/aliased in `bootstrap/app.php`.
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
* Livewire events (`$this->dispatch()`, never `emit`) now apply only inside
  Filament — no app-level Livewire components exist.

# Testing

* Pest syntax only; tests in `tests/Feature` / `tests/Unit`.
* Pure-JS logic in `resources/js/lib/` is tested with vitest
  (`docker exec ext_app_laravel npm run test`); new lib modules get vitest
  tests beside the module, same as Pest for PHP.
* Use specific assertions: `assertForbidden()`, `assertNotFound()`,
  `assertSuccessful()` — not `assertStatus(403)`.
* Test database is `ext_app_test` (from `phpunit.xml`) — never the dev DB.
* Run the minimal set: `php artisan test --filter=testName` after a change.
* Keep tests fast: minimal fixtures (a handful of rows per side), `Http::fake()`
  on any path that can reach the Python service or an external API (sync queue
  runs pre-dispatch code inline; Python timeouts reach 600s), guarded drain
  loops with convergence assertions, and `per_page` passed/asserted on
  paginated endpoints. Details: [Running Tests](/playbooks/running-tests.md).
* Per-ticket verification is `composer run test:tia` plus a targeted
  `php artisan test` on the files being worked — never the full suite per
  ticket. The full `composer run test` runs once per spec, after all its
  tickets resolve and before review dispatch; a later fix pass verifies
  with `test:tia` plus the touched test files; CI on push stays the final
  full gate.
* The wiki gate: `php artisan wiki:validate` must pass with zero
  unacknowledged warnings — fix a new warning by bumping the listing
  concept's `generated.at`, or acknowledge known-benign drift in
  `wiki/validate-baseline.json` with an owner+dated entry; `--prune`
  drops baseline entries that no longer match. Details:
  [Running Tests](/playbooks/running-tests.md).
* Every change must be covered by a test that runs green; see
  [Running Tests](/playbooks/running-tests.md).

# Documentation freshness trap

Older AI-authored guideline files (a `laravel/CLAUDE.md` from the Boost era,
`.github/copilot-instructions.md` — neither exists in the tree anymore) listed
**outdated** package versions (Laravel 12, Filament 3, Livewire 3, Pest 3,
Tailwind 3). Trust `composer.lock`/`package.json` and
[Application Overview](/architecture/overview.md#version-truth) instead.
