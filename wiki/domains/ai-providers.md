---
type: Service
title: AI Providers
description: Multi-provider AI abstraction used wherever the app asks an LLM a question.
tags: [ai, providers, service]
status: stable
generated: { by: agent/kimi-k3, at: 2026-08-22T23:00:00Z }
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
---

# Shape of the abstraction

`App\Classes\AIModelResolver` is the only entry point consumers use. Models
are addressed as **`provider:model`** strings (split on the **first** colon, so
OpenRouter model ids containing `/` are fine), e.g.
`openrouter:google/gemini-3.1-flash-lite-preview`.

* `getGroupedModels()` — models grouped by provider name, **only for
  configured providers** (API key present). Drives the simulator's model
  picker.
* `getAllModelKeys()` — flat list for validation.
* `ask($modelString, $instruction, $question)` — resolves the provider and
  calls `askForContext(...)`; returns `?string`.
* `isValidModel($modelString)` — safe check.

`App\Classes\AiProvider` (abstract, implements
`App\Contracts\AiProviderInterface`) provides: `isConfigured()` (API key
present), `getModels()`, `resolveModel()` (fall back to provider default), and
`markdownToHtml()` — the shared markdown→HTML renderer for AI answers
(headers, bold/italic, lists, fenced code blocks, `→`-notation normalized to
`=>`).

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

# AI model catalog

Model lists are **no longer hardcoded** in the provider classes. Every provider
reads its enabled, unexpired models from the `ai_models` table in its constructor
(keyed by `provider`, unique with `external_id`), reconstructing the legacy
`key => "Name ($X.XX/$Y.YY)"` display format via `AiModel::displayLabel()`.

* `App\Services\AiModelSync` (abstract, implements `App\Contracts\ModelSync`)
  holds the shared fetch / upsert / delete-missing logic. `sync()` reads the
  provider's catalog endpoint from `services.<provider>.models_url`; a **blank**
  value short-circuits the HTTP call but still runs delete-missing, so a
  not-yet-wired provider enforces an API-only catalog without a network call.
  One concrete subclass exists per provider (`OpenRouterModelSync`,
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
  **SyncAiModelsJob** (database queue) and shows a "Sync queued" notification
  immediately; the job loops every registered syncer in the background with
  per-provider error isolation (a failed provider is logged and skipped, the lock
  is always released in `finally`). A `Cache::lock('ai-model-sync')` guard
  prevents duplicate syncs. There is **no fallback**: until a sync has run at
  least once (and, for non-OpenRouter providers, their `models_url` has been
  filled), the model picker is empty for those providers.
* New rows default to `is_enabled = false`; re-synced rows keep their
  `is_enabled` value.
* `is_enabled` gates appearance in the simulator picker. Models are managed in
  the admin panel via `App\Filament\Resources\AiModelResource` (list + inline
  enable/disable action; no create/edit form — models are added only by sync).

# Consumers

* [Bilinguals Simulator](/domains/bilinguals-simulator.md) — translation
  assessment (`/ai/question`).
* `Test::askAI` (`/word/ask-ai`) — word-level AI questions.

# Extending

[Adding an AI Provider](/playbooks/add-ai-provider.md).
