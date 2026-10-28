---
type: Playbook
title: Production Deployment
description: Prod runs natively (no Docker) since 2026-09-14 — pushes to master autodeploy via deploy-native.sh on a self-hosted runner (git pull, composer/npm, additive migrate, seeds, systemd restarts; python restart + /health gate only when python code/deps changed). The Docker bind-mount model (docker-compose.prod.yml + ./deploy.sh + container stamp guard) is still in tree for the dev machine and the legacy prod layout; its sections below are labeled.
tags: [docker, production, deployment, devops, howto]
status: stable
generated: { by: agent:zcode, at: 2026-10-28T22:30:00+03:00 }
verified: { by: agent:zcode, at: 2026-10-14T19:20:00+03:00 }
sources:
  - id: deploy-native
    resource: deploy-native.sh
    title: Native prod deploy script (current autodeploy target)
  - id: deploy
    resource: deploy.sh
    title: Docker-layout deploy script (dev machine / legacy prod)
  - id: compose
    resource: docker-compose.yml
    title: Docker Compose manifest (base, both machines)
  - id: compose-prod
    resource: docker-compose.prod.yml
    title: Prod overlay (standalone machine)
  - id: checklist
    resource: docs/production-launch.md
    title: Production launch checklist
  - id: ci
    resource: .github/workflows/tests.yml
    title: CI workflow (tests gate)
  - id: deploy-ci
    resource: .github/workflows/deploy.yml
    title: Deploy workflow (autodeploy via self-hosted prod runner)
  - id: agents
    resource: AGENTS.md
    title: Agent guidelines
---

# Model

**Current prod (since commit 7ecdb02, 2026-09-14): a native Ubuntu server, no
Docker.** The app processes run straight from the checkout
(`/home/alex/projects/ext_app`) under systemd units — `ext-queue@.service` ×2
(`queue:work --tries=1 --timeout=620 --queue=default,low`),
`ext-scheduler.service` (`schedule:work`), `ext-python.service`,
`ext-cloudflared.service` — with PostgreSQL native on the machine and the
python venv at `docker-compose/python/ai/ai_env/`. Releases are
`deploy-native.sh`, autodeployed on push to master.

The Docker bind-mount model below remains accurate **for the Docker layout** —
the dev machine (`docker compose up -d`, images as runtime only, code live via
bind mounts, no code baked into images) and the legacy standalone prod overlay
(`docker-compose.prod.yml` + `./deploy.sh`), which stays in tree but no longer
describes the running prod machine.

| | Dev machine (Docker layout) | Prod machine (native — current) |
|---|---|---|
| Stack | `docker compose up -d` | systemd units at the repo root + native PostgreSQL |
| Services | — (queue/scheduler run via `composer run dev`) | `ext-queue@1`, `ext-queue@2`, `ext-scheduler`, `ext-python`, `ext-cloudflared`, backups crontab |
| Release | edits are live instantly (bind mount) | `deploy-native.sh` (autodeployed on push to master) |

# Release to prod (native — current)

```bash
# or just push to master — the Deploy workflow runs deploy-native.sh
bash deploy-native.sh
```

What it does (in-place in the prod checkout; `deploy.yml` runs
`bash "$PROD_CHECKOUT/deploy-native.sh"`, `PROD_CHECKOUT=/home/alex/projects/ext_app`):

1. Guards: master-only (refuses any other branch) and an `.deploy.lock` flock —
   a manual SSH run and the CI runner can never interleave. **No container
   stamp guard** — that mechanism is deploy.sh/Docker-only; the native gates
   are these two plus the python health check below.
2. `git fetch` + `git pull --ff-only origin master`.
3. `ai_env/bin/pip install -r docker-compose/python/requirements.txt` — the
   python venv is gitignored (machine-local), so it self-heals from
   requirements.txt on every deploy (pip is a no-op when satisfied).
4. If this pull touched `docker-compose/python/` or `ext-python.service`:
   `systemctl restart ext-python`, then a `/health` gate (up to 60s) —
   a missing/broken requirement fails the deploy here instead of in users'
   requests. Unrelated deploys skip the restart and never drop in-flight
   python requests.
5. `composer install`, `npm ci` + `npm run build`, `storage:link`,
   `optimize:clear` → `config:cache` / `route:cache` / `view:cache`.
6. `migrate --force` — **additive only**, never fresh/refresh/reset/wipe.
7. Idempotent reference-data seeds: `SentenceTypeSeeder`, `AiProviderSeeder`,
   `LanguageSeeder`, `UiStringSeeder`, `CefrWordTagSeeder` (deploy.sh
   additionally seeds `PromptTemplateSeeder`).
8. `systemctl daemon-reload` (a unit file shipped by the pull only reaches
   restart after the manager reloads definitions) then
   `systemctl restart ext-queue@1 ext-queue@2 ext-scheduler` — workers finish
   their current job first.

# Deploy.sh (Docker layout)

```bash
./deploy.sh          # Docker layout only — the Deploy workflow runs deploy-native.sh instead
```

What it does (all inside the running `ext_app_laravel` container):

1. Guards: refuses to run from any branch other than `master`; refuses to
   start while another deploy holds the `.deploy.lock` (flock — a manual SSH
   run and the CI runner can never interleave); runs the **container stamp
   guard** (below), which aborts *before pulling* if incoming master changes
   container definitions or the stamp is missing.
2. `git pull --ff-only origin master` (aborts if it cannot fast-forward),
   then a second stamp comparison against the pulled working tree (catches
   hand-edits on the prod machine).
3. `composer install --prefer-dist --optimize-autoloader` — full install
   including dev deps (same tree serves CI/dev tooling).
4. `npm ci` + `npm run build` — rebuilds `public/build`; nginx serves it
   immediately (it bind-mounts `./laravel`).
5. `php artisan storage:link` (idempotent; keeps `public/storage` wired to
   uploads).
6. `optimize:clear`, then `config:cache` / `route:cache` / `view:cache` —
   stale caches from the previous release are dropped before rebuilding.
7. `migrate --force` — **additive only**: applies pending migrations. Never
   fresh/refresh/reset/wipe (see the destructive-command warning in AGENTS.md).
8. Idempotent reference-data seeds: `SentenceTypeSeeder`, `AiProviderSeeder`,
   `LanguageSeeder`, `UiStringSeeder`, `PromptTemplateSeeder`,
   `CefrWordTagSeeder`.
9. `queue:restart` — recycles the overlay's queue workers onto the new code
   (workers finish their current job, the container restarts, the new worker
   loads the new code and schema).

No PHP restart is needed: the image has no OPcache, so new code takes effect
on the next request.

# Container stamp guard (deploy.sh / Docker layout only)

Containers and images are defined by five files: `docker-compose.yml`,
`docker-compose.prod.yml`, `LaravelDockerfile`,
`docker-compose/python/Dockerfile`, and
`docker-compose/python/requirements.txt` (the python image bakes
requirements.txt; `docker-compose.override.yml` is skipped on prod by the
explicit `-f` files, and gitignored `.env` files never change via git).
Nothing tracks whether the running containers were created from their
*current* content — compose's config-hash ignores Dockerfile-only changes —
so `deploy.sh` keeps its own record. The native prod path has no equivalent
(git pull + systemd; no containers to drift).

- `./deploy.sh --stamp` writes the files' sha256 into `.container-stamp`
  (gitignored, prod-machine-local). Run it once after every manual
  rebuild/recreate; it is the only thing that says "the running containers
  match these files".
- Every deploy hashes the files at `origin/master` **before pulling** — a
  refusal leaves prod at the last-good release (important: `./laravel` is
  bind-mounted, so a pull alone would put new code live without migrations
  or assets) — and hashes the working tree after the pull. Any mismatch, or
  a missing stamp, aborts with exit 1, naming the changed files and printing
  the manual rebuild commands.

The fix path when a deploy refuses: `build` → `up -d` →
`./deploy.sh --stamp` → `./deploy.sh` (or re-run the Deploy workflow). A
refusal under CI surfaces as a red Deploy run on the push.

# One-time switchover (from the baked-image setup)

The prod machine previously ran the baked overlay (`ext_app_prod` image, code
baked in, storage at `docker-compose/prod/storage`, assets+texts mirrored into
`docker-compose/prod/public`). Switch it once:

1. `git pull` — brings `deploy.sh` + the bind-mount overlay; removes the baked
   files (the running containers keep running until step 5).
2. `cp laravel/.env.production laravel/.env` — single env source in this model
   (`DB_*` must match `docker-compose/prod/postgres.env`, as the launch
   checklist required).
3. Build the runtime image **once** (never per code change again):
   `docker compose -f docker-compose.yml -f docker-compose.prod.yml build app`
   (the python image is already built there; rebuild it only when
   `requirements.txt` changes).
4. Move persisted state out of the baked layout into the checkout:
   ```bash
   rsync -a docker-compose/prod/storage/ laravel/storage/
   rsync -a docker-compose/prod/public/texts/ laravel/public/texts/  # gitignored — git won't carry them
   ```
5. `docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile cloudflare up -d`
   - `app`/`queue`/`scheduler`/`nginx`/`python` recreate onto the bind-mount
     config (seconds — same images, no builds).
   - `db` config is **identical to the old overlay** → the container stays
     as-is; the cluster is found and boots untouched.
   - The old `ext_app_prod` image is now unused (`docker image rm ext_app_prod`
     to reclaim space).
6. Smoke checklist:
   - row counts of key tables unchanged after first `up` (DB untouched);
   - `docker compose ... ps` — queue ×2, scheduler, backups, cloudflared Up;
   - app answers through the tunnel (200 on `/`);
   - `docker logs` on a queue container shows `migrate:status` wait passing
     then `queue:work` idle-processing; scheduler logs show per-minute ticks.

From then on, every release is `./deploy.sh` (~1 min).

# DB guarantees (prod)

- `deploy.sh` applies **pending migrations only** plus idempotent seeds; no
  destructive command exists anywhere in the deploy flow.
- Recreating the `db` *container* never touches data: PGDATA is the host bind
  mount `docker-compose/prod/postgres`, and postgres initializes only an
  **empty** directory — an existing cluster boots as-is. The overlay pins the
  same path and creds as the baked setup, so the existing cluster is always
  the one found.
- uid caveat: the container runtime user is uid 1000 (build args
  `user=alex/uid=1000`). If the prod machine's checkout owner is not uid 1000,
  `chown -R 1000:1000 laravel/storage laravel/bootstrap/cache` or adjust the
  build args.

# When images DO change

Images are rebuilt manually, only when a Dockerfile or system-level
dependency changes (on the machine that needs them):

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml build app      # LaravelDockerfile
docker compose -f docker-compose.yml -f docker-compose.prod.yml build python   # only when requirements.txt changes
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d          # recreate just the rebuilt service
./deploy.sh --stamp   # record that the containers now match these definitions
./deploy.sh           # ship the pending release (or re-run the Deploy workflow)
```

(On the dev machine, plain `docker compose build app` / `build python`.)

# CI gate

`.github/workflows/tests.yml` runs the Pest suite + `vendor/bin/pint --test`
plus the Vitest `frontend` and ESLint `lint` jobs on every push to `master` (and on PRs; also
`workflow_dispatch`). `.github/workflows/deploy.yml`
autodeploys: the self-hosted runner on the prod machine (labels
`self-hosted`, `prod`) runs `deploy-native.sh` against the prod checkout on
every push to `master` (and via `workflow_dispatch` to re-run after a manual
fix; a red run means the script aborted midway — fix on the prod machine and
re-run). Tests and deploy run in parallel — the test suite is not a deploy
gate; the native deploy's own gates are master-only, the flock, and the
python `/health` check.

The same runner also drives the hourly Log Doctor error triage
(`.github/workflows/log-doctor.yml` → `wiki/playbooks/log-doctor.md`).

Runner rules: `deploy.yml` must never gain a `pull_request` trigger (fork/PR
code must never execute on the prod self-hosted runner); the runner service
user must be able to `git fetch` non-interactively and own the checkout (or
carry a `safe.directory` entry for it). See the one-time setup below.

# Autodeploy setup (one-time, prod machine)

1. Bootstrap the stamp: verify the stack is current
   (`docker compose -f docker-compose.yml -f docker-compose.prod.yml
   --profile cloudflare ps`), then `./deploy.sh --stamp`.
2. Install a self-hosted runner (GitHub repo → Settings → Actions → Runners
   → New self-hosted runner), label it `prod`, and install it as a systemd
   service (`./svc.sh install <user> && ./svc.sh start`), as the user that
   owns the checkout and has docker group access.
3. Non-interactive git: the runner service has no SSH agent — verify
   `sudo -u <user> git -C <checkout> fetch origin master` succeeds (a
   passphraseless deploy key or an HTTPS token in the remote works). If the
   runner user differs from the checkout owner, also add
   `git config --global --add safe.directory <checkout>` for it.
4. Set `PROD_CHECKOUT` in `.github/workflows/deploy.yml` to the prod
   checkout's absolute path, and push that change from the dev machine.
5. Smoke test: push a trivial commit to master (or `workflow_dispatch`) —
   the Deploy run goes green and `git -C <checkout> log -1` shows the commit.

# Environment

- Dev machine: `laravel/.env`.
- Prod machine: `laravel/.env` (copy of `.env.production`),
  `docker-compose/prod/postgres.env` (db + backups),
  `docker-compose/cloudflare/.env` (tunnel). All gitignored, all on the host.

# Gotchas

- **Queue/scheduler/backups are prod-machine services** (overlay). On the dev
  machine they don't exist — jobs process only while `composer run dev` runs,
  and `alignments:resume` doesn't tick.
- **Queue lanes**: all workers consume `--queue=default,low` (strict order; a
  job's lane is a class **attribute**, `#[Queue(QueueLane::DEFAULT)]` —
  ADR 0042). Changing the `queue` service
  command is a **container-definition change** — `docker-compose.prod.yml` is
  one of the five stamped files, so the next deploy refuses until you
  recreate (`up -d`) and re-stamp (`./deploy.sh --stamp`). On the native
  systemd path (`ext-queue@.service`), unit-file changes need
  `systemctl daemon-reload` before a restart picks them up —
  `deploy-native.sh` runs it.
- **Prod serves its checkout's working tree.** Fine on a deploy-only machine;
  on the dev machine any local edit is live instantly.
- **`config:cache` freezes env reads.** After a deploy, `.env` edits have no
  effect until `php artisan optimize:clear` (or the next deploy).
- **Reading texts are gitignored** (`laravel/public/texts/`) — git pull never
  updates them; rsync separately when reading materials change.
- **Backups run only on the prod machine** — dumps land in
  `docker-compose/prod/backups/` daily at 03:00 UTC, 14-day retention.

# Restore

Native prod (current): dumps from the backups crontab restore directly
against the machine-local PostgreSQL — never the `testing` connection:

```bash
gunzip -c <backup>.sql.gz | psql -h localhost -U ext_app -d ext_app
```

Docker layout: dumps live in `docker-compose/prod/backups/` on the prod
machine. Restore against the prod DB:

```bash
gunzip -c docker-compose/prod/backups/ext_app-YYYYMMDD-HHMMSS.sql.gz \
  | docker exec -i ext_pgdb psql -U "$(grep ^DB_USERNAME= laravel/.env | cut -d= -f2)" \
      -d "$(grep ^DB_DATABASE= laravel/.env | cut -d= -f2)"
```

(Drop & recreate the DB first for a clean restore.)
