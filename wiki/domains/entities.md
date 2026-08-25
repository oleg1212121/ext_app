---
type: Feature
title: Entities (frontend management surface)
description: Inertia/React management area for creating and viewing per-language text entities, driven by enabled languages.
tags: [entities, inertia, react, languages]
status: stable
generated: { by: human:alex, at: 2026-08-25T00:00:00Z }
sources:
   - id: controller
     resource: laravel/app/Http/Controllers/EntityController.php
     title: EntityController
   - id: request
     resource: laravel/app/Http/Requests/StoreEntityRequest.php
     title: StoreEntityRequest
   - id: routes
     resource: laravel/routes/web.php
     title: Routes
   - id: access
     resource: laravel/app/Classes/EntityAccessService.php
     title: EntityAccessService
---

# What it does

A user-facing **management** surface for [entities](/database/entities-alignment.md),
distinct from the reader (the read-only [consume](/domains/reader.md) surface). It lets an
approved user browse entities per enabled language, create a new entity (with an
optional text file), and inspect a single entity's detail. Edit/delete remain
admin-only (Filament); alignment pairing stays in `/alignments`.

The surface is the **first production consumer** of the `Language` model — the
picker and every `{lang}` route are driven by `Language::enabled()`. See ADR 0002
(languages table wired into a production code path).

# Routes

| Route | Handler | Purpose |
|-------|---------|---------|
| `/entities` | `EntityController::index` | Picker: one card per enabled language (name, native name, entity count), named `entities.index` |
| `/entities/{lang}` | `EntityController::list` | Paginated list of that language's entities, named `entities.list` |
| `/entities/{lang}/create` | `EntityController::create` | Create form, named `entities.create` |
| `/entities/{lang}` (POST) | `EntityController::store` | Creates the entity; stores an optional file and dispatches `ProcessEntityFile`, named `entities.store` |
| `/entities/{lang}/{entity}` | `EntityController::show` | Detail: header + links to the reader and any alignments + read-only sentence list, named `entities.show` |

All routes sit in the `['auth','approved']` group. `{lang}` is validated against
enabled language codes (404 otherwise), not the hardcoded `en|ru` regex used by
other surfaces.

# Frontend

Inertia pages under `resources/js/Pages/Entities/` — `Index` (picker),
`List` (per-language list + "+ Create entity"), `Create` (form with name,
description, file upload), `Show` (detail). All use the `--wbench-*` tokens to
match the sibling Alignments management surface and the
[design system](/conventions/design-system.md).

# Creation pipeline

The create form mirrors the Filament `EnEntityResource` behaviour: fields are
`name` (required), `description` (optional), `file` (optional `.txt`). On
submit the controller stores the file to `entities/{lang}` on the `local` disk
and runs a **synchronous** signature check (`TextSignatureService::
findSimilarExisting`):

- **Match found** (≥0.95 cosine against an existing Entity): no new Entity is
  created. The uploader receives an Access grant on the existing Entity (with
  the match `similarity`), the uploaded file is deleted, and they are redirected
  to the existing Entity. This is how a user "uploads" a copyrighted work that
  already exists without creating a copy or infringing — they simply get linked.
- **No match:** a new Entity is created with `is_restricted = true`, the
  generated signature is stored on it, the uploader receives a creator grant
  (`similarity` null), and `ProcessEntityFile` is dispatched to split sentences.

If the embedding service is unavailable the upload fails hard (user retries);
no Entity is created and nothing leaks. The `signature` column is never
user-entered on the front end.

# Access

Entity reads are gated by `EntityAccessService` (see the [Entity Access](
../../CONTEXT.md#entity-access-context) context). A new upload is Restricted; only
admin and explicitly granted users may read it until an admin publishes it
(`is_restricted = false`). The per-language list and detail pages filter or 403
accordingly, and reading an `EnRuEntityMatch` in the simulator requires grants
on **both** of its Entities (see ADR
[0013](../../docs/adr/0013-default-restricted-uploads-and-per-entity-grants.md)
/ [0014](../../docs/adr/0014-per-entity-grants-require-both-sides-for-simulator.md)).
