---
type: Infrastructure
title: Docker & Services
description: Containers, ports, mounts, and the rule that all PHP/Composer/NPM commands run inside the app container.
tags: [docker, infrastructure, devops]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-26T18:45:00Z }
sources:
  - id: compose
    resource: docker-compose.yml
    title: Docker Compose manifest
  - id: agents
    resource: AGENTS.md
    title: Agent guidelines (command rules)
---

# The golden rule

**All Laravel/PHP/Composer/NPM commands run inside the `ext_app_laravel`
container:**

```bash
docker exec ext_app_laravel php artisan migrate
docker exec ext_app_laravel composer run test
docker exec ext_app_laravel npm run build
```

Never run PHP/Composer/NPM on the host. There is no PHP toolchain on the host.

# Services

| Service | Container | Host port | Notes |
|---------|-----------|-----------|-------|
| `app` | `ext_app_laravel` | — | PHP-FPM; `working_dir: /var/www`; built from `LaravelDockerfile` |
| `db` | `ext_pgdb` | `54321` → 5432 | PostgreSQL; data in `docker-compose/postgres/`; creds from `laravel/.env` |
| `nginx` | `ext_nginx` | `8000` → 80 | Serves the app at `http://localhost:8000` |
| `embedding` | `ext_embedding` | `8001` → 8000 | FastAPI embedding API; HF model cache mounted at `docker-compose/embedding/models` |
| (vite dev) | inside `ext_app_laravel` | `8002` | Started by `composer run dev` / `npm run dev` |

All services share the `ext_net` bridge network; the app reaches the embedding
API at `http://ext_embedding:8000` (config `services.embedding.url`).

# Mounts

| Host | Container | Why |
|------|-----------|-----|
| `./laravel` | `/var/www` | Application code |
| `./` | `/var/repo` | Repo root, so `wiki:sync` / `wiki:validate` can read/write `wiki/` and verify `sources` paths |
| `./docker-compose/postgres` | `/var/lib/postgresql` | DB data |
| `./docker-compose/embedding/models` | `/root/.cache/huggingface` | Embedding model cache |

Note: inside the container the app is `/var/www` and the repo root is
`/var/repo` — so `wiki/` is `/var/repo/wiki` and a `sources` path like
`laravel/app/...` resolves to `/var/repo/laravel/app/...`.

# Everyday commands

```bash
docker exec ext_app_laravel composer run dev     # server + queue + logs + vite (concurrently)
docker exec ext_app_laravel composer run test    # config:clear + full test suite
docker exec ext_app_laravel vendor/bin/pint --dirty  # format changed files (required before committing)
```

# Python

A prebuilt venv for ML experiments lives at
`docker-compose/python/ai/ai_env/` (torch, sentence-transformers, transformers,
numpy, scipy, pysbd). Do **not** reinstall those packages; use
`docker-compose/python/ai/ai_env/bin/python <script>`.
