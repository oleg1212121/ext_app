---
type: Feature
title: Profile
description: The /profile account surface — tabbed Account / Preferences / AI Models / Danger zone, per-user language and AI-model preferences, provider API key management, account deletion.
tags: [profile, settings, ai, inertia, react]
status: stable
generated: { by: agent:zcode, at: 2026-09-22T16:20:00Z }
sources:
  - id: controller
    resource: laravel/app/Http/Controllers/ProfileController.php
    title: ProfileController (edit/update/settings/ai-models/api-keys/destroy)
  - id: page
    resource: laravel/resources/js/Pages/Profile/Edit.jsx
    title: Tabbed page shell (Edit.jsx)
  - id: ai-models
    resource: laravel/resources/js/Pages/Profile/AiModels.jsx
    title: AiModels tab section
  - id: prefs-request
    resource: laravel/app/Http/Requests/UpdateAiModelPreferencesRequest.php
    title: UpdateAiModelPreferencesRequest
  - id: settings-request
    resource: laravel/app/Http/Requests/UpdateUserSettingsRequest.php
    title: UpdateUserSettingsRequest
  - id: adr-preferences
    resource: docs/adr/0035-per-user-ai-model-preferences.md
    title: ADR 0035 — Per-user AI model preferences, resolved server-side
  - id: routes
    resource: laravel/routes/web.php
    title: Routes (auth group)
---

# What it does

The single account surface, reachable from the NavBar user dropdown at
`/profile` for every authenticated user (approved or not). The page is a
**tab bar of four sections** over the legacy vellum/vermilion palette, each
section rendered as a bordered card; the active tab is reflected in the URL
as `?tab=` (deep-linkable, `history.replaceState` on switch), and only the
active tab's forms stay mounted (Crossword-style unmount panels).

| Tab | Sections | Endpoint |
|-----|----------|----------|
| Account | Profile Information (name/email) · Update Password | `PATCH /profile` (`profile.update`), `PUT /password` (`password.update`, auth routes) |
| Preferences | Native + interface language | `PATCH /profile/settings` (`profile.settings.update`) |
| AI Models | Answer + explanation model selects · AI Provider API Keys | `PATCH /profile/ai-models` (`profile.ai-models.update`), `POST /profile/api-keys` + `DELETE /profile/api-keys/{providerKey}` |
| Danger zone | Delete Account (password modal) | `DELETE /profile` (`profile.destroy`) |

Every form keeps its own Inertia `useForm` state and redirects back to its
own tab after saving (`Redirect::route('profile.edit', ['tab' => ...])`).

# AI Models tab

* Both selects list the models **available to this user** — provider
  admin-enabled AND holding the user's **User key** — grouped by provider,
  cheapest first, valued by `ai_models.id` (`AIModelResolver::
  getGroupedModelChoices()`); option labels reuse `AiModel::displayLabel()`.
* **Answer model** (`user_settings.ai_model_id`) powers AI answers;
  **explanation model** (`explanation_model_id`) powers word popups, and an
  unset explanation model **follows the answer model**. Semantics of unset
  vs unavailable are ADR 0035 — the page shows raw stored ids (possibly
  stale); availability is resolved at request time, not at save time.
* Without any stored API key (`apiKeyProviders.some(p => p.has_key)` is
  false) the selects render disabled with a hint pointing at the API keys
  card below in the same tab.
* API keys are managed per enabled provider as two-state rows (password
  input + Save, or masked-key badge + remove; replace = remove-then-re-add;
  the full key is never echoed) — see
  [AI Providers](/domains/ai-providers.md).

# Persistence

All preferences live on the one-row-per-user `user_settings`
(`App\Models\UserSettings`): `native_language_id` /
`interface_language_id` (typed FKs to `languages`), `ai_model_id` /
`explanation_model_id` (typed FKs to `ai_models`, `nullOnDelete` — the model
sync hard-deletes catalog rows, ADR 0035), and the `ui_settings` JSONB blob
for per-surface UI state (simulator/reader sections, written via
`PATCH /ui-settings`, not from this page). The model preferences were
backfilled from the legacy `ui_settings.simulator.model` string, which no
longer exists.

# Language & validation

Validation is FormRequest-based: `UpdateUserSettingsRequest`
(enabled/interface-enabled `Rule::exists` on languages),
`UpdateAiModelPreferencesRequest` (nullable `Rule::exists('ai_models')->where('is_enabled')`),
`StoreApiKeyRequest`, `ProfileUpdateRequest`. Locale resolution from the
language settings is described in
[Localization](/domains/localization.md); delete-account guards the
last-admin invariant on the `User` model.
