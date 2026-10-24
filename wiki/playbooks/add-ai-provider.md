---
type: Playbook
title: Adding an AI Provider
description: Steps to register a new AI provider so it appears in the simulator's model picker.
tags: [ai, providers, howto]
status: stable
generated: { by: agent:zcode, at: 2026-10-14T19:20:00+03:00 }
verified: { by: agent:zcode, at: 2026-10-14T19:20:00+03:00 }
sources:
  - id: resolver
    resource: laravel/app/Classes/AIModelResolver.php
    title: Provider registry
  - id: base
    resource: laravel/app/Classes/AiProvider.php
    title: Abstract provider base class
  - id: services
    resource: laravel/config/services.php
    title: Provider config blocks
---

# Background

Providers are plain classes in `app/Classes/` extending
[`AiProvider`](/domains/ai-providers.md) and keyed by a short string
(`gemini`, `groq`, ...). Consumers never instantiate them directly — they go
through `AIModelResolver` with `provider:model` strings.

# Steps

1. **Create the provider class** in `app/Classes/` (e.g. `Mistral.php`)
   extending `AiProvider`. Implement the abstract statics
   `getProviderKey()` / `getProviderName()` and the interface's two ask
   methods (`askForContext()` + `askForContextStreamed()`); the constructor
   calls `$this->loadModels()` — models come from the **DB catalog**
   (`ai_models`), not a hand-filled `$models` array — and pull `$apiKey`,
   `$aiApiLink`, `$model` from config. Copy the constructor/config pattern
   from an existing provider (`Groq.php` is the simplest OpenAI-compatible one).
2. **Register it** in `AIModelResolver::$providerClasses`:
   `'mistral' => Mistral::class`, and add a syncer mapping in
   `AiModelSyncRegistry` (`MistralModelSync`, subclass of
   `App\Services\AiModelSync` — a test asserts the two registries stay in
   sync). The seeder auto-creates the provider's `ai_providers` row
   (admin-enabled) from `$providerClasses` on the next deploy.
3. **Add config** in `config/services.php` (`'mistral' => ['key' => env(...),
   'url' => env(..., default), 'model' => env(..., default),
   'models_url' => env(...)]` — `models_url` feeds the model catalog sync; a
   blank value short-circuits the HTTP fetch and leaves `ai_models` empty for
   the provider) and the corresponding keys in `.env` / `.env.example`.
4. **Populate the model catalog**: run `php artisan ai:sync-models
   --provider=mistral` (or the admin "Sync models" button) so `ai_models`
   rows exist and enable the ones that should be pickable — `getGroupedModels()`
   reads only enabled `ai_models` rows, so an unsynced provider shows nothing.
5. **Verify**: the provider appears in `AIModelResolver::getGroupedModels()` for
   any user who has stored a **User key** for it (and the admin has it enabled) —
   visibility is now user-keyed, not env-keyed (`isConfigured()` only probes the
   `.env` System key). Store a User key for it from the Profile page to see it in
   the simulator picker.
6. **Test**: add a Pest test mocking the HTTP call (see existing provider
   tests, if any, for the pattern) and run
   `docker exec ext_app_laravel php artisan test --filter=Ai`.

# Notes

* Model strings flowing through the app keep the `provider:model` shape
  (e.g. `openrouter:google/gemini-3.1-flash-lite-preview`); models containing
  colons are safe — `parseModelString()` splits on the **first** colon only.
* `AiProvider::markdownToHtml()` is shared rendering for AI answers — reuse it
  instead of writing new markdown conversion.
