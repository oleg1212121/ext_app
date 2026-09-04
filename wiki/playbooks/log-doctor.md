---
type: Playbook
title: Log Doctor (scheduled error triage)
description: Hourly GitHub Actions workflow on the self-hosted prod runner — collect.sh scans laravel logs, failed_jobs and scheduler output since the last completed run, the read-only log-doctor opencode agent diagnoses each unique error against the live checkout, and issue-report.php deterministically files or comments GitHub issues (cause + solution); clean runs cost nothing and the window only advances after successful filing.
tags: [production, monitoring, opencode, github-actions, automation, howto]
status: stable
generated: { by: agent/opencode-go, at: 2026-08-31T20:00:00Z }
sources:
  - id: workflow
    resource: .github/workflows/log-doctor.yml
    title: Scheduled workflow (hourly cron + manual dispatch)
  - id: collect
    resource: laravel/scripts/log-doctor/collect.sh
    title: Host-side collector (logs, failed_jobs, scheduler)
  - id: analyze
    resource: laravel/scripts/log-doctor/analyze.sh
    title: opencode invocation wrapper
  - id: report
    resource: laravel/scripts/log-doctor/issue-report.php
    title: Deterministic GitHub issue filing (runs in the app container)
  - id: agent
    resource: .opencode/agents/log-doctor.md
    title: Read-only log-doctor agent definition
  - id: deploy
    resource: wiki/playbooks/production-deployment.md
    title: Prod machine, runner and checkout context
---

# Model

`.github/workflows/log-doctor.yml` runs hourly (UTC cron) on the **same
self-hosted prod runner** `deploy.yml` uses (`runs-on: [self-hosted, prod]`),
against the prod checkout (`PROD_CHECKOUT` — the bind-mounted, live source).
Four steps, three scripts:

```
collect.sh (host)          analyze.sh (host)              issue-report.php (container)
logs + failed_jobs +  -->  opencode run --agent  -->      search "log-doctor-sig" in open issues
scheduler, deduped         log-doctor (read-only),        → found: comment "still occurring"
by signature               JSON out of the reply          → new: create bug/log-doctor/automated
                                                          (then) collect.sh --mark-done
```

The **LLM only diagnoses**; all filing/dedup decisions are deterministic. If
there is nothing to analyze, the run is grep-only and costs no tokens.

# What is scanned (window = since the last completed run)

| Source | How | Notes |
|---|---|---|
| `laravel/storage/logs/laravel-<date>.log` | host-side awk | `ERROR`/`CRITICAL`/`ALERT`/`EMERGENCY` blocks; prod logs `warning+` via `LOG_STACK=daily`, warnings are deliberately ignored |
| `failed_jobs` table | `artisan tinker` in `ext_app_laravel` | queue failures often never reach laravel.log |
| scheduler container | `docker logs --since` | `schedule:work` task exceptions; absent on dev (skipped) |

First run ever looks back one hour. State lives in the runner user's
`~/.log-doctor/state.env` (`last_completed`), advanced **only** by the final
`--mark-done` step — so a failed analyze/report leaves the window in place and
the next hour reprocesses the same errors (issues dedup make that safe).

# Signatures and dedup

A signature = `sha1` (first 12 hex) of the error headline with digits → `#`
and long identifier-ish runs (session ids, UUIDs, hashes ≥16 chars) → `TOKEN`.
Deterministic across runs, so the same exception always maps to the same
signature. `issue-report.php` embeds `log-doctor-sig: <hash>` in every issue
body and searches open issues for that exact phrase before filing: existing
open issue → comment ("still occurring, N new occurrences, latest
cause/solution"); none → create. Closed issue + recurrence → a new issue
(intended: it regressed).

Caps per run: 8 unique groups, 60 excerpt lines per group, 10 failed-job
rows, 10 filed issues. Overflow groups are logged (visible in the Actions
run) but skipped — see Gotchas.

# Safety properties

- The agent is **read-only** (`.opencode/agents/log-doctor.md`:
  `edit/bash/task/webfetch/websearch/...: deny`; only `read/glob/grep/list`
  plus `external_directory: allow` so it can read the findings file outside
  the checkout). It cannot modify prod — the checkout IS the running app.
- `analyze.sh` writes a throwaway per-run opencode config: `snapshot: false`
  (no index bloat), `share: "disabled"` (prod excerpts must never be
  uploaded), `autoupdate: false`.
- Fixes are never auto-applied. Tickets carry cause + solution text only;
  the fix itself goes through dev machine → push to master → autodeploy.
- Same runner rule as `deploy.yml`: the workflow has **only**
  `schedule`/`workflow_dispatch` triggers — never `pull_request` (fork code
  must never run on the prod runner).

# One-time setup (prod machine)

1. Install opencode for the runner user (the user that owns the checkout and
   runs the runner service):
   `curl -fsSL https://opencode.ai/install | bash` (verify `opencode --version`
   on PATH for that user).
2. Authenticate opencode as that user: `opencode auth login` → select the
   desired provider. This writes to `~/.local/share/opencode/auth.json` and
   persists across runs. The workflow uses `LOG_DOCTOR_MODEL: opencode/big-pickle`
   (editable in the workflow); because `LOG_DOCTOR_API_KEY` is left unset,
   `analyze.sh` lets opencode use this local auth rather than injecting an
   API key into a throwaway config. For non-opencode providers that use API
   keys, set `LOG_DOCTOR_API_KEY` as a repo secret and the workflow will
   inject it.
3. Push this repo state to master (the workflow + scripts must be in the prod
   checkout) and let the Deploy workflow ship it.
4. Smoke test: Actions → Log doctor → Run workflow (`workflow_dispatch`).
   With no new errors it goes green and logs "clean". To force a ticket,
   trigger an error (or temporarily set `~/.log-doctor/state.env`
   `last_completed` back an hour) and watch one issue appear; re-run and
   verify it *comments* instead of duplicating.

The `bug` / `log-doctor` / `automated` labels are created automatically on
first filing (existing labels are never recolored).

# Manual / local use (dev machine)

```bash
LOG_DOCTOR_STATE_DIR=/tmp/log-doctor bash laravel/scripts/log-doctor/collect.sh
LOG_DOCTOR_STATE_DIR=/tmp/log-doctor bash laravel/scripts/log-doctor/analyze.sh <run_dir>
docker exec -i -e GITHUB_REPOSITORY=owner/repo ext_app_laravel \
    php /var/www/scripts/log-doctor/issue-report.php --dry-run < <run_dir>/agent-output.json
```

`--dry-run` prints the would-be issue body without any GitHub call. Inspect
`<run_dir>/agent-output.txt` (full agent reply) when the JSON block looks
wrong.

# Gotchas

- **GitHub disables cron workflows after 60 days without repo activity** —
  irrelevant at this repo's push cadence, but if tickets stop appearing,
  check the workflow isn't disabled (Actions tab banner).
- **Signature heuristics**: digits and long tokens are normalized; two
  *different* bugs with near-identical messages collapse into one ticket
  (human closes/comment), and the same bug with wildly different messages
  files twice. Acceptable for an hourly triage bot.
- **Overflow**: >8 unique groups in one hour means something big broke — the
  first 8 tickets + the run log's "skipped signature" warnings are the
  starting point; the skipped ones are not re-analyzed (the window advanced).
- **Timestamps are assumed UTC** (config `app.php` `'timezone' => 'UTC'`,
  no `APP_TIMEZONE` in env). Log windowing uses lexicographic comparison of
  `[Y-m-d H:i:s]` prefixes — a timezone change would silently shift windows.
- **Cost profile**: clean run = a few greps, $0. Run with findings = one
  opencode session over ≤8 bounded excerpts plus code exploration.
