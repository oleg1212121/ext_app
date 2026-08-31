---
type: Infrastructure
title: Docker & Services
description: Containers, ports, mounts, and the rule that all PHP/Composer/NPM commands run inside the app container.
tags: [docker, infrastructure, devops]
status: stable
generated: { by: agent/opencode-go, at: 2026-08-31T16:45:00Z }
sources:
  - id: compose
    resource: docker-compose.yml
    title: Docker Compose manifest (base, both machines)
  - id: compose-prod
    resource: docker-compose.prod.yml
    title: Prod overlay (standalone machine)
  - id: deploy
    resource: deploy.sh
    title: Production deploy script
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

# Production (standalone machine, bind-mount model)

Production runs on a **separate machine** with the same bind-mount model: no
code is baked into images (`app`/`queue`/`scheduler` run `eng_ext_laravel`
with `./laravel` bind-mounted). Its standing stack is the base compose plus
`docker-compose.prod.yml`:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile cloudflare up -d
```

The overlay adds `queue` (2× `queue:work --tries=1 --timeout=620`),
`scheduler` (`schedule:work`), and the `backups` crond sidecar; toggles python
to `--workers 2` with no host ports; keeps `db` **identical to the old baked
overlay** (creds `docker-compose/prod/postgres.env`, PGDATA
`./docker-compose/prod/postgres`) so the existing cluster is found untouched.

Shipping code is `./deploy.sh` on the prod machine: fast-forward `git pull`,
then `composer install` / `npm ci` / `npm run build` / `storage:link` / cache
rebuild / **additive** `migrate --force` / idempotent seeds / `queue:restart`
inside the running `ext_app_laravel` container. Pushes to `master` autodeploy
it via the self-hosted runner (`.github/workflows/deploy.yml`), and a
**container stamp guard** refuses any deploy — before pulling — when the
containers were not created from the current container definitions (see the
playbook). Full walkthrough + one-time switchover from the baked setup:
[production-deployment](../playbooks/production-deployment.md).

- **Images rebuild manually only** when a Dockerfile changes:
  `docker compose -f docker-compose.yml -f docker-compose.prod.yml build app`
  (or `build python`), then `up -d` + `./deploy.sh --stamp`.
- **CI**: `.github/workflows/tests.yml` (Pest + Pint) on every `master`
  push (and PRs); `.github/workflows/deploy.yml` autodeploys via the
  self-hosted `prod` runner — deploys are machine-run, container
  recreation stays human-run.
- **No OPcache in the image** → new code applies on the next request, no
  PHP restart needed.
- **Secrets on the prod machine** (all gitignored): `laravel/.env` (copy of
  `.env.production`), `docker-compose/prod/postgres.env`,
  `docker-compose/cloudflare/.env`.
- `.dockerignore` keeps `docker compose build app` contexts lean — it excludes
  dev runtime state and host data dirs (postgres clusters, model weights,
  venvs) from the repo-root build context.
