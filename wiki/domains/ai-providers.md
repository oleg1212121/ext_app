---
type: Service
title: AI Providers
description: Multi-provider AI abstraction used wherever the app asks an LLM a question.
tags: [ai, providers, service]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
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

# Consumers

* [Bilinguals Simulator](/domains/bilinguals-simulator.md) — translation
  assessment (`/ai/question`).
* `Test::askAI` (`/word/ask-ai`) — word-level AI questions.

# Extending

[Adding an AI Provider](/playbooks/add-ai-provider.md).
