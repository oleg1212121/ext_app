---
type: Playbook
title: Adding an AI Provider
description: Steps to register a new AI provider so it appears in the simulator's model picker.
tags: [ai, providers, howto]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
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
   `getProviderKey()` / `getProviderName()` and the interface's ask method;
   fill `$models` (key → display name), and pull `$apiKey`, `$aiApiLink`,
   `$model` from config in the constructor. Copy the constructor/config pattern
   from an existing provider (`Groq.php` is the simplest OpenAI-compatible one).
2. **Register it** in `AIModelResolver::$providerClasses`:
   `'mistral' => Mistral::class`.
3. **Add config** in `config/services.php` (`'mistral' => ['key' => env(...),
   'url' => env(..., default), 'model' => env(..., default)]`) and the
   corresponding keys in `.env` / `.env.example`.
4. **Verify**: with a key set, the provider's models appear in
   `AIModelResolver::getGroupedModels()` and therefore in the simulator UI
   model picker (unconfigured providers are hidden automatically via
   `isConfigured()`).
5. **Test**: add a Pest test mocking the HTTP call (see existing provider
   tests, if any, for the pattern) and run
   `docker exec ext_app_laravel php artisan test --filter=Ai`.

# Notes

* Model strings flowing through the app keep the `provider:model` shape
  (e.g. `openrouter:google/gemini-3.1-flash-lite-preview`); models containing
  colons are safe — `parseModelString()` splits on the **first** colon only.
* `AiProvider::markdownToHtml()` is shared rendering for AI answers — reuse it
  instead of writing new markdown conversion.
