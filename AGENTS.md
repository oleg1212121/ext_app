# AGENTS.md - Agent Coding Guidelines

This document provides guidelines for agents operating in this Laravel bilingual language learning application.

## Knowledge base (wiki/)

`wiki/` is an [OKF v0.2](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md) knowledge bundle — markdown concepts with YAML frontmatter, cross-linked into a graph. It holds the detailed context this file intentionally omits: architecture, feature domains, database schema, playbooks, and auto-generated references.

**Before non-trivial work:** open `wiki/index.md`, then read only the concepts relevant to your task and follow their links (progressive disclosure). Trust signals are in each concept's frontmatter — prefer `verified: human:*` content and heed `stale_after`.

**Maintenance rules (required):**
- When you change a feature, flow, or schema, update the matching concept in `wiki/`, bump its `generated.at`, and add an entry to `wiki/log.md`.
- When you add/change routes, models, or artisan commands, regenerate the machine-owned references: `docker exec ext_app_laravel php artisan wiki:sync` (never hand-edit `wiki/reference/`).
- `docker exec ext_app_laravel php artisan wiki:validate` must pass (it also runs as part of the test suite via `tests/Feature/WikiTest.php`).
- After reviewing a concept for accuracy, record it: add `verified: { by: human:<you>, at: <datetime> }` to its frontmatter.

## Docker Environment

All Laravel/PHP/Composer/NPM commands must run inside the `ext_app_laravel` container.

```bash
docker exec ext_app_laravel [command]
```

**Never run PHP/Composer/NPM commands on the host.**

## Actual Package Versions (verified from composer.lock)

| Package | Version |
|---------|---------|
| PHP | ^8.3 (8.4 runtime) |
| Laravel | 13.x |
| Livewire | 4.x |
| Filament | 5.x |
| Pest | 5.x |
| PHPUnit | 13.x |
| Tailwind CSS | 4.x |
| Alpine.js | 3.x |
| Inertia.js + React | 3.x / 19.x |

The CLAUDE.md Boost guidelines reference older versions (Laravel 12, Livewire 3, Filament 3, Pest 3). Trust composer.json/composer.lock over prose.

## Commands

### Development
```bash
# Start all services (server, queue, logs, vite)
docker exec ext_app_laravel composer run dev

# Build frontend assets
docker exec ext_app_laravel npm run build

# Watch frontend assets
docker exec ext_app_laravel npm run dev
```

### Code Formatting (Required before committing)
```bash
docker exec ext_app_laravel vendor/bin/pint --dirty
```

### Testing
```bash
# Run all tests (clears config cache first)
docker exec ext_app_laravel composer run test

# Run specific test file
docker exec ext_app_laravel php artisan test tests/Feature/ExampleTest.php

# Filter by test name (recommended after changes)
docker exec ext_app_laravel php artisan test --filter=testName

# Run unit tests only
docker exec ext_app_laravel php artisan test --testsuite=Unit

# Run feature tests only
docker exec ext_app_laravel php artisan test --testsuite=Feature

# Run only tests affected by your changes (Pest TIA — uses Xdebug coverage for the baseline)
# First run records the baseline (~50s); subsequent runs replay cached results and re-run
# only tests touched by changed files. Comment-only edits trigger zero tests.
docker exec ext_app_laravel composer run test:tia

# Force re-record the TIA graph after large refactors (slower run under Xdebug)
docker exec -e XDEBUG_MODE=coverage ext_app_laravel sh -c 'cd /var/www && php scripts/tia-setup.php && php vendor/bin/pest --parallel --tia --fresh'
```

Test database: `ext_app_test` (configured in phpunit.xml, not the default).

### Database

> **⚠️ DESTRUCTIVE COMMAND WARNING**
> The main dev database is `ext_app`, on the default `pgsql` connection.
> **NEVER run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, or `db:wipe` against the default `pgsql` connection** — it points at `ext_app`, not the test DB. This was the cause of a real dev-database wipe (see `wiki/log.md`).
>
> - Tests use a **dedicated `testing` connection** (defined in `config/database.php`, pinned in `phpunit.xml` via `DB_CONNECTION=testing`) that points at `ext_app_test`. `tests/Pest.php` asserts `DB::connection()->getName() === 'testing'` before every Feature test, so a test run on the wrong connection fails before any destructive migration runs.
> - For destructive ops on the test DB, go through the test runner: `composer run test` / `composer run test:tia`, or `php artisan migrate:fresh` with `DB_CONNECTION=testing` explicitly set. Verify the resolved database name is `ext_app_test` (not `ext_app`) before pressing enter.
> - **Never start two test runs concurrently** — they share `ext_app_test` and corrupt each other's `RefreshDatabase` state. Run suites sequentially.
> - `composer run test:tia` runs Pest in parallel; the `--drop-databases` flag makes each parallel worker drop its temporary `ext_app_test_test_{N}` database after the run, so orphaned test DBs must not accumulate.

```bash
docker exec ext_app_laravel php artisan migrate
# SAFE destructive reset ONLY when pinned to the testing connection:
docker exec ext_app_laravel sh -c 'DB_CONNECTION=testing php artisan migrate:fresh --seed'
```

PostgreSQL is exposed on host port `54321`.

## Architecture

### Frontend: Hybrid Inertia/React + Livewire

The app uses **two frontend approaches simultaneously**:

- **Inertia/React (JSX)** — Primary UI. Pages in `resources/js/Pages/`. Uses `@inertiajs/react`, `flowbite-react`, React 19.
- **Livewire** — Used for `Crossword` and `WordsSearch` components in `app/Livewire/`. Blade views in `resources/views/livewire/`.
- **Alpine.js** — Loaded globally in `resources/js/app.jsx` for lightweight interactivity.

When adding new pages, prefer Inertia/React (JSX). Livewire is legacy for crossword/simulator.

### Tailwind CSS 4

Tailwind 4 uses CSS-based config via `@theme`/`@source`/`@plugin` directives in `resources/css/app.css`. The `tailwind.config.js` is intentionally minimal. Do not add JS-based Tailwind config.

### Key Directories

- `wiki/` — OKF knowledge bundle (agent context: architecture, domains, schema, playbooks)
- `app/Classes/AiProvider.php` — Base class for AI providers (Gemini, Groq, OpenRouter, Cohere, Perplexity, HuggingFace)
- `app/Filament/Resources/` — Filament admin CRUD resources
- `app/Http/Controllers/Bilinguals/` — Bilinguals simulator controllers
- `resources/js/Pages/` — Inertia React pages
- `public/texts/simulator/` — Reading materials organized by language/level
- `docker-compose/python/` — FastAPI python service (container `ext_python`): sentence splitting, BGE-M3 embeddings, DP alignment

### Services (Docker)

| Service | Container | Port |
|---------|-----------|------|
| Laravel app | ext_app_laravel | — |
| PostgreSQL | ext_pgdb | 54321 |
| Nginx | ext_nginx | 8000 |
| Vite dev | (in ext_app_laravel) | 8002 |
| Python API | ext_python | 8001 |

### Python Environment

Pre-configured venv at `docker-compose/python/ai/ai_env/` with torch, sentence-transformers, transformers, numpy, scipy, pysbd pre-installed. Do NOT reinstall these packages.

```bash
docker-compose/python/ai/ai_env/bin/python <script>
docker-compose/python/ai/ai_env/bin/pip install <package>  # only when needed
```

## Conventions

- **Laravel 12+ structure**: No `app/Http/Middleware/` directory — register in `bootstrap/app.php`. Commands auto-register from `app/Console/Commands/`.
- **Validation**: Use Form Request classes in `app/Http/Requests/`, never inline validation.
- **Config**: Use `config()`, never `env()` outside config files.
- **Testing**: Pest syntax. Use `assertForbidden`/`assertNotFound` instead of `assertStatus(4xx)`.
- **Livewire events**: Use `$this->dispatch()`, not `emit`.
- **Models**: Use `casts()` method, not `$casts` property.
- **Eloquent**: Prefer `Model::query()` over `DB::`. Use eager loading.

## MCP (Laravel Boost)

Laravel Boost MCP is configured via `laravel/.mcp.json`. Available tools: `search-docs`, `tinker`, `database-query`, `browser-logs`, `list-artisan-commands`, `get-absolute-url`.
