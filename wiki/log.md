# Directory Update Log

## 2026-08-31

* **Prod overlay recreated for the standalone production machine (bind-mount adapted).**
  Production turns out to run on a separate machine (previously on the baked
  `ext_app_prod` overlay). Recreated `docker-compose.prod.yml` for the
  bind-mount model: `app` keeps the base `eng_ext_laravel` image + `./laravel`
  bind mount (no baked image, no env_file — env is `laravel/.env` on disk);
  `queue` (2× `queue:work --tries=1 --timeout=620`) and `scheduler`
  (`schedule:work`) run the runtime image with bind-mounted source and the
  wait-for-migrations loop; `backups` sidecar unchanged; python `--workers 2`
  + no host ports; cloudflared re-gated behind its profile (explicit `-f`
  files skip the override). **`db` is byte-identical to the old overlay**
  (postgres.env creds, PGDATA `./docker-compose/prod/postgres`) so the
  existing prod cluster is found untouched — `up -d` won't even recreate the
  container; every deploy applies pending migrations only. `deploy.sh` gained
  `php artisan storage:link`. Playbook rewritten (two-machine model, one-time
  switchover from the baked setup — env copy, one-time `build app`,
  storage/texts rsync, smoke checklist); Docker & Services + launch checklist
  updated. Validated with `docker compose -f docker-compose.yml -f
  docker-compose.prod.yml --profile cloudflare config`.

## 2026-08-31

* **Production deploys switched to the bind-mount model — `./deploy.sh`, no image rebuilds.**
  The baked-image prod overlay (a ~15-minute `docker compose up -d --build`
  per code change) was replaced: production runs on the same bind-mounted
  containers as dev, and deploying is `./deploy.sh` (master-only branch guard,
  fast-forward pull, `composer install --prefer-dist -o`, `npm ci` +
  `npm run build`, `optimize:clear` → config/route/view caches,
  `migrate --force`, idempotent `SentenceTypeSeeder`/`AiProviderSeeder`/
  `LanguageSeeder` seeds, `queue:restart`). Removed `LaravelDockerfile.prod`,
  `docker-compose.prod.yml`, `docker-compose/entrypoint.prod.sh`, and
  `docker-compose/php-prod.ini` (recoverable from git history;
  `docker-compose/prod/backup.*` + `postgres.env.example` kept, dormant).
  `.dockerignore` now also excludes host data dirs (postgres cluster, model
  weights, venvs, prod runtime state) from build contexts. CI unchanged:
  `tests.yml` (Pest + Pint) gates `master`; deploys are human-run. Updated the
  Production Deployment playbook, Docker & Services, the launch checklist,
  and AGENTS.md.

## 2026-08-30

* **Simulator AI panel retint: shared red + softer gloss-run shadow.** Inside
  `#ai_answer_div` (`public/css/simulator.css`), `--wbench-danger` and
  `--wbench-emphasis` are both overridden to `#fe2500` (scoped to the AI
  answer, the global tokens in the `@theme` block are unchanged), and the
  gloss-run `:hover` loses its underline in favor of a gray shadow
  (`text-shadow: 1px 1px 5px rgb(128 128 128 / 50%)`, day + night).
  Updated the Bilinguals Simulator concept.

## 2026-08-30

* **Rich AI-answer highlighting in the Bilinguals simulator.** The streamed
  answer pipeline `renderMarkdown()` in `Bilinguals.jsx` now does more than
  straight-quote wrapping: `==…==` phrases become `<mark>` (via `\u0001`
  sentinels around `marked.parse` so inline markdown inside them still parses),
  correction pairs `X => Y` (each side a quote, a wrapped inline tag, or a
  word) become `.ai-correction` spans with danger-struck old / accent
  underlined new / soft-ink mono arrow, quotes are wrapped in all four styles
  (`"…"`, `«…»`, `“…”`, `‘…’`), and `\d{1,3}%` scores become
  `<mark class="ai-score">` mono chips. New rules in `public/css/simulator.css`
  (day + night, existing `--wbench-*` tokens) plus GFM table styling; the
  gloss-run hover list gained `td, th`. The default assessment prompt in
  `SimulatorController` now instructs the model to use `##` headings,
  `~~removed~~`/`**added**`, `==double equals==`, quoted citations, and `>`
  blockquotes so the model's output maps onto the styled elements
  deterministically. Updated the Bilinguals Simulator concept.

## 2026-08-27

* **Removed Xdebug from the dev image; switched Pest TIA coverage to PCOV.**
  The dev `LaravelDockerfile` no longer installs Xdebug or copies
  `docker-php-ext-xdebug.ini` (which `.dockerignore` excluded, breaking the
  build context). PCOV is installed but **disabled by default** so normal CLI
  stays fast; `composer run test:tia` and the CI `tia-baseline` workflow enable
  it only for the baseline run via `-d extension=pcov.so -d pcov.enabled=1 --coverage`.
  Deleted the tracked dead `docker-php-ext-xdebug*.ini` files. Dev TIA and CI baseline
  both keep working. Updated `composer.json`, `AGENTS.md`, and wiki concepts.

* **Persistence hardening: all Docker data moved off named volumes onto host bind mounts.**
  Root cause of repeated data loss: Docker Desktop "Clean / Purge data" is a factory
  reset that wipes every Docker-managed **named volume** and reformats the VM disk — so
  the `ai_models` (model weights) and `pgbackups` (DB dumps) named volumes, plus the
  prod `app_storage`/`app_public` volumes, were destroyed on each Purge. The prod DB
  bind mount (`./docker-compose/prod/postgres`) also vanished because a bind mount only
  protects data if the project dir is in Docker Desktop **file sharing** (otherwise it
  silently resolves inside the VM). Changed `docker-compose.yml` (`ai_models` →
  `./docker-compose/python/models`) and `docker-compose.prod.yml` (`pgbackups` →
  `./docker-compose/prod/backups`; `app_storage`/`app_public` →
  `./docker-compose/prod/storage` + `./docker-compose/prod/public`). Added the storage
  skeleton recreation (`mkdir -p storage/framework/* ...`) to `entrypoint.prod.sh`
  because bind mounts shadow the image's skeleton (named volumes used to copy it on
  first mount). Updated `.gitignore`, `install-models.sh`, `env/.env`, and the wiki
  concepts. **Action for operators:** add the project root to Docker Desktop file
  sharing, and `chown -R 33:33 docker-compose/prod/storage docker-compose/prod/public`
  so `www-data` can write; then re-run `install-models.sh`.

* **Models now persisted in `ai_models` volume + idempotent `install-models.sh`.**
  Root-caused the `ext_app-queue-*` `ProcessEntityFile` / `AlignEntitySentences`
  failures to an empty `ai_models` named volume — the LaBSE model
  (`/app/models/labse`, used for both signatures and alignment via
  `MODEL_PATH`/`ALIGN_MODEL_PATH`) had never been downloaded, so the Python
  service returned `503 Model not loaded`. Added `install-models.sh` (host script:
  checks each model dir, downloads only missing ones into the volume with
  `HF_HUB_OFFLINE` temporarily `0`, then verifies both load offline). It now
  provisions **LaBSE** (`sentence-transformers/LaBSE`) at `/app/models/labse` and
  **BGE-M3** (`BAAI/bge-m3`) at `/app/models/bge_m3` (staged for future use). The
  volume persists across `docker compose up/down` recreates, so no re-download on
  restart — only `down -v` / `volume prune` would wipe it. Reconciled the stale
  `wiki/architecture/docker-services.md` (it claimed BGE-M3 + MiniLM; actual config
  is LaBSE active, BGE-M3 staged). Retried the two model-related failed jobs — both
  DONE. (One unrelated failed job remains: `EnEntity` id 1 `ModelNotFoundException`.)

* **Change: `ai_models.ai_provider_id` is now NOT NULL (was nullable).** The FK
  was originally added nullable (migration `2026_08_23_000002`) leaving
  pre-existing catalog rows orphaned (`ai_provider_id = NULL`); a follow-up
  migration `2026_08_24_000001_make_ai_models_provider_not_null` now enforces
  NOT NULL on already-migrated databases (raw `ALTER COLUMN ... SET NOT NULL`,
  idempotent on Postgres) and switches the FK to `cascadeOnDelete`. Orphan rows
  can no longer exist, so `App\Services\AiModelSync` dropped its null-provider /
  orphan-adoption branches and now requires a provider row to exist before
  syncing (the unique `(ai_provider_id, external_id)` index keeps the catalog
  mirror sound). `AiModelSyncTest` updated accordingly; affected AI suite green
  (`AiModelSyncTest`, `AIModelResolverTest`, `OpenRouterTest`, `UserCanUseAiTest`,
  `SimulatorApiKeyTest`, `UserCanUseAiParityTest`). Docs:
  `wiki/domains/ai-providers.md` (`generated.at` bumped).

## 2026-08-26

* **Production launch — Phases 2–4 (agent).** Production Docker, P1/P2 code
  hardening, CI, and DB indexes:
  * **Phase 2 (prod Docker):** added `LaravelDockerfile.prod` (multi-stage:
    `node:20-alpine` builds Vite → `php:8.4-fpm` no-xdebug/gh, opcache, baked
    `public/build` + `public/texts`, canonical asset copy + `rsync` entrypoint);
    `docker-compose/entrypoint.prod.sh` (idempotent `config:cache route:cache
    view:cache filament:optimize storage:link migrate --force`); new
    `docker-compose.prod.yml` override (prod image, no source bind mounts,
    pinned `postgres:18-alpine`, no host ports, healthcheck, resource limits,
    shared `app_public`/`app_storage` volumes); new `queue` (scale 2,
    `--tries=1 --timeout=620`) + `scheduler` (`schedule:work`) + `backups`
    (crond sidecar, daily `pg_dump`, 14-day retention); `.dockerignore`
    strips dev output/vendor/xdebug/`backup11.sql`; prod DB creds live in
    gitignored `docker-compose/prod/postgres.env`. Validated merge with
    `docker compose config`. Added `wiki/playbooks/production-deployment.md`
    and a Production-overlay section to
    [docker-services](architecture/docker-services.md).
  * **Phase 3 (hardening):** `throttle:20,1` on `/ai/question` +
    `/ai/question/stream` (+ `tests/Feature/AiQuestionThrottleTest.php`
    asserting 429 on the 21st request); `DB_SSLMODE` env-driven in
    `config/database.php` (both `pgsql` + `testing`); narrowed trusted proxies
    in `bootstrap/app.php` (`0.0.0.0/0` → RFC1918 + loopback); nginx
    `app.conf` hardened (`client_max_body_size 20m`, `X-Frame-Options`,
    `X-Content-Type-Options`, `Referrer-Policy`, `limit_req` on `/ai/question`).
    Phase 3.5 audit: all flagged `->all()`/`->get()` calls are per-entity/per-
    match scoped (no global scans); list endpoints already use
    `paginate(15)`/`limit(100)`/`forPage` — no change needed. 3.7 Sentry
    deferred (pending user creates the project; deps not added without
    approval).
  * **Phase 4:** `.github/workflows/tests.yml` (Pest suite against a
    `postgres:18-alpine` service + `pint --test` on PRs/`master`);
    `2026_08_26_154226_add_indexes_to_alignment_junction_tables` (indexes on
    `en_sentence_meaning_matches`/`ru_sentence_meaning_matches` FK columns,
    `en_ru_entity_matches(ru_entity_id, status)`, `book_word(book_id,
    is_solved)` — the anticipated `(entity_id,order)` and
    `(entity_match_id,order)` composites were already present).
  * Ran `wiki:sync` (regenerated `reference/`) + `wiki:validate` (conformant).

* **Production launch — Phase 1 (agent).** Security + launch-prep changes:
  deleted `App\Livewire\WordsSearch` and its Blade view (`WordsSearch.php:15`
  interpolated `$this->search` into a raw `DB::select()` — SQLi; confirmed zero
  references, dead code); raised the database queue `retry_after` default
  `300 → 660` so it exceeds the 600s `AlignEntitySentences::$timeout` (a second
  worker could otherwise retry a still-running job and duplicate alignment),
  guarded by `tests/Unit/QueueConfigTest.php`; added a committed
  `laravel/.env.production.example` (prod template — daily logs, `DB_HOST=db`
  /`5432`, `SESSION_*` secure cookies, `MAIL_MAILER=resend`) plus a gitignored
  `laravel/.env.production`; untracked and gitignored
  `docker-compose/cloudflare/.env` (leaked tunnel token); ran
  `composer require resend/resend-laravel` (Resend mail transport — reads
  `RESEND_API_KEY`, falls back to the existing `RESEND_KEY` wiring).
  Tombstoned `wiki/domains/words-search.md` and updated
  `wiki/architecture/frontend.md`. **User steps remaining:** rotate Cloudflare
  tunnel token, AI provider keys, admin/DB/proxy secrets; create Resend API
  key + verify sending domain; end-to-end registration/password-reset email flow.

## 2026-08-25

* **Change: entities *Sentences* tab (Edit.jsx) now paginates and shows
  sequential display order, matching the alignments editor.** `EntityController`
  `sentences` endpoint is paginated (`page`/`per_page`, default 25) and returns
  `{sentences, meta, before_first_id}`; `store`/`reorder`/`destroy` JSON
  endpoints also return the affected page (store/reorder return the page holding
  the new/moved sentence). The UI renders a 1-based `display_order`
  `(page-1)*per_page + index + 1` instead of the raw sparse `order` (fixes the
  negative-number display that drag-to-beginning produced). A per-row "+ add"
  icon opens an inline form that inserts directly below that row
  (`after_sentence_id` = that row's id); the top-level "+ Add sentence" button
  and "Insert position" select were removed (empty list shows a single "Add the
  first sentence" link). `before_first_id` lets a drop at the top of page N>1
  anchor to the previous page's last sentence. Non-negative order guard now
  mirrors `AlignmentEditorController`. Tests: `EntityEditingTest` covers
  pagination, `before_first_id`, store-page-targeting, and non-negative orders.
  No routes/models/commands changed (`wiki:sync` not required).

* **Change: granted users may edit entities and sentences in the entities
  frontend (ADR 0015).** The entities frontend (`/entities`) is now a full
  editing surface, not read-after-create-only. `EntityAccessService::canEdit`
  mirrors `canRead` (admin bypass; Restricted → grantees; Public → any
  approved user) and gates `entities.edit`, `entities.update`, and four JSON
  sentence endpoints (insert, update content+type, cascade delete, drag
  reorder). The `Edit` page (`resources/js/Pages/Entities/Edit.jsx`) combines
  a metadata form (Inertia PATCH) with a `@dnd-kit/sortable` sentence manager
  that fetches via `entities.sentences` JSON. Drag-to-reorder uses
  `SparseOrderService::orderForInsertAfter` with `after_sentence_id = 0` as a
  "beginning" sentinel. Cascade delete reuses the sentence models'
  `deleting`/`deleted` hooks (junctions removed, empty meaning matches
  deleted, `linked_count` updated) — a deliberate divergence from the
  alignment editor's unlink-before-delete rule. Every sentence mutation flips
  all `EnRuEntityMatch` rows involving the entity to `status = 'pending'`;
  the `signature` is left stale. Known gaps (v1): public-edit + cascade-delete
  by any approved user with no audit/soft-delete. Updated: `CONTEXT.md`
  (Access grant → read+edit; new "Insert sentence" term; new "Edit rule"
  term), `wiki/domains/entities.md`, `wiki/domains/access-control.md`,
  `wiki/database/entities-alignment.md`. New: ADR 0015, form requests
  (`UpdateEntityRequest`, `StoreEntitySentenceRequest`,
  `UpdateEntitySentenceRequest`, `ReorderEntitySentenceRequest`), test
  `tests/Feature/EntityEditingTest.php` (28 tests).

* **Change: simulator hides its AI surface for keyless users.** New
  `User::canUseAi()` (key for an enabled provider with an enabled, unexpired
  model — the same predicate `AIModelResolver::getGroupedModels()` applies)
  is the single capability predicate, surfaced as the `canUseAi` and `showAI`
  Inertia props (`showAI` is now `false` for keyless users instead of the
  hardcoded `true`). The frontend hides the per-row "Ask" button
  (`TextContent.jsx`), the "Question" section + its header toggle, and the
  "AI response" panel + its header toggle (`Bilinguals.jsx`, `Workplace.jsx`)
  when `canUseAi` is false; the "Open" button, the translation textarea, the
  Text/Workplace toggles, and the header "Add an API key in your Profile"
  link stay visible. The `/ai/question` request paths remain server-guarded by
  the 403 in `AIModelResolver::ask()`/`askStreamed()` (ADR 0012) — no new
  backend gate. `AIModelResolver` was intentionally left untouched; drift
  between the new predicate and `getGroupedModels()` is guarded by a parity
  test. `canUseAi` is a code-only name (no new CONTEXT.md glossary term;
  "Available (to a user)" already covers the provider-level predicate). Tests:
  extended `SimulatorApiKeyTest` (props for capable + keyless users), new
  `Unit/UserCanUseAiTest` (four capability scenarios), new parity test. No ADR.

* **Add: per-entity access control — default-restricted uploads + Signature-match grants.**
  New `is_restricted` boolean on `en_entities`/`ru_entities` (default false) and
  two grant pivots `en_entity_user` / `ru_entity_user` (user_id, nullable
  `similarity` for creator-vs-match distinction). Every upload now runs a
  synchronous `TextSignatureService::findSimilarExisting` check: a ≥0.95 cosine
  match links the uploader to the existing Entity (Access grant, file deleted)
  instead of creating a duplicate; otherwise a new Restricted entity is created
  with a creator grant. `EntityAccessService` (canRead / canReadMatch / grant /
  readableQuery / readableMatchQuery) enforces reads in `EntityController`,
  `ReaderController`, and `SimulatorController`; admin publishes via the Filament
  `is_restricted` toggle. No `created_by` column — the uploader's access is a
  grant row like any other. Defense-in-depth: `ProcessEntityFile` still detects
  duplicates asynchronously and migrates their grants onto the survivor. ADRs
  0013 (default-restricted + per-entity grants) and 0014 (per-entity grants
  require both sides to read a simulator match). `CONTEXT.md` gained the Entity
  Access context; `SimulatorEntitySeeder` marks `the_book_thief_5.txt` restricted.
  New/updated tests in `EntityControllerTest` and `TextSignatureServiceTest`
  (grant-on-match, fail-hard on embedding outage, both-sides 403, pivot
   migration); full suite green (380). `generated.at` bumped on access-control,
   entities, entities-alignment, reader, bilinguals-simulator; `wiki:sync`
   regenerated references.

* **Fix: authorization leak in the Alignments editor (`AlignmentEditorController`).**
   The 11 editor endpoints (`rows`, `unmatched`, `needs-review`, `storeRow`,
   `destroyRow`, `approveRow`, `storeSentence`, `updateSentence`, `unlinkSentence`,
   `destroyUnmatched`, `moveSentence`) bound `EnRuEntityMatch` via route-model
   binding with zero authorization, so any authenticated user could read *and
   mutate* alignments on restricted entities. Each endpoint now runs
   `abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403)`
   as its first statement (mirroring `AlignmentController`), ahead of the existing
   `meaningMatch → match` 404 checks, so non-granted users get 403 (never 404).
   Public matches remain readable by every approved user, so the existing
   `AlignmentEditorApiTest` suite is unaffected. New
   `tests/Feature/AlignmentEditorAccessTest.php` asserts 403 for a non-granted user
   across reads + `approveRow`, and 200 once both-side grants are attached.
   `wiki:validate` passes; `access-control` / `sentence-alignment` concepts updated.


## 2026-08-24

* **Add: front-end `/entities` management surface (first consumer of `Language::enabled()`).**
  New Inertia/React area for approved users to manage per-language text entities,
  separate from the read-only reader: `/entities` (picker — one card per enabled
  language with an entity count), `/entities/{lang}` (paginated list + "+ Create
  entity"), `/entities/{lang}/create` (form: name, description, optional `.txt`
  upload), `POST /entities/{lang}` (creates the entity, stores the file to
  `entities/{lang}` on the `local` disk, and dispatches `ProcessEntityFile` only
  when a file is present), and `/entities/{lang}/{entity}` (detail: header +
  "Read" link to `/reader-react/{lang}/{id}` + "Open alignment" links to any
  `EnRuEntityMatch` rows + read-only sentence list). Backed by new
  `EntityController` (switches on `$lang` via `EnEntity`/`RuEntity`, validates
  `{lang}` against `Language::enabled()` → 404 otherwise) and
  `StoreEntityRequest`. Edit/delete stay admin-only; alignment pairing stays in
  `/alignments`. ADR 0002 records the `Language::enabled()` wiring (first
  production consumer; ADR 0001's standalone stance evolved, not reversed).
  `NavBar.jsx` gained an Entities link; `CONTEXT.md` gained the Entity glossary
  term. New `tests/Feature/EntityControllerTest` (12 tests, green), pint clean.
  Docs: new `wiki/domains/entities.md` (registered in `index.md`); ADR 0002;
  `CONTEXT.md` Entity entry; `wiki:sync` regenerated `reference/web-routes.md`.
  `generated.at` bumped.

* **Add: `languages` table + full admin CRUD + seeder (standalone registry).**
  New `languages` table (`App\Models\Language`) is an admin-managed lookup
  registry surfaced as a full Filament CRUD on `/admin` (`LanguageResource` with
  List/Create/Edit pages): `code` (unique ISO 639-1, lowercase, 2-char),
  `name`, `native_name` (nullable), `is_enabled` (default true, one-click
  inline toggle + edit-form Toggle), `sort_order`. It is a **standalone**
  registry — nothing else references it yet and the ~100+ hardcoded
  `'en'`/`'ru'` literals stay untouched (ADR 0001). `DatabaseSeeder` now runs
  `LanguageSeeder` first, upserting enabled `en` (English) and `ru` (Russian)
  rows; deletes are free (no protection on the seeded pair, per decision). New
  tests: `Feature/Filament/LanguageResourceTest` (list, create, duplicate-code
  rejection, non-lowercase rejection, edit, inline toggle, delete) and
  `Unit/Models/LanguageTest` (casts, `enabled()` scope, seeder idempotency);
  full suite green, pint clean. Docs: ADR 0001, `CONTEXT.md` gained a Language
  Catalog Context (Language / Language code / Enabled language); `wiki:sync`
  regenerated `reference/models.md`. `generated.at` bumped.

## 2026-08-23

* **Change: simulator AI panel — readable headings, shadow hover, larger base font.**
  Three visual fixes in the AI Response rail. (1) `.ai-prose h1–h4` were pinned
  at `11px` while body text scaled with the user font control, so headings read
  smaller than paragraphs; they now use `1.15em` (mono-caps treatment kept), so
  they stay ~15% above body text at any size. (2) The gloss-run hover
  affordance in `#ai_answer_div` swaps its accent-tinted background fill for a
  crisp accent-tinted `text-shadow: 0 1px 1px` just below the hovered glyphs
  (night variant on `--wbench-accent-night`, transition retargeted to
  `text-shadow`; reduced-motion opt-out kept). (3) `DEFAULT_FONT_SIZE` in
  `Bilinguals.jsx` raised 22 → 26 (`+`/`−` still step ±2 within 12–48).
  Verified in-browser on `/bilinguals/en/ru/simulator`. Docs:
  `wiki/domains/bilinguals-simulator.md`,
  `wiki/conventions/design-system.md` (both `generated.at` bumped).

* **Change: double-quoted phrases in the simulator AI response render red.**
  `Bilinguals.jsx` gained `highlightQuotes()`, slotted into `renderMarkdown()`
  between `marked.parse()` and DOMPurify sanitize: it protects `<pre>`/`<code>`
  blocks with placeholders, wraps straight `"…"` spans (which marked emits as
  `&quot;` entities, so the match is entity-based) in
  `<mark class="ai-quote">` outside HTML tags, then restores them. Styled by a
  new `.ai-prose mark.ai-quote` rule (red text via `--wbench-danger`, night
  variant via `.dark`, transparent background overriding the generic `mark`
  tint) in `public/css/simulator.css`. Verified in-browser on
  `/bilinguals/en/ru/simulator` (light + dark). Docs:
  `wiki/domains/bilinguals-simulator.md` (`generated.at` bumped).

* **Change: profile API-key rows are now two-state (form vs. stored badge).**
  `Profile/Edit.jsx`'s provider row previously always showed the password input
  above Save/Remove buttons with the masked preview tucked underneath. It now
  branches on `apiKeyProviders.*.has_key`: no key → input + icon "Save" button;
  key stored → green "Current key" badge (`masked()`, still `••••`) plus a
  trash-icon remove control (`aria-label="Remove key"`); deleting reveals the
  form again via the existing redirect + Inertia prop refresh. Replacing a key
  is now remove-then-re-add (the inline replace path is gone). Backend,
  routes, and tests unchanged; `ProfileApiKeyTest` green. Docs:
  `wiki/domains/ai-providers.md` (`generated.at` bumped).

* **Change: profile page shows a masked API key preview.**
  `App\Models\UserApiKey::masked()` returns the first four and last four
  characters of the decrypted key separated by `••••`; the profile endpoint now
  exposes this as `apiKeyProviders.*.masked_key`. `Profile/Edit.jsx` renders it
  as read-only text above the password input so users can identify stored keys,
  while the input itself stays empty for replacement. The full key is still
  never echoed. Updated `tests/Feature/ProfileApiKeyTest` to assert the masked
  prop; `wiki/domains/ai-providers.md` updated.

* **Add: per-user AI provider API keys (supersedes ADR 0010).** Each user now
  stores their own encrypted API key per provider in a new `user_api_keys`
  table (`unique(user_id, ai_provider_id)`, `api_key` cast `encrypted`), modeled
  by `App\Models\UserApiKey` (+ factory) with `User::userApiKeys()`,
  `apiKeyForProvider($key)`, `hasApiKeyForProvider($key)` relations/helpers and
  `AiProvider::userApiKeys()`. The `.env` **System key** is retained only for
  non-user paths (model sync, CLI, admin tooling) — there is no System-key
  fallback for user requests. `AIModelResolver::getGroupedModels()` is now
  user-scoped: it lists only providers the authenticated user has a User key for
  (and that are admin-enabled), sorted within provider by price ascending and
  across providers by cheapest model (so the default is the globally cheapest);
  `firstModelKey()` returns that default. `ask()` reads the user's key,
  stamps it onto a cloned provider via `AiProvider::withApiKey()` (the resolver's
  cached template is never mutated), and throws a 403 `AiProviderException` when
  the user has no key. Profile page (`Profile/Edit.jsx`) gained an "AI Provider
  API Keys" section listing every enabled provider with a masked per-provider
  input (never echoes the stored key), independent Save/Remove, and a
  `StoreApiKeyRequest` validating `provider` (enabled, exists) + `api_key`
  (min 10); endpoints `POST/DELETE /profile/api-keys[/<providerKey>]` live on
  `ProfileController`. The simulator shows an empty state linking to the Profile
  when the user has no keys. ADR 0012 records the decision (supersedes ADR 0010,
  which now notes its supersession). `CONTEXT.md` AI Provider Context gained
  User key / System key / Available (to a user) terms; `isConfigured()`
  redefined as a System-key probe. New tests: `Unit/UserApiKeyTest`,
  `Unit/AIModelResolverTest` (rewritten for the user-scoped gate + price sort),
  `Feature/ProfileApiKeyTest`, `Feature/SimulatorApiKeyTest`,
  `Feature/AiQuestionApiKeyTest` (23 tests, full suite green). Docs:
  `wiki/domains/ai-providers.md` (glossary + registry + resolver behavior),
  `wiki/domains/bilinguals-simulator.md` (dynamic default + empty state),
  `wiki/playbooks/add-ai-provider.md` (user-keyed visibility); `generated.at`
  bumped.

* **Add: access-control authorization skeleton (gates + admin bypass + last-admin
  invariant).** `AppServiceProvider::boot` registers a `Gate::before` hook that
  lets an approved admin pass every ability automatically, plus an
  `accessAdminPanel` ability (`isAdmin() && is_approved`) that is the single
  source of truth for both `User::canAccessPanel` (Filament panel access) and the
  frontend admin link. `HandleInertiaRequests` now ships an `auth.can` ability
  map (starting with `accessAdminPanel`) instead of the raw `role` string, and
  `NavBar.jsx` reads `can(...)` rather than `user.role === 'admin'`. A new
  `User` model guard (enforced at the model level so it holds for any entry
  point) refuses to demote/unapprove/delete the last approved admin and refuses
  an admin removing their own admin access; `UserResource` additionally hides
  those actions and disables the `role`/`is_approved` fields for self and for the
  sole approved admin. New `tests/Feature/AccessControlTest.php` (17 tests:
  before-hook behavior, `accessAdminPanel` gate for admin/unapproved/guest,
  `canAccessPanel` delegation, Inertia `auth.can` map, model-level last-admin +
  self-demotion blocks, and Filament UI action/field gating). Docs: new
  `wiki/domains/access-control.md` concept + `log.md`; ADR 0008 records the
  `Gate::before` admin-bypass decision; `CONTEXT.md` gained an Access Control
  Context (Role, Approved, Admin bypass). `generated.at`/`verified` bumped.

* **Add: `ai_providers` table + edit-only admin + seeder; `ai_models` linked by
  FK.** New `ai_providers` table (`App\Models\AiProvider`) is an *enable-flag
  overlay* storing `key`/`name`/`is_enabled`/`description`; the hardcoded
  provider-class maps in `AIModelResolver` and `AiModelSyncRegistry` stay the
  source of truth for *which* providers exist (ADR 0009). `ai_models.provider`
  string column dropped in favour of a nullable `ai_provider_id` FK
  (`nullOnDelete`); sync writes `ai_provider_id` and **ignores
  `ai_providers.is_enabled`** because sync is a catalog mirror, not an
  availability gate (ADR 0011). New `Database\Seeders\AiProviderSeeder` seeds one
  enabled row per registered provider (API keys stay in `.env`, ADR 0010 — the
  admin `AiProviderResource` is edit-only, no Create page, non-secret fields
  only). `AIModelResolver` now skips providers that are not *available*
  (configured AND enabled); `AiProvider::isEnabled()` backs the check. New tests:
  `AiProviderSeederTest`, `AIModelResolverTest` (two-gate), `AiModelSyncTest`
  (FK + sync-invariant), `AiProviderResourceTest` (edit-only + toggle). Docs:
  `wiki/domains/ai-providers.md` gained a Language glossary and registry section;
  ADRs 0009–0011.

## 2026-08-22

did* **Change: AI Models admin enable/disable now fires without a confirmation
  modal.** Removed `requiresConfirmation()` / `modalHeading` / `modalDescription`
  from the `toggleEnabled` record action in `AiModelResource` so Enable/Disable
  toggles `is_enabled` immediately on click. The "Sync models" header action
  still requires confirmation. Added a feature test asserting `callAction`
  toggles `is_enabled` without a modal interception.

* **Add: shared model-sync across all AI providers.** A `ModelSync` contract +
  abstract `AiModelSync` base (fetch / upsert / delete-missing, parameterized by
  `provider()`) plus an `AiModelSyncRegistry` (parallel to `AIModelResolver`,
  guarded by a key-parity test) replace the OpenRouter-only sync. The
  `openrouter:sync-models` command and `SyncOpenRouterModelsJob` are removed;
  `ai:sync-models` (with an optional `--provider=<key>` filter) and
  `SyncAiModelsJob` loop all six registered syncers with per-provider error
  isolation (catch, log, continue). Each provider's catalog endpoint is now
  `services.<provider>.models_url`; a blank value short-circuits the HTTP call
  but still runs delete-missing, so not-yet-wired providers enforce an
  API-only catalog without a network call. All six provider class constructors
  now read their models from `ai_models` (DB) via `forProvider()->enabled()->
  unexpired()` instead of hardcoded arrays — so Gemini/Groq/Cohere/Perplexity/
  HuggingFace temporarily expose no models in the picker until their
  `models_url` is filled and a sync succeeds. The Filament "Sync models" action
  now dispatches `SyncAiModelsJob` (lock key `ai-model-sync`). New tests:
  `EmptyUrlModelSyncTest` (dataset over the five blank-URL providers: returns 0,
  makes no HTTP call, wipes that provider's rows) and `AiModelSyncRegistryTest`
  (key parity). `AiModelResourceTest` switched to the new job/lock key.
  Docs: `CONTEXT.md` ("Models endpoint", "Chat endpoint"), ADR 0007 (supersedes
  the OpenRouter-only flow in ADR 0006). Bumped `wiki` AI-provider concept +
  `log.md`.

## 2026-08-16

* **Add: gloss-run hover affordance in the bilinguals simulator AI panel.**
  Text-bearing elements of the AI answer (the **Gloss**) now signal
  interactivity: a pointer cursor plus an accent-tinted background on `:hover`
  (uniform layering — a nested run's fill paints over its parent block's fill).
  Pure CSS in `public/css/simulator.css`, scoped to `#ai_answer_div .ai-prose`
  (blocks + inline runs; excludes `ul`/`ol` containers and `br`/`hr`), accent
  `color-mix` fill ~14% light / ~22% dark, 150ms `background-color` transition,
  `prefers-reduced-motion` disables the transition. Deviation from the prior
  "no per-line `:hover` background" rule — `conventions/design-system.md`
  amended accordingly; `generated.at` bumped. Docs: `CONTEXT.md` ("Gloss" and
  "Gloss run" terms). No JSX/Vite change (file is served directly).

* **Add: "Needs review" section in the Alignments editor.** `/alignments/{id}`
  now shows a collapsible (collapsed by default) **Needs review** list below the
  unmatched pool, surfacing meaning matches a human should inspect: rows with
  `similarity < 0.55` (`AlignmentEditorApiPresenter::LOW_SIMILARITY_THRESHOLD`)
  or **one-sided** rows (junctions on exactly one side, any similarity — the
  original-completeness repair rows; empty rows are excluded). Backed by a new
  `GET /alignments/{entityMatch}/needs-review` endpoint
  (`AlignmentEditorController::needsReview`, `NeedsReviewRequest`) returning
  `{items, meta}` paginated 25/page; each item carries a page-independent
  **`rank`** (window `ROW_NUMBER() OVER (ORDER BY "order")` over the full
  filtered set, excluding unmatched-pool rows) plus `#order`, `similarity`,
  EN/RU parts, and `one_sided`. Clicking a row jumps the editor's rows table to
  the exact page (`ceil(rank / live per_page)`, matching the user's per-page
  choice) and scrolls + briefly highlights the row (client-side, no URL
  change). The section refetches its current page after every mutation; initial
  page-1 is passed as the `needs_review` prop from `AlignmentController@show` so
  the header count is immediate. Frontend: new
  `resources/js/Pages/Alignments/components/NeedsReviewSection.jsx`,
  `api.js:needsReview()`, `Show.jsx` state/`jumpToRow`/scroll-highlight,
  `PairRow` `data-row-id` + `highlighted` prop. New tests in
  `AlignmentEditorApiTest` (membership incl. high-similarity one-sided rows,
  interleaved rank ordering, 25/page pagination) + guest 401 +
  `AlignmentPagesTest` `needs_review.meta.total` assertion. Docs: `CONTEXT.md`
  ("Needs review" term), `domains/sentence-alignment.md` (Review stage) +
  `database/entities-alignment.md` (single-sided bullet); `generated.at` bumped.

* **Fix: the alignment editor no longer corrupts sentence document order.**
  Dragging a sentence within a row previously rewrote `sentence.order`
  (`assignNewRowSequence` → `setSentenceOrderRaw`), and `spreadOrders`'s
  stride-1024 fallback jumped a dropped sentence's order (e.g. 3 → 1024), so a
  later Re-align (which reads sentences strictly by `order`) placed it at the
  tail against the wrong sentences. The editor now treats `sentence.order` as
  immutable **document order** and rewrites only the within-row **junction
  order** (row-local sparse helpers): `reorderRowJunctions` for within-row
  drags, `link()` via `orderForInsertAfter` for cross-row/unmatched relinks,
  `appendJunctionOrder` for new sentences (junction appends at the row's end;
  the sentence is placed at the row's document boundary via the new
  doc-order `sideAnchorOrder`/`rowRightBoundary`). Display still shows raw
  `sentence.order`. `AlignmentEditorApiTest` strengthened (exact order
  preservation, cross-row/unmatched order immutability) plus a new decoupling
  test. Docs: `CONTEXT.md` (Sentence/Junction), ADR 0005, `wiki` concepts +
  `log.md`; `generated.at` bumped. Existing corrupt orders were left in the DB
  for manual repair.

* **Fix: the 5 pre-existing test failures are gone (full suite green).**
  `tests/Unit/OpenRouterTest.php` was updated to match the current
  `OpenRouter::$models` array (bare `:free` display names are now accepted,
  expected identifiers reflect the active model set from the July AI-model
  cull — commented-out paid models dropped, `nemotron-3-nano-30b-a3b:free`
  renamed to `nemotron-3-super-120b-a12b:free`). `SimulatorEntitySeeder`
  excludes `001_articles.txt` (the local, gitignored corpus file) alongside
  `book_thief_1.txt`. `WiktionaryParser::flushBatch()` now increments
  `words_imported` per batch, so the import stat is no longer stuck at 0.
  Bumped `generated.at` on `domains/dictionary-import.md` and
  `playbooks/import-dictionary-data.md`; dropped the stale "5 pre-existing
  test failures" note from `playbooks/running-tests.md`.

## 2026-08-15

* **Rework: original-side sentence completeness in the aligner.** Completion of
  an entity match now funnels through a single gate
  (`AlignEntitySentences::finalize()`) that enforces the **original
  completeness** invariant: every sentence on the original-text side
  (`is_original_en`) is junctioned into a meaning match, in document order,
  before `status` flips to `completed`. Junction-less original sentences
  (dropped by an empty-commit crawl seam, left over when the translation side
  is exhausted, or skipped by a re-align) are repaired as **single-sided
  meaning matches** (`similarity 0.0`, next machine `alignment_chunk` id),
  ordered positionally via `SparseOrderService::spreadOrders` between the
  neighbouring junctioned anchors. The repair is best-effort (logs a warning,
  never throws). "RU sentences exhausted before EN" is now a normal completion
  (the remaining original tail is drained) instead of an error. New
  `SentenceAlignmentService::storeSkipSentences()` persists single-sided rows;
  the empty-commit seams in `alignWholePool()`/`alignPoolChunk()` skip rather
  than silently drop the first uncommitted original sentence. Updated
  `domains/sentence-alignment.md` + `CONTEXT.md` glossary ("Original
  completeness", "Single-sided meaning match"). Tests in
  `ChunkedEntityAlignmentTest` cover the RU-exhausted drain, RU-original long
  tails, empty-commit seams, position-aware mid-text repair, human-unlinked
  sentences, and empty-side starts. Bumped `generated.at`.

## 2026-08-15

* **Polish: emphasis colors in AI prose + new `--wbench-emphasis` token.**
  Bold emphasis (`b`, `strong`) in the AI gloss/answer — previously painted in
  `--wbench-ink` (near-black, invisible on paper) — is now a theme red. Added
  `--wbench-emphasis` (#B0451E / night #E0664A) to the `@theme` block in
  `laravel/resources/css/app.css` so `--wbench-danger` stays reserved for
  errors. `laravel/public/css/simulator.css`: global `em`/`i` stay
  `--wbench-accent` italic (unchanged) and `b`/`strong` move to
  `--wbench-emphasis`; the scoped `.ai-prose` block adds a full emphasis set
  with dark-mode pairs — `mark` (soft red highlight via `color-mix`),
  `u`/`ins` (accent underline), `del`/`s` (muted ink-soft + red
  line-through), `small`, `sub`/`sup`, `code`/`kbd`/`samp` (mono chip on
  `--wbench-paper-deep`), `blockquote` (accent left border), `q` (accent
  italic). Extras are scoped to `.ai-prose` so global pages that load
  `simulator.css` are untouched beyond the `em`/`i`/`b`/`strong` rules. Bumped
  `conventions/design-system.md` (token table, accent-discipline note, state
  contract row, `.ai-prose` pattern note) + `log.md`.

## 2026-08-15

* **Add: original-text flag on entity matches.** `en_ru_entity_matches` gains
  `is_original_en` (boolean, default `true` = English is the original text)
  so a match records which language the paired text was authored in (the
  other side being a translation). Set via a radio ("Original Text" →
  English/Russian) on the admin create form and the List page "New Alignment"
  action; stored as metadata only (no simulator/pipeline wiring), read-only
  "Original" badge column on the List table. Added the "Original text" term
  to `CONTEXT.md`. Bumped `database/entities-alignment.md` + `log.md`.

## 2026-08-15

* **Fix: re-align runs as one job per pool instead of one long job.** A
  re-align of a match with many landmark-delimited pools previously drained
  every pool in a single `handle()` invocation — a 141-pool match ran one
  ~2m16s job (141 sequential `/align` calls, no cursor progress until the
  end). `AlignEntitySentences::handle()` now persists the cursor via
  `persistOffsets()` and `return`s after **each** whole-pool fast-path align,
  so each pool runs as its own queued job (~10–30s) with offsets persisted
  between pools and crash-resilient resume. Pools larger than `chunk_size`
  on either side still fall through to `alignPoolChunk()` and are drained
  chunk-by-chunk across jobs (unchanged). Updated
  `ReAlignPreservesLandmarksTest` (both re-align tests now drain the
  re-dispatched queue and assert intermediate `aligning` status + offsets +
  dispatch counts). Bumped `domains/sentence-alignment.md` (Plan 08) +
  `playbooks/run-alignment.md` + `log.md`.

## 2026-08-14

* **Polish: page picker in the Alignments editor rows table.** The rows
  `Pagination` component
  (`resources/js/Pages/Alignments/components/Pagination.jsx`) now renders
  numbered page buttons with ellipsis (window of current ±2 plus first/last,
  e.g. `1 … 4 5 [6] 7 8 … 62`) between Prev/Next, so a user can jump to any
  page instead of stepping one by one. The native per-page `<select>` (whose
  number was clipped by Tailwind preflight's `appearance:none` + chevron
  background in some browsers) was replaced by a small custom dropdown button
  (`PerPageSelect`) that always shows the current value and lists the options
  on click — the value is rendered as plain button text, so it can never be
  cut off. Unmatched pools keep their arrows-only paging. Backend already
  supported `page`/`per_page` (`RowsRequest`, `rowsPagePayload`) — no PHP
  changes. Bumped `domains/sentence-alignment.md` + `log.md`.

## 2026-08-14

* **Add: Approve action in the Alignments editor.** Each meaning-match row in
  `resources/js/Pages/Alignments/` gains an **Approve** button (leftmost in the
  row rail, next to Create below / Delete). It calls the new
  `POST /alignments/{entityMatch}/rows/{meaningMatch}/approve` endpoint
  (`AlignmentEditorController::approveRow`), which sets `similarity = 1.0` and
  `alignment_chunk = -1` — promoting the row to a hard human landmark, exactly
  the tier that survives re-align (Plan 08/10). Optimistic UI update + revert
  on error, matching the create/delete pattern. New tests in
  `AlignmentEditorApiTest` (sets similarity 1 + landmark marker; 404 for a row
  of another match; guests 401). Updated `domains/sentence-alignment.md` +
  `log.md`.

## 2026-08-14

* **Add: landmark tiers concept (Plan 10 close-out).** Consolidated the two
  classes of meaning-match rows that survive a re-align as explicit
  **landmark tiers** in `domains/sentence-alignment.md`: **hard** (human-made,
  `alignment_chunk = -1`, `similarity = 1.0` — never deleted, rolled back, or
  re-aligned) vs **auto** (machine rows with
  `similarity >= LANDMARK_THRESHOLD` 0.90, promoted to landmarks on
  re-align). Both feed
  `landmarkRows()`/`landmarkBounds()` and act as pool boundaries; only the
  delete scope of Re-align (machine rows below the bar) vs "Run from scratch"
  (both tiers) differs. `database/entities-alignment.md` gained the same
  markers under its invariants. Bumped both concepts' `generated.at` +
  `log.md`.

## 2026-08-14

* **Add: split Filament re-run actions (Plan 09).** The single destructive
  "Re-run" action on `EnRuEntityMatchResource` (list table) is replaced by two
  explicit confirmation actions, both visible only on `status ∈
  {completed, failed}`: **Re-align** (`realign`, warning, `heroicon-o-arrow-path`)
  calls `AlignEntitySentences::begin()` and preserves human-made rows
  (`alignment_chunk = -1`) plus confident landmarks
  (`similarity >= AlignEntitySentences::LANDMARK_THRESHOLD`), re-aligning only
  the low-confidence gaps — its modal reports the live preserved counts;
  **Run from scratch** (`rerunScratch`, danger, `heroicon-o-trash`) calls
  `beginFromScratch()`, deletes ALL meaning matches including human-made ones,
  and states so in its modal (adding the human-made count when > 0).
  `LANDMARK_THRESHOLD` made `public` so the resource can reference it. New
  `FilamentReAlignActionTest` (3 tests: per-status visibility, realign dispatch
  + modal copy, scratch dispatch + modal copy). Updated
  `domains/sentence-alignment.md` + `playbooks/run-alignment.md` + `log.md`.

* **Add: landmark-aware re-align (Plan 08).** `AlignEntitySentences` gains a
  fresh-alignment entry point `beginFromScratch()` (the old `begin()`:
  verify, wipe every meaning match, snapshot totals, raise small entities to
  one chunk, dispatch) while the Filament "Re-run" action switches to the new
  landmark-aware `begin()`: it deletes only machine rows below
  `LANDMARK_THRESHOLD` (0.90), preserving human rows (`alignment_chunk = -1`)
  and high-confidence auto-landmarks, resets the cursor, and re-dispatches
  (delegating to `beginFromScratch()` when the match was never set up).
  `handle()` is now pool-aware: `landmarkRows()`/`landmarkBounds()` carve
  non-overlapping pools around landmarks (1:N landmark spans merge, bounds
  clamp to snapshot totals), each aligned independently so pins are never
  crossed; the whole-pool fast path is taken only when a pool fits one window
  and no rollback candidates remain inside it, otherwise the chunk path with
  seam rollback (`rollbackCandidates()`: machine rows junctioned on both
  sides in the pool, last 2) applies. Fresh-alignment callers
  (`CreateEnRuEntityMatch`, `ListEnRuEntityMatches`, `alignments:resume`,
  `EnEntityResource`/`RuEntityResource` buttons) moved to `beginFromScratch()`.
  New `ReAlignPreservesLandmarksTest` (3 tests); rollback seeds in
  `ChunkedEntityAlignmentTest` lowered 0.9 → 0.8 so they fall under the
  landmark bar. Updated `domains/sentence-alignment.md` +
  `playbooks/run-alignment.md` + `log.md`.

* **Add: alignment landmark passthrough (Plan 07).** The PHP alignment client
  now accepts the Python knobs introduced in plans 02–06:
  `SentenceAlignmentService::alignChunkRemote()` gained optional
  `array $landmarks = []` and `?float $highConfidence = null` parameters and
  passes them to `/align` as `landmarks` (list of `{en_start, en_end,
  ru_start, ru_end}` ints) and `high_confidence`. When neither is given the
  payload is byte-identical to the previous shape — no `landmarks`/
  `high_confidence` keys are sent — so existing callers are untouched.
  `AlignEntitySentences` reserves `LANDMARK_THRESHOLD = 0.90` (inert until
  plan 08 wires human-edited rows in as pins on re-align). New feature tests
  assert the keys appear in the payload when passed and are omitted when not.
  Updated `domains/sentence-alignment.md` + `log.md`.

* **Add: hard landmark pins on `/align` (Plan 06).** `BilingualAligner`
  `align_lists`/`_align_pair` now accept and honor `landmarks: list[dict]`
  (`{en_start, en_end, ru_start, ru_end}` — indices into the submitted lists).
  New module-level `_validate_pins` rejects (ValueError → 422 via
  `api/align.py`) zero-length, out-of-range, and crossing/overlapping pins
  (sorted by `en_start`, any pin whose EN or RU span intersects the previous
  pin's is rejected — sharing a sentence is contradictory). `_prepass_anchors`
  skips cells inside a pin's rectangle (`range(en_start,en_end) ×
  range(ru_start,ru_end)`), and `_align_with_anchors` builds the sub-pool
  boundaries from the union of pins + prepass anchors: pins delimit the
  top-level gaps, only anchors lying entirely inside a gap split it further, so
  machine output can never cross or overlap a pin **by construction** (pools
  sit strictly between boundaries). Pins are emitted verbatim with score 1.0 in
  document order; unmatched lists exclude pinned indices automatically. Honored
  in both greedy and dp. The request schema uses a new `AlignLandmark` model
  (four int spans, no `score` — pins are always 1.0), so `AlignRequest.landmarks`
  is no longer `list[AlignMatch]`; `api/align.py` converts pins to dicts and
  translates ValueError → 422. Verified live: a 1:2 pin honored by `dp`, a 1:1
  pin emitted verbatim at score 1.0, out-of-range/crossing/zero-length pins all
  → 422 with clear messages, and landmark-less requests unchanged (200).
  New stub tests in `test_aligner.py` (pin emitted verbatim score 1.0 both
  algorithms; prepass skips pinned cells + no machine match overlaps a pin;
  pinned indices excluded from unmatched; invalid pins rejected). All 29 checks
  pass; `.env.example` comments un-staled (knobs now applied). Updated
  `domains/sentence-alignment.md` + `log.md`.

* **Add: per-sentence embedding aggregation for multi-sentence windows
  (Plan 05).** `BilingualAligner` gained `_sentence_vectors`, `_aggregate_window`,
  and the `_window_vector` routing point; `_generate_window_embeddings` and
  `_embed_windows` now hand every window to `_window_vector`, which dispatches
  on the live `window_embed` knob (`ALIGN_WINDOW_EMBED`, default `aggregate`,
  coerced from anything not `aggregate|joined`). `aggregate` (default) embeds
  each single sentence once through the shared cache (key = model id +
  normalized single text — the same key the prepass step-1 windows use) and
  builds each window vector as a length-weighted, L2-normalized mean of its
  sentence vectors (`_aggregate_window`: weights = window sentences' character
  counts; step-1 reduces to the normalized single) — so no joined window text
  is ever embedded and encode count ≈ `n + m` regardless of banding or window
  expansions. `joined` keeps the legacy join-then-embed (cached by joined
  text; one encode per window text on miss). `EmbeddingCache` docstring updated
  to the single-vs-joined key semantics. Equal-length window sentences
  reproduce old pooled-mean scores exactly; differing lengths shift toward the
  longer sentence. New stub regression tests in `test_aligner.py`: aggregate
  ranks the correct 1:2 fusion window highest; weights follow sentence lengths
  (unit `_aggregate_window` check + end-to-end 2:1); per-sentence vectors
  cached (counting stub, pooled window adds zero encodes); joined-mode smoke
  (4 texts). Existing tests updated: window encode counts drop under aggregate
  (1:2/1:3/1:4 ladder 8→5, orphan-merge 4→3, span-cap 1:2 4→3), the
  banding test now asserts aggregate is band-independent (7/7) and keeps the
  joined 11-vs-16 variant, and three pooled-window score asserts converted to
  `assert_same_matches` (aggregate float32 arithmetic differs from the stub's
  numpy normalization in the last bits). All 25 checks pass; LaBSE smoke on
  `testen.txt`/`testru.txt` (greedy, 20 lines each): aggregate 9 matches mean
  0.624, joined 10 matches mean 0.571. Updated
  `domains/sentence-alignment.md` (Plan 02 `window_embed` now consumed;
  embedding-cache bullet updated to the new key semantics; new Plan 05
  section) + `log.md`.

## 2026-08-14

* **Add: diagonal banding of per-sub-pool match edges (Plan 04).**
  Per-sub-pool match edges are restricted to a diagonal band around the
  expected length-ratio line: `k = len(sub_en)/len(sub_ru)` per pool, cell
  `(i, j)` in-band when `abs(j*k - i) <= band` (`_band_allowed`); the
  half-width comes from the live `band_width` knob (`ALIGN_BAND_WIDTH`,
  default unset → derived per pool as `max(2, max_window)`, `_resolve_band`).
  DP (`_align_chunk`) gates match edges on their start cell (skip edges stay
  unbounded) and embeds only windows whose start positions appear in some
  in-band cell (`_in_band_starts` → `_generate_window_embeddings(sentences,
  starts)`), so off-band windows never reach the model. Greedy
  (`_align_chunk_greedy`) bands its internal `anchor_threshold` anchors
  (`_find_anchors(sim, n, m, threshold, k=None, band=None)`), the gap window
  ladder (`_best_window_pair`, gated on window centers, in-band-only step
  filtering), and the skip decision (`_should_skip_en` clamps lookahead slices
  to in-band cells; both-axes-out tiebreak `return j * k > i` walks back
  toward the diagonal). Prepass anchors stay deliberately unbanded (they can
  exist anywhere on the full singles matrix). The DP path no longer
  precomputes the full chunk's `(n + m) * max_window` windows: `_align_pair`
  embeds only the chunk's singles for the prepass matrix, and each pool embeds
  only its own in-band windows through the cache (clean list → exactly `n + m`
  encodes, matching greedy). Five new stub regression tests in
  `test_aligner.py` (out-of-band pair rejected tight / accepted wide; in-band
  pair at the band edge; recovery to the far diagonal pair across a divergent
  middle; `band_width` knob controls match density with dp == greedy;
  banding suppresses 5 out-of-band window embeddings on a 6×1 chunk); the
  existing DP encode-count test now asserts `dp == greedy == 6` singles; all
  21 checks pass. Updated `domains/sentence-alignment.md` (Plan 02
  `band_width` now consumed; Plan 03 DP-window cost text corrected; new Plan 04
  section) + `log.md`.

## 2026-08-14

* **Add: high-confidence 1:1 prepass anchors (Plan 03).**
  `BilingualAligner._align_pair` now embeds the chunk's single sentences once,
  locks prepass anchors — non-crossing, mutually-best 1:1 cells at/above
  `high_confidence` (`ALIGN_HIGH_CONFIDENCE`, default 0.9) via the shared
  `_find_anchors(sim, n, m, threshold)` core (greedy's internal
  `anchor_threshold` anchors still call it with `anchor_threshold`; the new
  `_prepass_anchors` wraps it with `high_confidence`) — and dispatches
  `_align_with_anchors`, which splits the chunk into sub-pools at the anchors
  and aligns each pool in isolation with the chosen algorithm (greedy gap
  alignment or per-pool DP reusing the precomputed window embeddings through
  the cache), emitting the anchors as committed 1:1 matches with their cell
  cosine scores in strict document order. The DP path precomputes the full
  chunk's window embeddings once (unchanged cost) and derives the prepass's
  singles matrix from its step-1 submatrix; the greedy path embeds the singles
  once and reuses them per pool. No match can consume sentences on both sides
  of a locked pair. Three new stub regression tests in `test_aligner.py`
  (identical anchor set for greedy and dp on ≥0.9 pairs; pools stay between
  anchors in document order; lowering `high_confidence` locks more anchors);
  all 13 prior checks pass unchanged. Updated
  `domains/sentence-alignment.md` (Plan 02 section now notes `high_confidence`
  is consumed by plan 03; new Plan 03 section) + `log.md`.

## 2026-08-14

* **Add: reserved alignment knobs plumbed end-to-end (Plan 02, no behavior yet).**
  Three new live config accessors in `ai/config.py` —
  `align_high_confidence()` (`ALIGN_HIGH_CONFIDENCE`, default 0.9),
  `align_band_width()` (`ALIGN_BAND_WIDTH`, default unset → derived later as
  `max(2, max_window)`), `align_window_embed()` (`ALIGN_WINDOW_EMBED`, default
  `aggregate`, coerced from anything not `aggregate|joined`) — plus four new
  optional `/align` request fields on `AlignRequest` (`high_confidence`
  `[0,1]`, `band_width` `[1,50]`, `window_embed` pattern `^(aggregate|joined)$`,
  `landmarks: list[AlignMatch]` default `[]`). `api/align.py` passes them to
  `BilingualAligner(...)`; the constructor resolves `None` from config and
  stores them as `self.high_confidence` / `self.band_width` /
  `self.window_embed` (unused until plans 03/04/05/06). `AlignMatch` moved above
  `AlignRequest` so `landmarks` can reference it. Verified: all 13 stub aligner
  tests pass unchanged, schema round-trips the new fields, invalid
  `window_embed` / out-of-range bounds → 422, and a full `/align` request with
  the new fields returns 200 (real LaBSE). `.env.example` documents the new
  keys; updated `domains/sentence-alignment.md` + `log.md`.

## 2026-08-14

* **Refactor: python normalizes every sentence once at alignment entry.**
  `BilingualAligner._align_pair(en_raw, ru_raw, landmarks=None)` is now the
  single normalization point for alignment: it runs the new module-level
  `_normalize_sentences` (casefold + alnum/space only + collapsed whitespace,
  index-preserving) on both lists, then dispatches to greedy/dp on the
  normalized forms. `align_lists()` and the demo/`process()` path both route
  through it, and the internal window code (`_window_text`, `_embed_windows`,
  `_generate_window_embeddings`, `_generate_sentence_embeddings`,
  `_best_window_pair`, `_merge_orphans`, `_should_skip_en`) no longer calls
  `_normalize_text` — it operates on the pre-normalized list, so joining
  pre-normalized sentences *is* the normalized window text and the
  embedding-cache keys (raw and pre-normalized input hash to the same keys;
  `_match`'s diagnostic text now shows the same normalized form in both DP and
  greedy). Normalization is idempotent, so existing inputs align
  byte-identically (verified: 11 prior stub tests pass unchanged, real-model
  `process()` smoke on LaBSE both algorithms). `landmarks` is accepted on
  `_align_pair` as the reserved seam for the landmark-pins API (Plan 06). New
  stub tests: `_normalize_sentences` contract + the whistler pair scoring
  ≥ 0.68 in its normalized form, and raw vs pre-normalized input aligning
  identically (0 extra encodes). Updated `domains/sentence-alignment.md` +
  `log.md`.

## 2026-08-13

* **Fix: greedy 1:1 anchors pre-commit past a better pooled window, leaving
  orphans.** Root cause (verified on the real LaBSE aligner): the default
  greedy is anchor-first, so a 1:1 that clears `ALIGN_ANCHOR_THRESHOLD` and is
  mutually-best in its window is locked before the window ladder ever runs at
  that cursor — yet the pooled multi-sentence window can outscore the 1:1. On
  the repro chunk EN0↔RU0 locked at 0.701, the cursor jumped to (1,1), RU was
  exhausted, and the trailing "Where are those damn scissors?" stayed
  unmatched, while the pooled EN0:2↔RU0 scored 0.743 (the DP, which considers
  all edges, gets it right — the bug is greedy-specific). **Rejected fix:** the
  earlier idea of making `_find_anchors` reject an anchor when any pooled
  window beats the 1:1 degraded real loosely-aligned text (outputen/outputru:
  52/52 matches dropped 50 → 20–42, orphans 2 → 8–30) — anchor locking does
  real reliability work. **Shipped fix:** a greedy-only orphan-merge post-pass.
  `BilingualAligner._merge_orphans()` runs at the end of `_align_chunk_greedy`,
  walks the matches left-to-right, and for each match whose following gap has
  orphans on exactly one side (the other side consumed) extends the match's
  window over the orphan run — every extension length up to the bound
  (`max_window`, `ALIGN_MAX_TOTAL_SPAN`) is lazily embedded and scored, and
  the best pooled window replaces the match only if it beats its score by
  `ALIGN_MERGE_MARGIN` (new live knob, default 0.02; config accessor
  `align_merge_margin()`, `.env.example` updated). Measured: the repro becomes
  a correct 2:1 (0.7433), 0 unmatched, +2 encodes; real text keeps all 50
  matches, orphans 2 → 1 (a genuine merge), +4 encodes; all 10 prior stub
  regression tests pass unchanged (no encode-count regressions — it only fires
  on orphans). New stub test
  `greedy_merges_an_orphan_into_a_beating_pooled_window` (2 EN / 1 RU where
  the pooled 2:1 beats a bar-clearing 1:1 → merged, no unmatched, 4 encodes;
  plus a below-margin guard case where the anchor survives). Real-model repro
  re-run on LaBSE: EN ["We are alive, the four of us.", "Where are those damn
  scissors?"] vs single-RU confirms 1:1 at 0.7002 with merge off (orphan left)
  → 2:1 at 0.8088 with merge on (no unmatched). No PHP changes — the job
  consumes the unchanged `matches` payload; `ChunkedEntityAlignmentTest` +
  `SentenceAlignmentServiceTest` (83 alignment tests) pass. Updated
  `domains/sentence-alignment.md` + `log.md`.

* **Fix: The whistler ↔ «СВИСТУН» alignment misses.** LaBSE scores the raw
  pair 0.32 — below `ALIGN_DEFAULT_THRESHOLD` 0.55 — so it was skipped;
  lowercase + alnum-only ("the whistler" ↔ "свистун") scores 0.685. Root cause
  was normalization, not the algorithm. `BilingualAligner` now embeds every
  window through `_normalize_text` (casefold + keep only alphanumerics/
  whitespace, unicode-aware + collapse whitespace), applied in both the DP
  (`_generate_window_embeddings`) and greedy (`_embed_windows`) paths — and the
  embedding cache is keyed on the normalized joined text, so DP/greedy share
  keys. Stored DB text is untouched (PHP persists raw sentences; only
  `_match`'s diagnostic text shows the normalized form). **Also fixed the
  greedy "forced 1:1":** `_align_gap` no longer commits a 1:1 the moment it
  clears the bar — it always runs `_best_window_pair`, which is now a ladder
  (`ALIGN_PRIMARY_WINDOW`, default 3): steps 1..primary compared as one set,
  highest-scoring combo above threshold wins, then widen one step per side up
  to `max_window` (still bounded by `ALIGN_MAX_TOTAL_SPAN`). Verified live via
  `/align` (1:1 at 0.685; raw 0.320 vs normalized 0.685 measured directly on
  LaBSE) and `test_aligner.py` (10 checks, incl. new whistler and 1:4-widen
  ladder tests). Updated `domains/sentence-alignment.md` + `log.md`.

* **Calibrate: LaBSE aligner thresholds re-lowered 0.75/0.8 → 0.55/0.6.**
  The Aug-13 LaBSE switch set `ALIGN_DEFAULT_THRESHOLD = 0.75` /
  `ALIGN_ANCHOR_THRESHOLD = 0.8` (provisional, sanity-checked on the
  `testen.txt`/`testru.txt` smoke test which scored 0.768–0.965, mean 0.833).
  A real-world check on adapted learning texts showed genuine meaning matches
  scoring well below that: The Gamblers titles (THE GAMBLERS ↔ ИГРОКИ) score
  0.59 and the full parenthetical titles 0.67 under LaBSE (measured via `/align`
  on the running service, live config). At 0.75 those genuine matches were
  silently skipped by the aligner. Re-lowered `ALIGN_DEFAULT_THRESHOLD` to
  **0.55** (the established MiniLM garbage-floor calibration from the Aug-11
  histogram; LaBSE runs hotter than MiniLM so 0.55 stays conservative) and
  `ALIGN_ANCHOR_THRESHOLD` to **0.6** in `docker-compose/python/env/.env` (live,
  no restart; verified `config.align_default_threshold()` reads 0.55) and synced
  `.env.example`. `/align` on the two title pairs now commits both matches
  (0.588, 0.668). Final numbers still to be refined from a real
  `meaning_match.similarity` distribution once a live corpus is aligned.
  Updated `domains/sentence-alignment.md` (calibration notes + `generated.at`)
  and `log.md`.

* **Improve: LaBSE as the aligner model (fast-defaults calibration).**
  Swapped the `/align` model from MiniLM to **LaBSE**
  (`sentence-transformers/LaBSE`, 768-dim) for higher bilingual alignment
  quality. Downloaded into the `ai_models` volume via
  `docker exec -e HF_HUB_OFFLINE=0 ext_python python /app/scripts/download_model.py
  sentence-transformers/LaBSE /app/models/labse`, activated live by setting
  `ALIGN_MODEL_PATH=/app/models/labse` in `docker-compose/python/env/.env`
  (and `.env.example`); the next `/align` request lazy-loaded it (dim=768 in
  logs) with no rebuild/restart, `MODEL_PATH=/app/models/bge_m3` (signatures)
  unchanged. Verification: the `test.py` sample against LaBSE gives
  b↔c = 0.81 > a↔c = 0.39 (MiniLM gives 0.43 for the same translation pair,
  so LaBSE runs markedly hotter); `/health` still reports dim=1024; a `/align`
  smoke test on `testen.txt`/`testru.txt` returned 16 matches scoring
  0.768–0.965 (mean 0.833), validating the new thresholds. **Calibration
  (provisional):** `ALIGN_DEFAULT_THRESHOLD` 0.55 → **0.75** and
  `ALIGN_ANCHOR_THRESHOLD` 0.6 → **0.8** (LaBSE bitext cosine runs hot vs
  MiniLM's 0.55/0.6); sanity-checked on the test pair, to be refined from a
  real `meaning_match.similarity` distribution once a live corpus is aligned.
  **Token cap audit:** LaBSE caps at 256 tokens (`max_seq_length=256`);
  `testen.txt` had one 3-sentence window at 270 tokens and it is silently
  truncated to the head 256 (tail dropped, warning only, no crash) — noted in
  `domains/sentence-alignment.md` as a known quality caveat for long-sentence
  joins. `test_aligner.py` (8 checks, stub model) and `test_splitter.py` pass
  unchanged. Updated `domains/sentence-alignment.md` (aligner-model section +
  threshold/calibration notes) and `log.md`.

## 2026-08-12

* **Improve: alignment speed — anchor-first greedy algorithm.** With BGE-M3 as
  the aligner (`ALIGN_MODEL_PATH=/app/models/bge_m3`), the full-window DP in
  `bilingual_aligner.py` embedded `(n + m) * max_window` window texts per
  `/align` call (~1260 encodes ≈ 4 min for a 100-sentence entity at
  `max_window=6`). The new **greedy** mode (default, `ALIGN_ALGORITHM=greedy`;
  `dp` restores the old DP) embeds each single sentence once per side, locks
  confident non-crossing 1:1 anchors (`ALIGN_ANCHOR_THRESHOLD`, default 0.6)
  from the n×m sentence matrix, and greedily aligns only the gaps between
  anchors — lazily embedding multi-sentence windows (`1:2`, `2:1`, `2:2`, …
  bounded by `max_window` and `max_total_span`) only when the cursor's 1:1 is
  sub-threshold, and skipping the side whose best 1:1 partner is weaker. The
  DP's skip/unmatched semantics and the `matches` payload shape are unchanged,
  so no Laravel-side changes were needed (the job's anchor-trim/rollback still
  works). Embedding count drops to ≈ `n + m` singles plus a few expansions per
  messy cursor (~6× fewer for a clean text). `/align` accepts optional
  `algorithm`/`anchor_threshold` overrides; `dp` path and all its behavior are
  intact behind the knob. Regression tests in
  `docker-compose/python/ai/alignment/test_aligner.py` (stub model, asserts
  encode counts and greedy/DP equivalence, 1:2 expansion, skip, anchor+gap).
  Updated `domains/sentence-alignment.md` stages 3/4.

## 2026-08-11

* **Fix: pysbd merging curly-quote dialogue blocks into one sentence.**
  Book dialogue lines close with curly `”` (U+201D), which was missing from
  `selective_flatten`'s keep-set (`".!?…»\"')"`), so the newline after each
  line — and a blank line's `\n\n` — collapsed to one/two spaces. pysbd masks
  `.`/`?` inside `“...”` spans and re-splits at `[!?.-]` + closing quote +
  exactly one space + uppercase; the two collapsed spaces broke that re-split,
  so the whole dialogue block plus the next unquoted prose sentence merged
  into one 464-char "sentence" (entity 10: 5952 sentences incl. the monster).
  `selective_flatten` now keeps newlines after `”` (U+201D) and `’` (U+2019)
  too. Entity 10 re-split into 6814 clean sentences (max 575, a pre-existing
  pysbd em-dash/quote limitation unrelated to this bug). Regression test
  `test_splitter.py` gained a `check_curly_quote_dialogue` guard. Updated
  `domains/sentence-alignment.md` stage 1.

* **Fix: pysbd splitting hard-wrapped prose into sentence fragments.**
  The RU "Книжный вор 2" source is hard-wrapped (~70 col lines, single
  newline per line, no blank lines between paragraphs). pysbd treats every
  `\n` as a sentence boundary, so "Когда Лизель оглядывалась … оказывались"
  and "едва ли не самыми яркими воспоминаниями." came out as two sentences
  (9926 total). `TypedSentenceSplitter` now selectively flattens buffered
  prose before segmentation: `selective_flatten` collapses a `\n` to a space
  unless the preceding char is sentence-ending punctuation (`. ! ? … » " ' )`),
  preserving real paragraph breaks so pysbd's quote-region heuristic still
  cannot merge long dialogue spans (EN Book Thief stays ~1036 sentences).
  The entity re-split into 7148 real sentences (max 277 chars, 189 titles
  preserved). Regression test `test_splitter.py` now covers both the EN
  quote-region fixture and the RU hard-wrap fixture (fails under either
  extreme behavior). Updated `domains/sentence-alignment.md` stage 1.

* **Improve: aligner precision & speed — skip branch, span cap, embedding
  cache.** The python DP force-aligned every sentence, so a sentence with no
  counterpart was matched to an unrelated window (a <0.6 garbage meaning
  match). `BilingualAligner` now supports skip edges (consume EN-only or
  RU-only sentences, reported via `unmatched_en`/`unmatched_ru`, default
  `ALIGN_SKIP_PENALTY=-0.5`/sentence), a span cap (default
  `ALIGN_MAX_TOTAL_SPAN=6` rejects 1:5/5:1 edges), and a process-level
  embedding cache (`EmbeddingCache`, LRU 10k) so chunk-seam and shared-entity
  windows are not re-encoded. `ALIGN_DEFAULT_THRESHOLD` raised 0.4 → 0.55
  (MiniLM calibration against a live `meaning_match.similarity` histogram:
  garbage tail below ~0.55). `AlignEntitySentences::begin()` raises small
  entities to a single chunk. The php gap-filling already converts skipped
  spans to `skip_en`/`skip_ru` steps, so no persistence change was needed.
  Regression tests: `docker-compose/python/ai/alignment/test_aligner.py`
  (stub model, no weights). Updated `domains/sentence-alignment.md` stages 3
  + python microservice.

## 2026-08-11

* **Fix: pysbd quote-region swallowing dialogue during split.**
  The python splitter flattened line breaks to spaces before segmentation
  (`SentenceSplitter::split_text` normalized input; `TypedSentenceSplitter`
  joined buffered lines with `" "`), which let pysbd's quote heuristic merge
  long dialogue spans: a line ending in a stray `"` opened a quote region that
  swallowed everything up to the next closing quote. The Book Thief entity
  (en) split into 547 sentences including one 1761-char monster (15 sentences
  > 500 chars). Now `split_text` segments with line breaks intact and
  `flush_buffer` joins lines with `\n`; the entity re-split into 1036 clean
  sentences (max 222). Added regression test
  `docker-compose/python/ai/splitting/test_splitter.py` running the splitter
  over the exact production entity fixture (fails under the old behavior,
  passes now). Updated `domains/sentence-alignment.md` stage 1.

* **Fix: split jobs dying on malformed UTF-8 at chunk seams.**
  `SplitEntityFileSentences` failed for RU entities with Guzzle's
  `json_encode error: Malformed UTF-8 characters` when an `fread` chunk ended
  mid-multibyte-character. The old guard relied on `mb_strcut($chunk, 0,
  strlen($chunk), 'UTF-8')`, which does **not** strip an incomplete trailing
  sequence (it returns the chunk unchanged), so `$rawCarry` was always empty
  and the partial leading byte flowed into the JSON POST body.
  `SentenceSplitter::insertSentencesFromFile()` now trims incomplete trailing
  UTF-8 bytes with `carryIncompleteTrailingBytes()` (a backward scan of the
  last ≤4 bytes) and re-prefixes them to the next chunk. Regression test
  added with Cyrillic text whose character lands exactly on a chunk seam.

## 2026-08-10

* **Fix: chunk-seam head garbage by commit-rollback of the last two
  matches.** Trim-to-last-anchor (above) only drops the *tail* of the current
  chunk; the strict 1:1 RU window gives the DP no backward reach, so the
  garbage re-appears at the *head* of the next chunk (a 1:5 span that scores
  as an anchor and survives trim). `AlignEntitySentences::handle()` now
  rolls back the last `ROLLBACK_MATCHES` (2) committed meaning matches
  before aligning: `rollbackPriorMatches()` deletes their rows (junction
  rows cascade via FK), rewinds the cursor to the first sentence those
  matches covered via `rollbackOffset()` (position in the `order, id`
  sequence), and grows `en_limit`/`ru_limit` by the rolled-back spans so the
  forward reach is unchanged — the DP re-aligns that region with fresh
  forward context. Only matches with junction rows on **both** sides are
  candidates (skip steps and human-edit `alignment_chunk = -1` rows are
  never rolled back). A monotone-cursor safety net force-advances EN past
  the pre-rollback stored offset if a rolled-back commit would otherwise
  not move forward, so the job cannot pinwheel. Each match is rewritten at
  most twice before stabilizing (bounded churn, no schema change). Updated
  ADR 0004 and `domains/sentence-alignment.md` stage 3.
* **Fix: chunk-seam garbage (5:1 / 1:5 mis-pairs) by trim-to-last-anchor.**
  The reworked self-restarting job advanced its cursor by the full chunk
  size and committed every DP match, but the DP force-aligns every sentence
  in the window — so at each seam the EN tail got jammed onto the RU tail
  of the current window (5:1) and the next window's head did the reverse
  (1:5). `AlignEntitySentences::handle()` now commits only matches up to
  and including the last match with `score >= ANCHOR_SCORE_THRESHOLD`
  (0.40) and advances the cursor to that anchor's `en_end`/`ru_end`
  instead of `offset + chunk_size`; the dropped tail is re-aligned with
  fresh context by the next invocation (a port of
  `BilingualAligner._trim_to_last_anchor`). No-anchor chunks commit
  everything (forward-progress guarantee); the final chunk (both windows
  reach their totals) commits everything and completes. Because the cursor
  no longer advances by a fixed chunk size, `alignment_chunk` is now a
  monotonic per-run id (`MAX + 1`, never the human-edit `-1` sentinel) via
  `nextAlignmentChunk()` instead of `intdiv(enOffset, chunkSize)`, so the
  per-chunk idempotent delete can never wipe a previous chunk.
  `alignChunkRemote()` returns the raw python `matches` alongside the
  adapted links/dpPath; `storeAlignmentSegmentFromMatches()` persists only
  the committed prefix (skip-fill bounded by the last anchor, or by the
  full window on the last chunk). `storeAlignmentSegment()` kept for the
  legacy full-alignment path. Updated ADR 0004 (drift handled by trim, not
  overlap) and `domains/sentence-alignment.md` stage 3.
* **Rework: alignment pipeline is now a single self-restarting job driven
  by a 5-minute command.** `AlignEntitySentences::handle()` reads its chunk
  slice from a new per-match cursor (`last_en_sentence_offset` /
  `last_ru_sentence_offset` on `en_ru_entity_matches`) and
  `self::dispatch()`es the next invocation until the cursor reaches
  `en_total_sentences`, then flips to `completed`. The standalone
  `AlignEntitySentenceChunk` job and the `Bus::chain()` fan-out are deleted
  (ADR 0003). Verify + cursor reset + transition run in a shared
  `AlignEntitySentences::begin($id)` static called by all Filament dispatch
  sites and a new `alignments:resume` console command (scheduled
  `everyFiveMinutes()->withoutOverlapping()` in `routes/console.php`). On a
  transient chunk failure `tries=5` with backoff heals in-process; on a hard
  failure `failed()` is terminal and the cursor is **left untouched** so a
  resume is possible. `db_path` column is dropped (it was dead — read by
  nothing). `entity_similarity` is now set by `begin()` rather than the
  chunk chain. `DB_QUEUE_RETRY_AFTER=900` shipped in `.env.example`.
* **Rework: RU alignment window now tracks EN strictly (no overlap).**
  `AlignEntitySentences::RU_WINDOW_OVERLAP = 25` and `ruWindowForEnRange()`
  are deleted. The RU window uses the same offset and limit as the EN
  window, assuming the importer pins EN/RU sentence orders 1:1 (ADR 0004).
  Filament status badge colors and the Alignment view Blade template no
  longer reference the `verifying` state; the status set is
  `pending | aligning | completed | failed`.

## 2026-08-09

* **Fix: alignments editor sentence keys are now language-scoped.**
  `AlignmentEditorApiPresenter::sentencePayload` emitted keys of the form
  `s-{id}` ignoring language; since `en_entity_sentences` and
  `ru_entity_sentences` are separate tables their ids can overlap, so the
  Inertia editor's shared sentence lookup (keyed by that key) let a RU
  sentence clobber an EN sentence with the same id — rendering Russian text
  in the EN column ("ru/ru"). Keys are now `{lang}:s-{id}` (e.g. `en:s-6776`),
  making them unique across the whole drag-and-drop surface. No schema change.

* **Alignments editor (Inertia/React) replaces the Filament entry point for
  pair editing.** New `/alignments` (pair list) → `/alignments/{id}` (editor)
  pages in `resources/js/Pages/Alignments/`, linked from the NavBar instead of
  `/admin/sentence-alignments`. The editor shows paginated EN/RU pair rows with
  @dnd-kit drag-and-drop, inline add/edit/delete, an "Unmatched" pool
  (collapsible, 15/lang, draggable in/out, trash = permanent delete), and
  immediate persistence (optimistic updates reconciled from server responses,
  revert + error banner on failure). Backed by the existing surgical
  `AlignmentEditorController` endpoints (rows/unmatched pagination, create/
  delete pair, add/edit/unlink/hard-delete sentence, `sentences/move`).
  `AlignmentEditorApiPresenter::unmatchedPayload` now nests `{items, meta}`
  including `last_page`; `AlignmentController::index` passes the paginator
  `items()` as a plain array so Inertia props stay a list. Deleted the legacy
  blade `resources/views/alignments/*` + `resources/js/pages/alignments/*`
  (incl. vite entries). Added `@dnd-kit/core|sortable|utilities` to
  `package.json`. New `AlignmentEditorApiTest` (20 cases: pair CRUD, add
  placement, within-row reorder, cross-row relink, unmatched moves, trash,
  pagination, counts) and `AlignmentPagesTest` switched to `assertInertia`
  prop assertions. Filament admin pages untouched.

## 2026-08-06

* **Redesign: Crossword workbench migrated to the wbench design system.**
  Rebuilt `/crossword-react/{lang}` (`CrosswordApp.jsx` and all components:
  `CrosswordHeader`, `CrosswordGrid`, `RightPanel`, `TabContent`,
  `UnsolvedModal`) on `--wbench-*` tokens so it reads as a sibling of the
  Bilinguals simulator instead of the legacy vellum + vermilion + verdigris
  palette. `resources/css/crossword.css` rewritten end-to-end: cold paper
  `--wbench-paper` board, `--wbench-rule` hairlines, Source Serif 4 cell
  letters and definitions, JetBrains Mono coordinate markers and eyebrows,
  IBM Plex Sans chrome. One accent role on this surface — the active
  (currently-selected) word reads as `color-mix(--wbench-accent 14%,
  --wbench-paper)`; solved cells go ink-on-paper (`--wbench-ink` bg + paper
  text), quiet and inactive, so the accent stays exclusive to the active
  word. Both arrow cells now share `--wbench-paper-deep` with a 2px
  accent leading edge — direction reads from layout, not from a second
  hue (the old across=vermilion / down=verdigris split was the loudest
  inconsistency with the system's "one accent job per surface" rule).
  Drag handles flip to `--wbench-accent` on hover, matching the
  `simulator.css` pattern. Added a local `.xword-edge` rule in
  `crossword.css` instead of retinting the global `.ribbon-mark` (which stays
  vermilion — shared with the simulator's `TextContent` and the Reader
  index, so both are untouched). Implemented the four-state contract on the
  grid surface: `CrosswordGrid` now returns a centered serif invitation
  under a mono `NO CROSSWORD LOADED` eyebrow when no crossword is built,
  and a serif `--wbench-danger` line + `Retry` control (re-calling
  `getCrossword`) when `isError` is set — instead of returning `null`.
  Definition rows tightened (`text-lg`, `py-2`, mono zero-padded index
  `01`, `02`); tab strip rewritten with the simulator's `tabClass` + 2px
  accent `Underline`; `RightPanel` ghost buttons retinted to
  `--wbench-accent` on hover. `CrosswordHeader` reworked: compact `py-2`,
  mono `CROSSWORD · EN` eyebrow + serif `Workbench` name, hairline divider
  before the `Build` control (which becomes an ultramarine CTA on
  hairline, not vermilion). No controller, route, request/response, or
  prop shape changed; `useCrossword.js` and `constants.js` untouched.
  Follow-ups noted: (1) `useCrossword` does not expose a build-pending
  flag, so the grid's loading state currently collapses into the empty
  state — adding a `pending` flag would let a `WORKING · BUILDING` mono
  label + `.ai-loader-rule` fill sit here per the four-state contract;
  (2) the global `.ribbon-mark` in `app.css`, and the shared
  `Select` / `Button` / `SelectGroup` components, are still on
  `--color-vellum*` / vermilion — migrating the simulator only (the
  shared form controls are used by no other page) and the global
  ribbon-mark (which would also retint Reader) is left for a later pass
  to keep this change self-contained to the crossword. Bumped
  `conventions/design-system.md` `generated.at`.

* **Redesign: Reader index migrated to the wbench design system.**
  Rebuilt `resources/js/Pages/Reader/ReaderIndexApp.jsx` (the
  `/reader-react/{lang}` index) on `--wbench-*` tokens so it reads as a sibling
  of the Bilinguals simulator instead of the legacy vellum palette. Removed the
  editorial display treatment (italic "Antiphonal" eyebrow, 7xl serif heading,
  italic gloss, vermilion flourish, decorative aside, the `→` arrow belt) and
  replaced it with a single compact hairline toolbar (mono `READER · EN ↔ RU` +
  `Parallel Library` eyebrow, `ml-auto` underline tabs), a single-column dense
  list with mono row numbers `01 02 …` and the existing `.ribbon-mark` hover
  edge. Implemented the four-state contract on the entity list — the page's one
  signature: while Inertia navigates between EN/RU libraries a `.ai-loader-rule`
  fills under the toolbar and a mono `LOADING · {Lang}` label sits in the
  content area (reduced-motion defanged by the existing
  `simulator.css` media query); empty state is the mono `NO TEXTS IN THIS
  LIBRARY` eyebrow + serif invite. No controller, request/response, or prop
  shape changed; `ReaderReactIndex.jsx` (the Inertia wrapper) untouched. The
  entity reader at `/reader-react/{lang}/{entityId}` (`ReaderApp.jsx` /
  `ReaderRow.jsx`) is still on `--color-vellum/*` — tracked as a follow-up in
  `domains/reader.md` so a library switch does not cross palettes when entering
  a text. Bumped `conventions/design-system.md` and `domains/reader.md`.

* **Redesign: Bilinguals simulator + design system convention.** Redesigned
  `/bilinguals/en/ru/simulator` with a cold-paper palette (`--wbench-paper`
  #FBFAF8 / `--wbench-ink` #0D0D0F / `--wbench-accent` ultramarine #1F3DDB) and
  three typefaces (Source Serif 4 reading / IBM Plex Sans chrome / JetBrains
  Mono marginalia), all scoped via new `--wbench-*` tokens in
  `resources/css/app.css` so other app pages keep the `--color-vellum/*`
  identity. Densified the layout (22px reading, `py-2` rows, 168px workplace),
  added an empty/loading/answer/error four-state AI Response rail (signature
  surface), retinted drag handles and `.ribbon-mark`, and removed the legacy
  red/blue/yellow literals from `public/css/simulator.css`. Controllers and
  request/response shapes untouched. Added `conventions/design-system.md`
  documenting tokens, type roles, compact scale, layout principles, and the
  four-state contract for request-replaceable surfaces; linked from
  `conventions/index.md` and root `index.md`.

## 2026-08-04

* **Pest TIA Engine**: Bumped Pest 4 → 5 and PHPUnit 12 → 13
  (`composer.json` `pestphp/pest: ^5.0`, `pestphp/pest-plugin-laravel: ^5.0`,
  `phpunit/phpunit: ^13.0`) to enable the [Pest Tia Engine](https://pestphp.com/docs/tia)
  (Test Impact Analysis — re-run only tests affected by your changes). Added
  `composer run test:tia` (uses `XDEBUG_MODE=coverage` env var so parallel
  workers inherit Xdebug coverage for the baseline run) and
  `laravel/scripts/tia-setup.php` (initialises a container-local git repo at
  `/var/www` with a baseline commit, since the Laravel project is bind-mounted
  separately from the git repo root at `/var/repo`). Configured
  `pest()->tia()->filtered()->baselined()` in `tests/Pest.php`. Added
  `.github/workflows/tia-baseline.yml` for CI baseline sharing and installed
  `gh` CLI in `LaravelDockerfile`. Updated `playbooks/running-tests.md`.

## 2026-08-03

* **Python service: two-model split + no-rebuild iteration**. Split the single
  BGE-M3 model into two: BGE-M3 (1024-dim) stays for signatures (`/embed`,
  `/embed/batch`, `/cosine/batch`); a new smaller
  `paraphrase-multilingual-MiniLM-L12-v2` (384-dim) powers `/align` for ~2.5–3×
  faster CPU alignment. Coral score distribution differs from BGE-M3 — the
  `ALIGN_DEFAULT_THRESHOLD` likely needs ~0.55 (tunable live). Refactored
  `ext_python` so the image only carries Python+pip packages; source code
  (`ai/`, bind-mounted) and model weights (named Docker volume `ai_models`
  at `/app/models`) are external — rebuilding is only needed for new
  `requirements.txt` packages. Models load lazily (empty FastAPI lifespan +
  `ai/models_cache.py`) so `uvicorn --reload` restarts in ~1–2s after a `.py`
  edit; the first request lazy-loads the model it needs. `docker-compose/python/
  env/.env` is bind-mounted at `/app/env/.env` (directory mount, not single-file)
  and live per-request accessors in `ai/config.py` re-read it on each call, so
  threshold/window/model-path edits apply without container recreate or restart.
  Added `docker-compose/python/scripts/download_model.py` for adding/swapping
  models into the volume. Removed `docker-compose/python/ai/bge_m3_local/` from
  the source tree (now in the named volume). Updated `docker-compose.yml`
  (python service: `command:` `--reload`, `env_file:`, new volumes, top-level
  `ai_models` volume), `Dockerfile` (no `COPY ai/`, no hardcoded `MODEL_PATH`),
  `ai/main.py` (empty lifespan), `ai/config.py` (live accessors), new
  `ai/models_cache.py`, and all `ai/api/*.py` handlers. Updated
  `domains/sentence-alignment.md`.
  * Also updated `architecture/docker-services.md` (python service description,
    new mounts, `ai_models` volume).

* **Database safety hardening.** A real dev-database wipe (`migrate:fresh`
  against the default `pgsql` connection) triggered a review of the test
  database setup. Changes:
  * `config/database.php`: added a dedicated `testing` connection (pgsql clone
    with `database => env('DB_TEST_DATABASE', 'ext_app_test')`). Tests are now
    bound by connection *name*, not by mutating `DB_DATABASE` on the shared
    `pgsql` connection.
  * `phpunit.xml`: `DB_CONNECTION=testing` + `DB_TEST_DATABASE=ext_app_test`
    (force), keeping `DB_DATABASE=ext_app_test` as a backstop.
  * `tests/TestCase.php` guard tightened: refuses to boot unless the resolved
    default connection is `testing` and its database is `ext_app_test` (was
    only checking the `pgsql` connection's database name).
  * `tests/Pest.php`: added `beforeEach` assertion that
    `DB::connection()->getName() === 'testing'`.
  * `composer.json`: `test:tia` now passes `--drop-databases` so parallel
    Pest workers drop their `ext_app_test_test_{N}` DBs after each run (they
    were accumulating as orphans).
  * Cleaned up 16 orphaned `ext_app_test_test_{N}` databases on the dev
    Postgres instance.
  * `AGENTS.md`: documented the `testing`-connection rule for destructive ops.
  * The main dev DB `ext_app` is currently empty (from the wipe); restore via
    the alignment pipeline when needed.

## 2026-07-28

* **Entity sentence editing**: Added *Sentences* Filament relation managers to
  `EnEntityResource` and `RuEntityResource`, reachable from each entity list
  via a new Sentences row action. Sentences can be created, edited, deleted,
  and reordered with sparse order gaps maintained by `SparseOrderService`.
  `SentenceType` defaults to `sentence`. Deleting a sentence removes its per-side
  meaning matches and cleans up empty `EnRuMeaningMatch` rows, updating the
  parent match's `linked_count`. Added `EntitySentencesRelationManagerTest`.
  Updated `database/entities-alignment.md`, `domains/sentence-alignment.md`,
  `reference/models.md` (via `wiki:sync`).

## 2026-07-27

* **Python service**: Replaced the `ext_embedding` container with `ext_python`
  (same host port 8001): a restructured FastAPI package under
  `docker-compose/python/ai/` (`api/`, `splitting/`, `signatures/`,
  `similarity/`, `alignment/`) serving BGE-M3 (1024-dim, bind-mounted from
  `ai/bge_m3_local`, `HF_HUB_OFFLINE=1`). Sentence splitting and DP alignment
  moved from PHP into python (`/split`, `/align`); Laravel orchestrates chunks
  and owns all DB writes. Config renamed `services.embedding.*` →
  `services.python.*` (new `align_timeout`, dropped dead batch keys); old
  384-dim e5-small signatures must be regenerated. Updated
  `architecture/docker-services.md`, `architecture/overview.md`,
  `domains/sentence-alignment.md`, `playbooks/run-alignment.md`,
  `database/entities-alignment.md`, `AGENTS.md`.
* **Test safety**: Hardened the test environment against wiping the real
  `ext_app` database: `TestCase::createApplication()` guard aborts the suite
  if the resolved DB is not `ext_app_test`, `phpunit.xml` env entries gained
  `force="true"`, and a committed `.env.testing` pins the test DB. Updated
  the Running Tests playbook.

## 2026-07-26

* **Initialization**: Created the OKF v0.2 bundle: architecture, feature
  domains, database, playbooks, conventions sections, plus auto-generated
  `reference/` concepts via `php artisan wiki:sync`.
* **Tooling**: Added `php artisan wiki:sync` and `php artisan wiki:validate`
  (OKF conformance, broken links, staleness) and a Pest test enforcing
  conformance in the test suite.
* **Integration**: Root `AGENTS.md` gained a "Knowledge base" section routing
  agents here; `wiki/` is bind-mounted into the app container at `/var/wiki`.

## 2026-08-22

* **AI model sync button**: Added a "Sync models" header action to the admin
  AI Models list (`ListAiModels::getHeaderActions`) that dispatches a queued
  `App\Jobs\SyncOpenRouterModelsJob` (database queue), replacing the
  "add models only via the artisan command" comment. The job calls the existing
  `OpenRouterModelSync::sync()` service and is guarded by a
  `Cache::lock('openrouter-model-sync')` so a second click while a sync is
  running is refused with a warning instead of queuing a duplicate. `is_enabled`
  is preserved by the service's `updateOrCreate` (unchanged). Updated
  `wiki/domains/ai-providers.md`.
