---
type: Architecture
title: Application Overview
description: What ext_app is, its main components, and how a request flows through the system.
tags: [architecture, overview]
status: stable
generated: { by: agent:zcode, at: 2026-10-28T22:30:00+03:00 }
verified: { by: agent:zcode, at: 2026-10-14T19:20:00+03:00 }
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
practice reading translations of the same work, and get AI feedback on their own translations.

# Repository layout

The repo root is a **Docker workspace**; the Laravel application lives in
`laravel/`. Paths in this bundle are repo-root relative.

| Path | Purpose |
|------|---------|
| `laravel/` | The Laravel 13 application (mounted to `/var/www` in the app container) |
| `wiki/` | This OKF knowledge bundle (repo root bind-mounted at `/var/repo` in the app container, so `wiki/` is `/var/repo/wiki`) |
| `docker-compose/python/` | FastAPI python microservice (`ai/` package: splitting, embeddings, alignment) + prebuilt `ai/venv` (dev machine; prod native path uses `ai_env/`) |
| `docker-compose/postgres/` | PostgreSQL data directory |
| `nginx/` | Nginx config |
| `public/texts/simulator/` | (inside `laravel/public`) reading texts by language/level |

# Main components

* **Laravel app** — monolith serving three UI stacks simultaneously; see
  [Frontend](/architecture/frontend.md).
* **Filament 5 admin** at `/admin` — CRUD for dictionaries, entities and tag
  pools ([Entities & Alignment](/database/entities-alignment.md)); the manual
  alignment editor is an Inertia/React page outside the admin
  (`/works/{work}/alignments/{entityMatch}/edit`).
* **Queue workers** — background jobs on the database queue in two strict-order
  lanes (`default`, `low`; a job declares its lane as a class **attribute**,
  `#[Queue(QueueLane::DEFAULT)]`, see
  ADR 0042), each run bounded by a fixed run budget with unbounded pipelines
  self-re-dispatching over durable progress markers (ADR 0043): the upload
  pipeline (`ProcessEntityFile`, `SplitEntityFileSentences`,
  `FinalizeEntityDerivations`, `GenerateEntitySignature`), the
  [alignment pipeline](/domains/sentence-alignment.md)
  (`AlignEntitySentences`, self-re-dispatching per chunk), scheduled sweeps
  (`RefreshEntityWords`, `ComputeEntityTextHash`) and `SyncAiModelsJob`.
* **Python microservice** (`ext_python`) — sentence-transformer models over
  HTTP: `/health`, `/embed`, `/split`, `/align`, `/enrich` (the old
  `/embed/batch` and `/cosine/batch` endpoints were removed). Sentence
  splitting and DP alignment run there; Laravel stores the results. Config:
  `config/services.php` → `python`.
* **External AI APIs** — six providers behind one abstraction; see
  [AI Providers](/domains/ai-providers.md).

# Request flow (typical authenticated page)

1. Nginx (`ext_nginx`, host port 8000) → PHP-FPM in `ext_app_laravel`.
2. `routes/web.php` — everything except `/` is behind the `auth` middleware
   (Laravel Breeze stack, see `routes/auth.php`).
3. Controllers return **Inertia responses** (`Inertia::render(...)`) rendering
   React pages from `resources/js/Pages/`.
4. POST endpoints under the same `auth` group serve the interactive features
   (AI questions, text pagination).

# Version truth

**Trust `composer.json` / `composer.lock` and `package.json` over prose** —
older AI-authored guidelines (and older copies of this wiki) have listed
outdated versions. Actual: PHP ^8.3 (8.4
runtime), Laravel ^13.6, Livewire ^4, Filament ^5, Pest ^5, PHPUnit ^13,
Tailwind ^4, React ^19, Inertia ^3.
