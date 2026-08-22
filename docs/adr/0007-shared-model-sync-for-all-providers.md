# Shared model-sync for all AI providers

All six AI providers (OpenRouter, Gemini, HuggingFace, Cohere, Perplexity,
Groq) now share a `ModelSync` contract backed by an abstract `AiModelSync`
base and an `AiModelSyncRegistry`, and a single `ai:sync-models` artisan
command + `SyncAiModelsJob` that loops every registered syncer. The
OpenRouter-only `openrouter:sync-models` command and `SyncOpenRouterModelsJob`
are removed.

**Context.** ADR-0006 established a DB-backed `ai_models` catalog but only for
OpenRouter: the other five providers kept hardcoded model arrays in their
provider classes and had no catalog-sync path, and the `models_url` endpoint was
hardcoded inside `OpenRouterModelSync`. The schema was already multi-provider
(`ai_models.provider` + unique `[provider, external_id]`), but the sync
infrastructure was OpenRouter-scoped.

**Decision.** A `ModelSync` contract (`provider(): string`, `sync(): int`) and
an abstract `AiModelSync` base hold the shared fetch / upsert / delete-missing
logic, parameterized by each subclass's `provider()`. An `AiModelSyncRegistry`
(parallel to `AIModelResolver`) maps provider keys to syncer classes and is
guarded by a test asserting key parity with `AIModelResolver`. The
`ai:sync-models` command and `SyncAiModelsJob` loop all registered syncers with
per-provider error isolation (catch, log, continue). Each provider's models
endpoint is `services.<provider>.models_url`; a blank value short-circuits the
HTTP call but still runs delete-missing, so a not-yet-wired provider enforces an
API-only catalog without a network call. All six provider class constructors now
read their models from `ai_models` (DB) instead of hardcoded arrays.

**Why this shape.** The contract + base + registry mirrors the existing
`AiProviderInterface` / `AiProvider` / `AIModelResolver` pattern, so adding a
seventh provider is a one-line registry entry. Per-provider error isolation means
one provider's API outage does not block the others. The blank-`models_url`
short-circuit keeps every syncer structurally complete and ready — filling in a
real URL later is a config-only change. Replacing the OpenRouter-specific
command and job removes a duplicate code path and a hand-maintained per-provider
list, and the `ai:sync-models --provider=<key>` option gives a debugging lever
for turning providers on one at a time. Builds on and supersedes the
OpenRouter-only flow described in ADR-0006.
