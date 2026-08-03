---
type: Infrastructure
title: Docker & Services
description: Containers, ports, mounts, and the rule that all PHP/Composer/NPM commands run inside the app container.
tags: [docker, infrastructure, devops]
status: stable
generated: { by: agent/opencode-go, at: 2026-08-03T19:30:00Z }
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
| `python` | `ext_python` | `8001` → 8000 | FastAPI python API (splitting, embeddings, alignment). **Two lazy-loaded models**: BGE-M3 (1024-dim, signatures) + MiniLM-L12 (384-dim, aligner), both in the named volume `ai_models` at `/app/models`. Dev `command:` runs `uvicorn --reload` so `.py` edits apply without rebuild; `docker-compose/python/env/.env` is re-read per request for live threshold/model-path tuning. `HF_HUB_OFFLINE=1` at runtime |
| `ai_models` (volume) | — | Named Docker volume for model weights (BGE-M3, MiniLM-L12). Survives container recreates; not in git. Add models via `docker exec -e HF_HUB_OFFLINE=0 ext_python python /app/scripts/download_model.py <hf_repo_id> /app/models/<name>` |
| (vite dev) | inside `ext_app_laravel` | `8002` | Started by `composer run dev` / `npm run dev` |

All services share the `ext_net` bridge network; the app reaches the python
API at `http://ext_python:8000` (config `services.python.url`).

# Mounts

| Host | Container | Why |
|------|-----------|-----|
| `./laravel` | `/var/www` | Application code |
| `./` | `/var/repo` | Repo root, so `wiki:sync` / `wiki:validate` can read/write `wiki/` and verify `sources` paths |
| `./docker-compose/postgres` | `/var/lib/postgresql` | DB data |
| `./docker-compose/python/ai` | `/app/ai` | Live python source (mirror of the Laravel bind-mount pattern; `uvicorn --reload` picks up edits without rebuild) |
| `./docker-compose/python/env` | `/app/env` (ro) | Live `.env` for `ext_python`; `config.py` re-reads `/app/env/.env` per request so threshold/window/model-path edits apply without recreate/restart. Also set via `env_file:` for import-time constants |
| `./docker-compose/python/scripts` | `/app/scripts` (ro) | Helper scripts (`download_model.py`) |
| `ai_models` (named volume) | `/app/models` | Model weights (BGE-M3 at `/app/models/bge_m3`, MiniLM at `/app/models/minilm`). The image itself carries no models |

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
