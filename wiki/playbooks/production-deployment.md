---
type: Playbook
title: Production Deployment
description: Launching ext_app on the same host behind the Cloudflare tunnel — prod Docker image, compose overlay, workers, backups, dry-run.
tags: [docker, production, deployment, devops, howto]
status: stable
generated: { by: agent/opencode-go, at: 2026-08-26T15:45:00Z }
sources:
  - id: checklist
    resource: docs/production-launch.md
    title: Production launch checklist
  - id: compose-prod
    resource: docker-compose.prod.yml
    title: Prod compose override
  - id: dockerfile-prod
    resource: LaravelDockerfile.prod
    title: Multi-stage prod image
  - id: entrypoint
    resource: docker-compose/entrypoint.prod.sh
    title: Prod container entrypoint
  - id: nginx
    resource: nginx/conf/app.conf
    title: nginx site config
---

# Goal

Take the app live at `https://ext-app.abibook.xyz` on the existing host
(already running dev) behind the Cloudflare tunnel. No new host, no Redis yet
(database drivers for cache/session/queue; Redis deferred per the checklist).

# Prerequisites (Phase 1 — done)

Cloudflare tunnel token rotated + untracked; AI/admin/DB secrets rotated;
`WordsSearch` (SQLi) deleted; queue `retry_after` bumped to `660`;
`laravel/.env.production` + committed `.env.production.example` exist;
Resend mail configured. See [docs/production-launch.md](../../docs/production-launch.md).

# Secrets (all gitignored — never committed)

| File | Holds | Filled by |
|------|-------|-----------|
| `laravel/.env.production` | App env (APP_KEY, DB_*, AI keys, RESEND_API_KEY, ADMIN_*) | Phase 1.2/1.3/1.6/1.7 |
| `docker-compose/prod/postgres.env` | `POSTGRES_USER` / `POSTGRES_PASSWORD` / `POSTGRES_DB` | Phase 1.3 — MUST match `DB_*` in `.env.production` |
| `docker-compose/cloudflare/.env` | `TUNNEL_TOKEN` / `TUNNEL_ID` | Phase 1.1 |

# Build & run

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

The `app` service builds `LaravelDockerfile.prod` → tags `ext_app_prod`.
`queue`/`scheduler` reuse that image (no separate build). The image entrypoint
syncs baked assets into the nginx-shared `app_public` volume, runs
`storage:link`, `config:cache`, `route:cache`, `view:cache`,
`filament:optimize`, then `migrate --force`, seeds idempotent reference data
(`SentenceTypeSeeder`, `AiProviderSeeder`), and finally `php-fpm`.

`queue`/`scheduler` skip the entrypoint and wait for
`php artisan migrate:status` to succeed before consuming/starting.

# First-run gotchas

- **DB creds**: `docker-compose/prod/postgres.env` must match
  `DB_USERNAME`/`DB_PASSWORD`/`DB_DATABASE` in `laravel/.env.production`, or
  the app can't connect.
- **Admin**: run `docker exec ext_app_laravel php artisan admin:create`
  against the prod DB (uses `ADMIN_*` from `.env.production`).
- **Static assets after a rebuild**: the entrypoint `rsync`s the image's
  canonical `/opt/app-assets/public/` into the `app_public` volume on every
  boot, so asset updates land without `docker compose down -v`.
- **`storage` persists** on the `app_storage` named volume (shared by `app`,
  `queue`, `scheduler`) — uploads and logs survive recreates.
- **Scheduler**: `routes/console.php` schedules `alignments:resume` every 5
  min with `->withoutOverlapping()`. Without the `scheduler` service,
  stalled alignments never resume.

# Backups & restore

The `backups` sidecar runs crond → `docker-compose/prod/backup.sh` daily at
03:00 UTC. Dumps land in the `pgbackups` volume as
`ext_app-YYYYMMDD-HHMMSS.sql.gz`, 14-day retention.

Restore (against the prod DB; never the `testing` connection):

```bash
gunzip -c /backups/ext_app-YYYYMMDD-HHMMSS.sql.gz \
  | docker exec -i ext_pgdb psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"
```

For a clean restore, drop & recreate the DB first.

# Hardening applied (Phase 3)

- **Rate limits**: `throttle:20,1` on `/ai/question` + `/ai/question/stream`
  (see [bilinguals-simulator](../domains/bilinguals-simulator.md)); nginx
  `limit_req` on `/ai/question` as defense-in-depth.
- **`DB_SSLMODE`** env-driven (`config/database.php`); `=require` in prod.
- **Trusted proxies** narrowed to RFC1918 + loopback
  (`bootstrap/app.php`).
- **nginx**: `client_max_body_size 20m` (book uploads), `X-Frame-Options`,
  `X-Content-Type-Options`, `Referrer-Policy`.

# DB indexes (Phase 4.2)

`2026_08_26_154226_add_indexes_to_alignment_junction_tables` adds indexes to
`en_sentence_meaning_matches`, `ru_sentence_meaning_matches` (both FK
columns), `en_ru_entity_matches` (`ru_entity_id, status`), and `book_word`
(`book_id, is_solved`). The `(entity_id, order)` and
`(entity_match_id, order)` composites were already present.

# Redis — deferred

Launch with `CACHE_STORE=database`, `SESSION_DRIVER=database`,
`QUEUE_CONNECTION=database`. Revisit when the `jobs` table backs up under
load, cache lock contention shows in logs, or session reads become hot (see
[docs/production-launch.md](../../docs/production-launch.md) trigger list).

# CI

`.github/workflows/tests.yml` runs the Pest suite (`composer run test` against
a `postgres:18-alpine` service) and `vendor/bin/pint --test` on PRs and
`master` pushes.
