# Production Launch Checklist

Step-by-step launch checklist for `ext-app.abibook.xyz` (same host + Cloudflare tunnel).
Registration stays open behind the `EnsureUserIsApproved` gate (no change).
Decisions locked: **mail = Resend**, **Redis = later (deferred past initial launch)**.

Legend: `[USER]` = only you can do it · `[AGENT]` = I can do it · `[USER+AGENT]` = both.

---

## Phase 1 — Secrets & P0 Code Fixes

### 1.1 Rotate Cloudflare tunnel token `[USER+AGENT]`
- [ ] `[USER]` Cloudflare Zero Trust dashboard → Networks → Tunnels → regenerate the token for the tunnel currently in `docker-compose/cloudflare/.env`
- [ ] `[AGENT]` `git rm --cached docker-compose/cloudflare/.env`; add `docker-compose/cloudflare/.env` to root `.gitignore`; commit
- [ ] `[USER]` Put the new token in the local (now untracked) file; `docker compose up -d cloudflared`; verify tunnel reconnects in the dashboard
- **Why**: this file is *committed to git* with a live `TUNNEL_TOKEN` — the only truly leaked secret in the repo

### 1.2 Rotate AI provider keys `[USER]`
- [ ] Gemini — https://aistudio.google.com/apikey — revoke `AIzaSyB3…`, create new
- [ ] Groq — console.groq.com — revoke `gsk_Z3Jm…`
- [ ] OpenRouter — openrouter.ai/keys — revoke `sk-or-v1-3a3d…`
- [ ] HuggingFace — huggingface.co/settings/tokens — revoke `hf_Pqjq…`
- [ ] Update `laravel/.env` (dev) and `laravel/.env.production` (after 1.6)
- [ ] Verify: ask a question in the simulator end-to-end
- Note: `laravel/.env` is gitignored with zero git history — these leaked via disk/sharing, not via git

### 1.3 Rotate remaining secrets `[USER]`
- [ ] Admin password (`ADMIN_PASSWORD=111333` — trivially weak); new value lives in prod env only, then run `php artisan admin:create` (the `CreateAdminCommand`) against the prod DB
- [ ] DB creds `test/test` → strong random password; update `POSTGRES_PASSWORD` + `DB_PASSWORD` together (requires recreating the postgres volume or `ALTER USER` — plan a short maintenance window)
- [ ] Proxy creds (`PROXY_LOGIN` / `PROXY_PASSWORD`) if still in use; remove from `.env` entirely if not
- [ ] Do **not** rotate `APP_KEY` unless you suspect a leak — it invalidates all sessions. If you do, expect session logout (low impact here, `SESSION_ENCRYPT=false`)

### 1.4 Delete `WordsSearch` (SQLi) `[AGENT]`
- [ ] Delete `laravel/app/Livewire/WordsSearch.php`
- [ ] Delete `laravel/resources/views/livewire/words-search.blade.php` (if present)
- [ ] Verify zero references: `grep -rn "WordsSearch\|words-search" laravel/app laravel/resources laravel/routes laravel/tests`
- [ ] Run `docker exec ext_app_laravel composer run test:tia`
- **Why**: `WordsSearch.php:15` interpolates `$this->search` into a raw `DB::select()` — SQL injection. Confirmed zero references in the codebase → dead code, delete rather than fix

### 1.5 Fix queue `retry_after` mismatch `[AGENT]`
- [ ] `laravel/config/queue.php` line 43: default `300` → `660` (must exceed the max job timeout; `AlignEntitySentences::$timeout = 600`). Keep env-driven via `DB_QUEUE_RETRY_AFTER`
- [ ] Update the stale comment on line 42 (it cites 180s)
- [ ] Confirm all job timeouts fit under 660: `AlignEntitySentences` 600, `GenerateEntitySignature` 180, `SplitEntityFileSentences` 180, `ProcessEntityFile` 120, `SyncAiModelsJob` 120 ✓
- [ ] Add a unit test asserting `config('queue.connections.database.retry_after') > 600`
- [ ] Verify live: dispatch an alignment job, confirm no duplicate execution in the `jobs` table
- **Why**: `retry_after=300 < timeout=600` → a second worker can retry a still-running job → duplicate alignment work

### 1.6 Create `.env.production` template `[AGENT]`
- [ ] New `laravel/.env.production` (already gitignored) + committed `laravel/.env.production.example` with empty values, containing:
  - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://ext-app.abibook.xyz`
  - `LOG_LEVEL=warning`, `LOG_STACK=daily` (current `LOG_STACK=single` overrides `LOG_CHANNEL=daily` — fix so daily rotation is actually active)
  - `DB_HOST=db`, `DB_PORT=5432` (decouples from the host-mapped port 54321 — prerequisite for removing the port mapping in 2.2)
  - Strong `DB_PASSWORD`
  - `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=ext-app.abibook.xyz`, `SESSION_ENCRYPT=true`
  - `MAIL_MAILER=resend`, `RESEND_API_KEY=` (filled in 1.7), `MAIL_FROM_ADDRESS=noreply@ext-app.abibook.xyz`
  - No `DEBUGBAR_*` vars at all
  - `DB_SSLMODE=require` (placeholder — requires code change 3.2 first)

### 1.7 Configure Resend mail `[USER+AGENT]`
- [ ] `[USER]` Resend dashboard → API Keys → create key; verify your sending domain `ext-app.abibook.xyz` (add DNS records)
- [ ] `[AGENT]` `composer require resend/resend-laravel` (check package name / Laravel 13 compat before install); set `RESEND_API_KEY` in `.env.production`; `MAIL_FROM_ADDRESS=noreply@ext-app.abibook.xyz`
- [ ] Verify end-to-end: register a new account → verification email arrives in inbox → click → `/dashboard` unlocks (it has `verified` middleware); then test password reset flow
- **Why P0**: `MAIL_MAILER=log` means verification + reset emails vanish into `laravel.log`; with `/dashboard` requiring `verified`, users are locked out at launch

---

## Phase 2 — Production Docker (same host + Cloudflare tunnel)

### 2.1 Multi-stage prod Dockerfile `[AGENT]`
- [ ] New `LaravelDockerfile.prod`:
  - Stage 1 `node:20-alpine`: `npm ci && npm run build` → produces `public/build`
  - Stage 2 `php:8.4-fpm`: **no Xdebug**, `composer install --no-dev --optimize-autoloader`, copy `public/build` from stage 1
- [ ] Bake `public/texts/` into the image (it's gitignored — a git-built image ships without reading materials otherwise)
- [ ] PHP ini: `upload_max_filesize=20M`, `post_max_size=25M`, `opcache.enable=1`, `opcache.validate_timestamps=0`, `opcache.memory_consumption=128`
- [ ] Do **not** copy `docker-php-ext-xdebug.ini` or `docker-php-ext-xdebug copy.ini` into prod context
- [ ] Do **not** install `gh` CLI in the prod image

### 2.2 `docker-compose.prod.yml` override `[AGENT]`
- [ ] `app`: use the prod image, **no bind mounts** of source code
- [ ] `db`: pin `postgres:17-alpine` — first run `docker exec ext_pgdb postgres --version` to confirm the current major and pin to that; **remove `ports: 54321:5432`**; add healthcheck `pg_isready -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"`
- [ ] `python`: remove `ports: 8001:8000`; remove the `--reload` command override (the image `CMD` + `HEALTHCHECK` in `docker-compose/python/Dockerfile` are already prod-ready); add `--workers 2` only after checking host RAM (BGE-M3 is heavy)
- [ ] `nginx`: unchanged except conf updates from 3.3
- [ ] `cloudflared`: unchanged (env file now untracked after 1.1)
- [ ] Resource limits (`mem_limit` / `cpus`) on all services
- [ ] First-boot sequence (entrypoint script or documented manual step): `php artisan config:cache route:cache view:cache filament:optimize storage:link migrate --force`

### 2.3 Queue workers + scheduler `[AGENT]`
- [ ] Supervisor (or a dedicated `queue` service) running `php artisan queue:work --tries=1 --timeout=620` with `numprocs=2`, autorestart
- [ ] Scheduler: `php artisan schedule:work` as its own supervised process (or host cron `* * * * * cd /var/www && php artisan schedule:run >> /dev/null 2>&1`)
- **Why P0-adjacent**: `routes/console.php` schedules `alignments:resume` every 5 min (`->withoutOverlapping()`). With no scheduler running, stalled alignments never resume and nothing alerts you

### 2.4 Postgres backups `[AGENT]`
- [ ] Daily `pg_dump` cron sidecar → timestamped dumps on a named volume, 14-day retention, gzip
- [ ] Delete or relocate `backup11.sql` from the repo root (gitignored, but don't ship it)
- [ ] Document the restore command in this checklist

---

## Phase 3 — P1 Code & Hardening

### 3.1 Rate limit AI endpoints `[AGENT]`
- [ ] `throttle:20,1` on `/ai/question` and `/ai/question/stream` (`laravel/routes/web.php` lines 125–126)
- [ ] Feature test that hits the limit and asserts 429

### 3.2 Env-driven `sslmode` `[AGENT]`
- [ ] `laravel/config/database.php` lines 97 and 115: `'sslmode' => env('DB_SSLMODE', 'prefer')`
- [ ] Then `DB_SSLMODE=require` in `.env.production` actually takes effect
- [ ] Note: also set on the `testing` connection if you want test parity (optional)

### 3.3 nginx hardening `[AGENT]`
- [ ] `client_max_body_size 20m;` (default 1MB will 413 on real book uploads via `ProcessEntityFile`)
- [ ] Headers: `X-Frame-Options SAMEORIGIN`, `X-Content-Type-Options nosniff`, `Referrer-Policy strict-origin-when-cross-origin`
- [ ] Optional defense-in-depth: `limit_req_zone` for AI endpoints (in addition to the Laravel throttle in 3.1)

### 3.4 Narrow trusted proxies `[AGENT]`
- [ ] `laravel/bootstrap/app.php` line 15: `'0.0.0.0/0'` → `['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']` (Docker bridge + cloudflared live in these)
- [ ] Verify `SESSION_SECURE_COOKIE` still sets correctly behind the tunnel (DevTools → Application → Cookies → `Secure` flag present)

### 3.5 Paginate ReaderController `[AGENT]`
- [ ] `laravel/app/Http/Controllers/ReaderController.php` lines 96 and 127: `->all()` → paginate/chunk
- [ ] Audit the same pattern: `laravel/app/Http/Controllers/Test.php:204`, `laravel/app/Http/Controllers/AlignmentEditorController.php` (lines 55, 145, 374, 469, 478, 493), `laravel/app/Http/Controllers/EntityController.php:267,329,429,607`, `laravel/app/Http/Controllers/ProfileController.php:33`

### 3.6 Redis — DEFERRED
- [ ] Deferred past initial launch (decision locked). Launch with database drivers for `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION`. Add a `redis:7-alpine` service and switch drivers when load justifies it. Document the trigger threshold here when revisited.

### 3.7 Error tracking `[USER+AGENT]`
- [ ] `[USER]` Create a Sentry project (or Flare/Bugsnag) → capture the DSN
- [ ] `[AGENT]` `composer require sentry/sentry-laravel` (verify Laravel 13 support); wire into `bootstrap/app.php` exception handler; DSN in `.env.production`

---

## Phase 4 — P2 & Final Verification

### 4.1 CI `[AGENT]`
- [ ] New `.github/workflows/tests.yml`: run `composer run test` on PRs (currently only `.github/workflows/tia-baseline.yml` exists — there is **no** PR test workflow at all)
- [ ] Add a `vendor/bin/pint --test` job to the same workflow

### 4.2 DB indexes `[AGENT]`
- [ ] Audit junction tables (`entity_sentence`, meaning-match tables) for missing composite indexes
- [ ] One migration adding the missing indexes

### 4.3 Registration policy — no action
- [ ] Stays open; `EnsureUserIsApproved` gates every functional route. Just document where in Filament the admin flips `is_approved` on a pending user

### 4.4 Dry run `[AGENT+USER]`
- [ ] `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build` on this host
- [ ] Run migrations against a **copy** of the dev DB first. ⚠️ Never `migrate:fresh` / `migrate:refresh` / `migrate:reset` / `migrate:rollback` / `db:wipe` on the default `pgsql` connection — it points at `ext_app`, not the test DB (repo rule)
- [ ] Full user journey: register → pending-approval → admin approves in Filament → verify email (Resend) → dashboard → reader loads texts → simulator AI question (throttle works) → dispatch alignment job → watch queue for duplicates → `laravel.log` at `warning` level shows no errors
- [ ] Confirm `ext-app.abibook.xyz` serves the prod build with secure cookies (DevTools → Application → Cookies → `Secure` + `HttpOnly` flags)

### 4.5 Repo bookkeeping `[AGENT]`
- [ ] `docker exec ext_app_laravel php artisan wiki:sync` after any route/command changes
- [ ] `docker exec ext_app_laravel php artisan wiki:validate` passes (also runs in the test suite)
- [ ] `docker exec ext_app_laravel vendor/bin/pint --dirty`
- [ ] Update `wiki/log.md` + add a new `wiki/` concept for the production setup
- [ ] Delete the stray committed file `laravel/resources/views/components/crossword/SELECT * FROM users;.pgsql`

---

## Open trigger: revisiting Redis (3.6)
Revisit when any of these hold:
- queue `jobs` table backs up under load
- cache `lock` contention shows in logs
- session reads become a hot path

At that point: add `redis:7-alpine` to `docker-compose.prod.yml`, set `CACHE_STORE=redis` / `SESSION_DRIVER=redis` / `QUEUE_CONNECTION=redis`, and re-run the dry run (4.4).
