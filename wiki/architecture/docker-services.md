---
type: Infrastructure
title: Docker & Services
description: Containers, ports, mounts, and the rule that all PHP/Composer/NPM commands run inside the app container.
tags: [docker, infrastructure, devops]
status: stable
generated: { by: agent/opencode-go, at: 2026-08-27T17:30:00Z }
sources:
  - id: compose
    resource: docker-compose.yml
    title: Docker Compose manifest (dev)
  - id: compose-prod
    resource: docker-compose.prod.yml
    title: Docker Compose prod override
  - id: dockerfile-prod
    resource: LaravelDockerfile.prod
    title: Multi-stage prod image build
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
| `python` | `ext_python` | `8001` → 8000 | FastAPI python API (splitting, embeddings, alignment). **Lazy-loaded models from the host bind mount `./docker-compose/python/models` at `/app/models`** (see below). Dev `command:` runs `uvicorn --reload` so `.py` edits apply without rebuild; `docker-compose/python/env/.env` is re-read per request for live threshold/model-path tuning. `HF_HUB_OFFLINE=1` at runtime — override to `0` only when downloading models. |
| `./docker-compose/python/models` (host bind mount) | `/app/models` | Host bind mount for model weights. **Survives Docker Desktop "Purge data"** (which only wipes Docker-managed named volumes / the VM disk) and container recreates — so models are downloaded once, not per restart. Provision after `docker compose up -d` via `bash install-models.sh` (idempotent; downloads only missing models). Manual alternative per model: `docker exec -e HF_HUB_OFFLINE=0 ext_python python /app/scripts/download_model.py <hf_repo_id> /app/models/<name>` |
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
| `./docker-compose/python/models` (host bind mount) | `/app/models` | Model weights persisted here on the host disk. **LaBSE** at `/app/models/labse` (active: used for both signatures and alignment via `MODEL_PATH`/`ALIGN_MODEL_PATH` in `docker-compose/python/env/.env`). **BGE-M3** at `/app/models/bge_m3` (downloaded and staged for future use; switch by editing those two `.env` vars). The image itself carries no models. Host bind mount (not a named volume) so it survives Docker Desktop "Purge data". |

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

# Production overlay

`docker-compose.prod.yml` overrides the dev manifest for the production launch
(same host + Cloudflare tunnel). It is applied with both files:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

Differences from dev (using Compose `!override`/`!reset` merge tags):

| Service | Dev | Prod |
|---------|-----|------|
| `app` | bind-mounts `./laravel` + `./`; dev `LaravelDockerfile` (with gh, xdebug) | `LaravelDockerfile.prod` (multi-stage: node build → php-fpm, no xdebug/gh, opcache, baked `public/build` + `public/texts`); env via `laravel/.env.production`; `app_storage` + `app_public` **host bind mounts** (`./docker-compose/prod/storage`, `./docker-compose/prod/public`); entrypoint recreates the storage skeleton then runs `config:cache route:cache view:cache filament:optimize storage:link migrate --force` |
| `db` | `image: postgres` (latest), host port `54321`, bind-mount data | pinned `postgres:18-alpine`, no host port, bind-mount data `./docker-compose/prod/postgres` (host disk; survives Purge if the project dir is in Docker Desktop file sharing), healthcheck; creds from gitignored `docker-compose/prod/postgres.env` (POSTGRES_*) |
| `nginx` | bind-mount `./laravel` + `./nginx/conf` | serves baked assets from the host bind-mounted `app_public` dir (read-only) + `./nginx/conf`; hardened `app.conf` (20M body, security headers, `limit_req` on `/ai/question`) |
| `python` | host port `8001`, `--reload` | no host port, `--workers 2` |
| `cloudflared` | unchanged | unchanged (tunnel token untracked after Phase 1.1) |
| `queue` | — | new; `ext_app_prod` image, `queue:work --tries=1 --timeout=620`, `scale: 2`, waits for migrations |
| `scheduler` | — | new; `schedule:work` daemon (ticks every minute — drives `alignments:resume` `->withoutOverlapping()`) |
| `backups` | — | new; `postgres:18-alpine` sidecar running crond → daily `pg_dump` to the host bind-mounted `./docker-compose/prod/backups` (14-day retention, survives Purge) |

Secrets never live in compose: `laravel/.env.production`,
`docker-compose/prod/postgres.env`, and `docker-compose/cloudflare/.env` are
all gitignored. `.dockerignore` strips dev build output, `vendor/`,
`node_modules/`, the xdebug inis, and `backup11.sql` from prod build contexts.
See [production-deployment](../playbooks/production-deployment.md) for the launch playbook.
