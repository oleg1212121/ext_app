---
type: Service
title: AI Providers
description: Multi-provider AI abstraction used wherever the app asks an LLM a question.
tags: [ai, providers, service]
status: stable
generated: { by: agent/opencode, at: 2026-09-03T16:30:00Z }
verified: { by: human:alex, at: 2026-08-23T18:00:00Z }
sources:
  - id: base
    resource: laravel/app/Classes/AiProvider.php
    title: Abstract provider
  - id: resolver
    resource: laravel/app/Classes/AIModelResolver.php
    title: Provider registry and entry point
  - id: contract
    resource: laravel/app/Contracts/AiProviderInterface.php
    title: Provider interface
  - id: services
    resource: laravel/config/services.php
    title: API keys, endpoints, default models
   - id: model
     resource: laravel/app/Models/AiModel.php
     title: DB-backed AI model catalog entry
   - id: sync-contract
     resource: laravel/app/Contracts/ModelSync.php
     title: ModelSync contract
   - id: sync-base
     resource: laravel/app/Services/AiModelSync.php
     title: Abstract AI model sync service
   - id: sync-registry
     resource: laravel/app/Services/AiModelSyncRegistry.php
     title: AI model sync registry
   - id: sync-or
     resource: laravel/app/Services/OpenRouterModelSync.php
     title: OpenRouter model sync service
   - id: command
     resource: laravel/app/Console/Commands/SyncAiModels.php
     title: ai:sync-models command
   - id: job
     resource: laravel/app/Jobs/SyncAiModelsJob.php
     title: SyncAiModelsJob
   - id: provider-model
     resource: laravel/app/Models/AiProvider.php
     title: DB-backed AI provider (enable overlay)
   - id: provider-migration
     resource: laravel/database/migrations/2026_08_23_000001_create_ai_providers_table.php
     title: ai_providers table
    - id: model-fk-migration
      resource: laravel/database/migrations/2026_08_23_000002_add_ai_provider_id_to_ai_models.php
      title: ai_models.ai_provider_id FK (added nullable)
    - id: model-fk-not-null
      resource: laravel/database/migrations/2026_08_24_000001_make_ai_models_provider_not_null.php
      title: ai_models.ai_provider_id made NOT NULL + cascade delete
   - id: provider-seeder
     resource: laravel/database/seeders/AiProviderSeeder.php
     title: AiProviderSeeder
   - id: provider-resource
     resource: laravel/app/Filament/Resources/AiProviderResource.php
     title: AiProviderResource (edit-only admin)
   - id: user-key-model
     resource: laravel/app/Models/UserApiKey.php
     title: Per-user API key (encrypted)
   - id: user-key-migration
     resource: laravel/database/migrations/2026_08_23_000003_create_user_api_keys_table.php
     title: user_api_keys table
   - id: user-key-request
     resource: laravel/app/Http/Requests/StoreApiKeyRequest.php
     title: StoreApiKeyRequest
   - id: profile-controller
     resource: laravel/app/Http/Controllers/ProfileController.php
     title: Profile API key endpoints
---

## Language

* **User key** — a per-user API key a user stores (encrypted) for one provider,
  used for every user-facing AI request. One key per provider per user
  (`user_api_keys`, `unique(user_id, ai_provider_id)`). _Avoid: "personal key"_
* **System key** — the admin's `.env` key for a provider
  (`services.<key>.key`); reserved for non-user paths: model sync, CLI, admin
  tooling. _Avoid: "env key", "admin key"_
* **Provider (enabled)** — admin-toggled on; `ai_providers.is_enabled = true`.
  _Avoid: "active", "on"_
* **Provider (available, to a user)** — enabled AND the user has a **User key**;
  gates simulator-picker appearance. _Avoid: "visible", "configured"_
* **Model (enabled)** — per-row toggle; `ai_models.is_enabled`. _Avoid: "active
  model"_
* **Sync** — catalog mirror from `services.<key>.models_url`; deliberately
  ignores provider enabled-state.

# Shape of the abstraction

`App\Classes\AIModelResolver` is the only entry point consumers use. Models
are addressed as **`provider:model`** strings (split on the **first** colon, so
OpenRouter model ids containing `/` are fine), e.g.
`openrouter:google/gemini-3.1-flash-lite-preview`.

* `getGroupedModels()` — models grouped by provider name, **only for providers
  the authenticated user has a User key for** (and that are admin-enabled);
  within each provider sorted by price ascending (cheapest first) and provider
  groups ordered by their cheapest model, so the globally cheapest model is
  first. Drives the simulator's model picker.
* `firstModelKey()` — the first model key from `getGroupedModels()` (globally
  cheapest), or `null` when the user has no usable providers.
* `getAllModelKeys()` — flat list for validation.
* `ask($modelString, $instruction, $question)` — resolves the provider, reads
  the authenticated user's **User key** for it, and calls `askForContext(...)`
  on a key-stamped clone (`AiProvider::withApiKey()`); throws a 403
  `AiProviderException` if the user has no key for the provider. Returns
  `?string`.
* `isValidModel($modelString)` — catalog-only check (does this model exist?);
  authorization (does this user have a key?) is enforced in `ask()`.

`App\Classes\AiProvider` (abstract, implements
`App\Contracts\AiProviderInterface`) provides: `isConfigured()` (a **System
key** is present in `.env`) — retained as a probe, no longer gates visibility;
`getModels()`, `resolveModel()` (fall back to provider default),
`withApiKey($key)` (returns a clone carrying the given key, leaving the
resolver's cached template untouched), and `markdownToHtml()` — the shared
markdown→HTML renderer for AI answers (headers, bold/italic, lists, fenced
code blocks, `→`-notation normalized to `=>`).

# Registered providers

| Key | Class | Default model | Config block |
|-----|-------|---------------|--------------|
| `openrouter` | `OpenRouter` | `xiaomi/mimo-v2-flash:free` | `services.openrouter` |
| `gemini` | `Gemini` | `gemini-2.5-flash-lite` | `services.gemini` |
| `huggingface` | `HuggingFace` | `deepseek-ai/DeepSeek-V3.1-Terminus:novita` | `services.huggingface` |
| `cohere` | `Cohere` | `command-a-03-2025` | `services.cohere` |
| `perplexity` | `Perplexity` | `sonar` | `services.perplexity` |
| `groq` | `Groq` | `llama-3.3-70b-versatile` | `services.groq` |

Each block holds `key` / `url` / `model` from env (e.g. `GEMINI_API_KEY`,
`GEMINI_API_URL`, `GEMINI_MODEL`). Note the typo kept for BC:
`HUGGINFACE_API_KEY` (missing a 'G') is the env var name actually read.

A shared outbound proxy is configured via `services.proxy`
(`PROXY_LOGIN/PASSWORD/IP/PORT`) for providers that need it.

# Provider registry

Providers are persisted in the `ai_providers` table (`App\Models\AiProvider`)
but only as an *enable-flag overlay*: each row holds `key` (unique, matching the
hardcoded `getProviderKey()`), `name`, `is_enabled`, and `description`. The set
of providers and their syncer classes stays hardcoded in `AIModelResolver` and
`AiModelSyncRegistry` (ADR 0009). `Database\Seeders\AiProviderSeeder` seeds one
enabled row per registered provider. Per-user API keys live in `user_api_keys`
(encrypted via the `encrypt:` cast, ADR 0012) — the `.env` **System key**
remains only for non-user paths (model sync, CLI, admin tooling). The admin
`App\Filament\Resources\AiProviderResource` is therefore still **edit-only** (no
Create page) and edits only the non-secret overlay fields (`key` readonly,
`name`, `is_enabled`, `description`); user keys are managed by each user from
their Profile. The Profile lists every enabled provider as a two-state row
driven by whether a User key exists: with no key stored it shows a password
input plus an icon Save button; with a key stored it shows only a green "Current
key" badge (first and last four characters separated by `••••`) and a trash-icon
remove control — deleting the key reveals the input form again, so replacing a
stored key is remove-then-re-add. The full key is never echoed.

A provider is **available (to a user)** in the simulator picker iff it is
*enabled* (`ai_providers.is_enabled`) **AND** the user has stored a **User key**
for it. `AIModelResolver::getGroupedModels()` excludes any provider that is not
both; `ask()` returns 403 if the user has no User key for the requested provider
(there is no System-key fallback for user requests — ADR 0012).

# AI model catalog

Model lists are **no longer hardcoded** in the provider classes. Every provider
reads its enabled, unexpired models from `ai_models` in its constructor, via the
**NOT NULL** `ai_provider_id` foreign key to `ai_providers` (unique with
`external_id`, `cascadeOnDelete`), reconstructing the legacy
`key => "Name ($X.XX/$Y.YY)"` display format via `AiModel::displayLabel()`. The
column was originally added nullable (leaving pre-existing catalog rows
orphaned); a follow-up migration enforces NOT NULL, so orphan rows can no longer
exist.

* `App\Services\AiModelSync` (abstract, implements `App\Contracts\ModelSync`)
  holds the shared fetch / upsert / delete-missing logic. `sync()` reads the
  provider's catalog endpoint from `services.<provider>.models_url`; a **blank**
  value short-circuits the HTTP call but still runs delete-missing, so a
  not-yet-wired provider enforces an API-only catalog without a network call.
  `sync()` writes `ai_provider_id` (resolved from the provider key) and **ignores
  `ai_providers.is_enabled`** — sync is a catalog mirror, not an availability
  gate (ADR 0011). One concrete subclass exists per provider (`OpenRouterModelSync`,
  `GeminiModelSync`, `CohereModelSync`, `PerplexityModelSync`,
  `HuggingFaceModelSync`, `GroqModelSync`), each returning its key from
  `provider()`.
* `App\Services\AiModelSyncRegistry` maps provider keys → syncer classes
  (parallel to `AIModelResolver`'s provider map; a test asserts the two stay in
  sync). `OpenRouterModelSync` fetches `GET https://openrouter.ai/api/v1/models`
  (via the Laravel `Http` facade); the other five currently have a blank
  `models_url` and simply enforce the empty catalog.
* The sync is triggered manually — either by the `ai:sync-models` artisan
  command (optionally `--provider=<key>` to sync just one), or by the **"Sync
  models"** button in the admin panel (`AiModelResource` → `ListAiModels` header
  action). The button dispatches a queued `App\Jobs\SyncAiModels`;
  **SyncAiModelsJob** (database queue) loops every registered syncer in the
  background with per-provider error isolation (a failed provider is logged and
  skipped, the lock is always released in `finally`). A
  `Cache::lock('ai-model-sync')` guard prevents duplicate syncs. There is **no
  fallback**: until a sync has run at least once (and, for non-OpenRouter
  providers, their `models_url` has been filled), the model picker is empty for
  those providers.
* New rows default to `is_enabled = false`; re-synced rows keep their
  `is_enabled` value.
* `ai_models.is_enabled` (per-row **model-enabled**) gates appearance in the
  simulator picker. Models are managed in the admin panel via
  `App\Filament\Resources\AiModelResource` (list + inline enable/disable action;
  no create/edit form — models are added only by sync).

# Consumers

* [Bilinguals Simulator](/domains/bilinguals-simulator.md) — translation
  assessment (`/ai/question`).
* `Test::askAI` (`/word/ask-ai`) — word-level AI questions.

# Extending

[Adding an AI Provider](/playbooks/add-ai-provider.md).
