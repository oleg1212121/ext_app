---
type: Architecture
title: Application Overview
description: What ext_app is, its main components, and how a request flows through the system.
tags: [architecture, overview]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
sources:
  - id: composer
    resource: laravel/composer.json
    title: Composer manifest (actual package versions)
  - id: routes
    resource: laravel/routes/web.php
    title: HTTP routes
---

# What it is

`ext_app` is a bilingual (English/Russian) language learning application. Its
core loop: import real texts in both languages, align their sentences using
embeddings, and let learners read side-by-side, look up dictionary entries,
practice with crosswords, and get AI feedback on their own translations.

# Repository layout

The repo root is a **Docker workspace**; the Laravel application lives in
`laravel/`. Paths in this bundle are repo-root relative.

| Path | Purpose |
|------|---------|
| `laravel/` | The Laravel 13 application (mounted to `/var/www` in the app container) |
| `wiki/` | This OKF knowledge bundle (mounted to `/var/wiki`) |
| `docker-compose/embedding/` | FastAPI sentence-embedding microservice |
| `docker-compose/python/ai/` | Experimental Python scripts + prebuilt `ai_env` venv |
| `docker-compose/postgres/` | PostgreSQL data directory |
| `nginx/` | Nginx config |
| `public/texts/simulator/` | (inside `laravel/public`) reading texts by language/level |

# Main components

* **Laravel app** — monolith serving three UI stacks simultaneously; see
  [Frontend](/architecture/frontend.md).
* **Filament 5 admin** at `/admin` — CRUD for dictionaries, entities, and the
  manual alignment editor ([Entities & Alignment](/database/entities-alignment.md)).
* **Queue workers** — the [alignment pipeline](/domains/sentence-alignment.md)
  runs as queued jobs (`AlignEntitySentences`, `AlignEntitySentenceChunk`,
  `GenerateEntitySignature`, `ProcessEntityFile`, `SplitEntityFileSentences`).
* **Embedding microservice** (`ext_embedding`) — sentence-transformers
  (`intfloat/multilingual-e5-small`) over HTTP; used by alignment and text
  signatures. Config: `config/services.php` → `embedding`.
* **External AI APIs** — six providers behind one abstraction; see
  [AI Providers](/domains/ai-providers.md).

# Request flow (typical authenticated page)

1. Nginx (`ext_nginx`, host port 8000) → PHP-FPM in `ext_app_laravel`.
2. `routes/web.php` — everything except `/` is behind the `auth` middleware
   (Laravel Breeze stack, see `routes/auth.php`).
3. Controllers return **Inertia responses** (`Inertia::render(...)`) rendering
   React pages from `resources/js/Pages/`, or Blade views for the two legacy
   Livewire components.
4. POST endpoints under the same `auth` group serve the interactive features
   (AI questions, text pagination, crossword generation, dictionary saving).

# Version truth

The `laravel/CLAUDE.md` Boost guidelines list outdated versions (Laravel 12,
Filament 3, Livewire 3, Pest 3, Tailwind 3). **Trust `composer.json` /
`composer.lock` and `package.json` over prose.** Actual: PHP ^8.3 (8.4
runtime), Laravel ^13.6, Livewire ^4, Filament ^5, Pest ^4, PHPUnit ^12,
Tailwind ^4, React ^19, Inertia ^3.
