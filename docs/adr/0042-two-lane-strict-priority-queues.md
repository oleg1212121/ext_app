# ADR 0042: Two-lane strict queue priority with a per-job lane property

Date: 2026-09-26
Status: Accepted

## Context

All queue workers (the dev `composer run dev` script, the prod docker overlay's
`queue` service, the native `ext-queue@.service` units) consume a single
unnamed `default` queue on the database driver (Postgres `jobs` table; no
Redis in any environment). Eight job classes share it: the user-facing upload
pipeline, the long-running alignment chunks (up to 600s each), scheduled
sweeps nobody waits on, and the admin-triggered model sync. The wanted knob
is processing order only — unimportant jobs should wait behind important
ones — fixed per job type, with strict priority acceptable (a starved `low`
job is fine while `default` work exists).

## Decisions

### 1. Named queue lanes, not Redis/Horizon or delays

Two lanes: `default` (someone waits on the result) and `low` (nobody waits;
processed only when no `default` work is pending). Every worker consumes
`--queue=default,low`, so the ordering is strict and lives entirely in the
worker flags — no schema change (`jobs.queue` exists and is indexed) and no
new services. Alternatives rejected:

- *Redis + Horizon*: brings a Redis service into dev docker, prod compose
  AND the native prod machine, plus Horizon supervisor config and a driver
  migration — for weighted scheduling and a dashboard this order-only,
  two-lane use case does not need.
- *Dispatch-time `->delay()`*: not load-adaptive. A delayed job still lands
  in the same busy lane later, and the delay must be guessed at dispatch.

### 2. The lane lives on the job class as a `#[Queue]` attribute

Each job class carries `#[Queue(QueueLane::DEFAULT)]` — the framework's
`Illuminate\Queue\Attributes\Queue` attribute, with lane names from
`App\Jobs\QueueLane`. The bus dispatcher resolves the lane in precedence
order: dispatch-site override (`->onQueue()`) → class attribute → property
default. So no dispatch site changed, a one-off dispatch can still move a
job, and the default is one declarative line on the class.

A plain `public $queue = 'default';` property is **not possible**: the
`Queueable` trait already declares the property, and PHP forbids
redeclaring a trait property with a different definition (typed or untyped,
any non-null default) — it fatals at class composition. The attribute is
the supported declarative mechanism; a constructor
`$this->onQueue(QueueLane::DEFAULT)` call would work but is boilerplate in
all 8 jobs and not reflection-testable. Alternatives rejected:

- *Central config map* (`config/queue_lanes.php` mapping class → lane):
  needs constructor boilerplate on every job (`config()` cannot initialize
  typed properties), and a stray dispatch-site `->onQueue()` would silently
  override the registry. More machinery, same effect.

The job class is the unit of priority: the hand-rolled chains
(`ProcessEntityFile` → `SplitEntityFileSentences` → `FinalizeEntityDerivations`,
and `AlignEntitySentences`' per-chunk self-re-dispatch) keep each stage's own
lane — a chain does not inherit its predecessor's lane. Re-classifying a job
is editing its attribute line; `QueueLaneTest` additionally enforces that
every class in `app/Jobs` declares a lane.

### 3. Everything starts on `default`

This ships the knob, not a classification. Flipping a job to `QueueLane::LOW`
is a one-line change with no infra involvement; until then runtime behavior
is unchanged.

### 4. Two lanes only

A future `high` lane would extend `QueueLane` and be inserted into the worker
flags — nothing renumbers, no scale to migrate.

## Consequences

- The worker flags live in three places (composer `dev` script,
  `docker-compose.prod.yml` queue service, `ext-queue@.service` ExecStart)
  and must stay in sync; a new lane must be appended to all three.
  `QueueLaneTest` guards the job side (every job declares a lane), not the
  worker side.
- `deploy-native.sh` now runs `systemctl daemon-reload` before restarting the
  queue/scheduler units — a unit-file change shipped by `git pull` only takes
  effect after the manager reloads its definitions.
- Deploy surfaces: the compose change requires container recreation plus
  `./deploy.sh --stamp` (container-definition change); the systemd change
  reaches prod through `deploy-native.sh`'s daemon-reload; the composer
  script applies on the dev machine's next `composer run dev`.
- The `retry_after` > longest-job-timeout invariant (ADR-guarded by
  `QueueConfigTest`) is untouched: no job moved lanes or grew a timeout.
