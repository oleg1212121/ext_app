---
type: Service
title: Localization
description: Interface language resolution and DB-backed UI strings with Filament CRUD.
tags: [i18n, localization, languages, filament]
status: stable
generated: { by: agent, at: 2026-09-12T12:00:00Z }
verified: {}
---

# Localization

How the web UI's display language works and how interface text is managed.

## Interface language

- The UI locale comes from a resolution chain: the user's
  **Interface language** setting → the **Native language** → `en`. Only
  interface-enabled languages (`languages.is_interface_enabled`, seeded true for
  `en`/`ru`) qualify; guests always get `en`.
- `App\Models\User::resolvedInterfaceLocale()` implements the chain;
  `App\Http\Middleware\SetInterfaceLocale` (appended to the `web` group, before
  `HandleInertiaRequests`) applies it per request and skips `/admin*` — the
  Filament panel stays English by design.
- Set at `PATCH /profile/settings` (Inertia `Profile/Edit` has an
  interface-language select defaulting to "follows native language"), and
  inline in Filament's `UserResource` forms via the
  `settings_interface_language_id` shuttle.

## UI strings

- Source of truth: `ui_string_keys` (dotted key, `group` = first segment,
  derived on save) + `ui_strings` (one text per interface-enabled language,
  unique `(ui_string_key_id, language_id)`). Adding an interface language is a
  row insert, never DDL (ADR 0022 / ADR 0018 principle).
- `App\Support\UiStrings::mapFor(locale)` builds a flat key → text map per
  locale (English merged underneath; missing values fall back to English
  silently), cached via `Cache::rememberForever("ui_strings.map.{locale}")`.
  Model observers on `UiString`/`UiStringKey` flush these caches, so admin
  edits apply on the next request.
- `App\Translation\UiStringLoader` (a `FileLoader` subclass, swapped in via
  `$app->extend('translation.loader', ...)`) overlays the map onto each string
  group, so Blade/Filament `__('group.key')` works alongside framework lines.
- React pages: `HandleInertiaRequests` shares `locale` and `uiStrings`
  (the same map); `resources/js/i18n.jsx` provides `useI18n()`/`t(key, replace)`.
  Missing keys render as the raw key and `console.warn` in dev.
- Admin CRUD: Filament `UiStringKeyResource` (navigation group
  "Localization") — key form validated `^[a-z0-9_]+(\.[a-z0-9_]+)+$`, group
  derived from the key, and a relationship Repeater listing one text field per
  interface-enabled language side by side.
- Initial content: `Database\Seeders\UiStringSeeder` upserts every
  `database/seeders/ui-strings/*.php` partial (group per surface: nav, auth,
  profile, library, entities, alignments, reader, bilinguals, welcome,
  dashboard, pending). Seeder runs are idempotent; admin edits win afterwards.

## Framework messages

Validation errors and built-in auth notifications remain English in v1; the
`validation.*`/`auth.*` groups can later be seeded into the same tables with no
architecture change (ADR 0022).

**Sources**: `app/Support/UiStrings.php`, `app/Translation/UiStringLoader.php`,
`app/Http/Middleware/SetInterfaceLocale.php`, `app/Filament/Resources/UiStringKeyResource.php`,
`database/migrations/2026_09_12_000001_*`, `docs/adr/0022-*`, `docs/adr/0023-*`.

**Related**: [Access Control](access-control.md) (who may edit),
[Schema Overview](../database/schema-overview.md).
