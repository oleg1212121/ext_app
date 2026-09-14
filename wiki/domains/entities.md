---
type: Feature
title: Library & entities (management surface)
description: Work-first Library browse surface (/library) plus the language-scoped entity create/detail/edit pages, driven by enabled languages.
tags: [entities, works, library, inertia, react, languages]
status: stable
stale_after: 2026-12-11
generated: { by: agent:zcode, at: 2026-09-11T17:00:00Z }
sources:
   - id: controller
     resource: laravel/app/Http/Controllers/EntityController.php
     title: EntityController
   - id: library
     resource: laravel/app/Http/Controllers/LibraryController.php
     title: LibraryController
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
distinct from the reader (the read-only [consume](/domains/reader.md) surface).
Since the Library rework it is **work-first**: `/library` is the work catalog —
every approved user sees every work, empty ones included (ADR
[0021](../../docs/adr/0021-works-are-a-public-catalog.md); works carry no access
semantics) — and a work page shows the entities of that work **the user can
read** (public + granted; Readable count), with a search bar and an add-entity
entry. Entity detail, editing, and sentence management keep their
language-scoped `/entities/{lang}/...` URLs so Alignments/Reader deep links
survive. Entity deletion remains admin-only (Filament); alignment pairing
stays in `/alignments`.

Since ADR
[0018](../../docs/adr/0018-works-and-unified-language-keyed-tables.md) every
entity belongs to a **work** (`work_id` NOT NULL) and carries a `language_id`;
a work may hold several entities in the same language, told apart by
`entities.label` (translator/edition).

The surface is a production consumer of the `Language` model — language
selects and every `{lang}` route are driven by `Language::enabled()`. See ADR
0002 (languages table wired into a production code path).

# Routes

| Route | Handler | Purpose |
|-------|---------|---------|
| `/library` | `LibraryController::index` | Works grid: `?q=` search (title/author ilike), plus-card → create work, per-work **readable** entity count, 15/page. Named `library.index` |
| `/library/create` (GET/POST `/library`) | `LibraryController::createWork` / `storeWork` | Create-work form (title, author, description, original language — must be enabled) → redirect to the work page. Named `library.create` / `library.store` |
| `/library/{work}` | `LibraryController::showWork` | Work info + readable entities of the work, `?q=` search (name/label ilike), plus-card → add entity, 15/page. Named `library.show` |
| `/library/{work}/entities/create` (GET) + POST `/library/{work}/entities` | `LibraryController::createEntity` / `storeEntity` | Work-scoped entity creation: work fixed, language picked from enabled languages; no existing/new-work choice. Runs the shared `EntityCreationService` pipeline. Named `library.entities.create` / `library.entities.store` |
| `/entities`, `/entities/{lang}` | redirect → `/library` | Legacy language-first browse pages (picker + per-language table) |
| `/entities/{lang}/create` | `EntityController::create` | Language-first create form (work picker + inline "new work" fields), named `entities.create` |
| `/entities/{lang}` (POST) | `EntityController::store` | Creates the entity under the resolved work via `EntityCreationService`; stores an optional file and dispatches `ProcessEntityFile`, named `entities.store` |
| `/entities/{lang}/{entity}` (GET/PATCH) | `EntityController::show` / `update` | Detail page / metadata update, named `entities.show` / `entities.update` |
| `/entities/{lang}/{entity}/edit` | `EntityController::edit` | Combined edit page: metadata form + drag-and-drop sentence manager (ADR 0015), named `entities.edit` |
| `/entities/{lang}/{entity}/sentences` (GET/POST) + `/reorder` + `/{sentence}` (PATCH/DELETE) | `EntityController::sentences*` | JSON sentence list + insert / reorder / update / delete, named `entities.sentences.*` |

All routes sit in the `['auth','approved']` group. `{lang}` is validated against
enabled language codes (404 otherwise), not the hardcoded `en|ru` regex used by
other surfaces; `{work}` is numeric.

# Frontend

Inertia pages under `resources/js/Pages/` — `Library/Index` (works grid with
search, dashed plus-card, work cards: title, author, original-language chip,
readable entity count), `Library/CreateWork`, `Library/ShowWork` (work info,
entity search, plus-card, entity cards linking to `/entities/{code}/{id}`),
`Library/CreateEntity` (work fixed, language select), plus the surviving
`Entities/Create`, `Entities/Show`, `Entities/Edit` (metadata form + dnd-kit
sortable sentence manager). The navbar entry is **Library**. All use the
`--wbench-*` tokens to match the sibling Alignments management surface and the
[design system](/conventions/design-system.md); pagination is the shared
`Components/LinkPagination.jsx` (prev/next Inertia links preserving `q`/`page`).

# Editing (ADR 0015)

The `Edit` page combines a metadata form (Inertia `PATCH` →
`entities.update`) with a sentence manager backed by `@dnd-kit/sortable`.
Sentence CRUD is JSON-driven (mirrors `AlignmentEditorController`): the page
fetches the sentence list from `entities.sentences`, and each mutation
(insert / update / delete / reorder) returns the updated list. Drag-to-reorder
uses `SparseOrderService::orderForInsertAfter` with `after_sentence_id = 0`
sentinel for "at the beginning".

**Access**: `EntityAccessService::canEdit` mirrors `canRead` — admin bypass;
Restricted editable by grantees; Public editable by any approved user.

**Cascade delete**: deleting a junctioned sentence cascades — the sentence
models' `deleting`/`deleted` hooks remove junctions, delete any meaning match
left empty, and update `linked_count`. This diverges deliberately from the
alignment editor's unlink-before-delete rule (422 if linked).

**Match staleness**: every sentence mutation flips all `EntityMatch` rows
involving the entity (either side) to `status = 'pending'`, surfacing the need
to re-align. The entity `signature` is intentionally left stale.

# Creation pipeline

Both entry points — the Library's work-scoped form (work fixed, language
chosen) and the legacy language-first form (language fixed, existing-or-new
work) — run `App\Classes\EntityCreationService::create()`. Entity fields are
`name` (required), `label` (optional, distinguishes same-language variants of
a work), `description` (optional), `file` (optional `.txt`), plus the language
and the resolved work. The service stores the file to `entities/{lang}` on the
`local` disk and runs a **synchronous** signature check
(`TextSignatureService::findSimilarExisting`, same-language entities only):

- **Match found** (≥0.95 cosine against an existing Entity in the same
  language — possibly of a *different* work): no new Entity is created. The
  uploader receives an Access grant on the existing Entity (with the match
  `similarity`), the uploaded file is deleted, and they are redirected to the
  existing Entity. This is how a user "uploads" a copyrighted work that
  already exists without creating a copy or infringing — they simply get
  linked.
- **No match:** a new Entity is created with `is_restricted = true`, the
  generated signature is stored on it, the uploader receives a creator grant
  (`similarity` null), and `ProcessEntityFile` is dispatched (entity id + file
  path; the job reads the language from the entity) to split sentences.
- **Embedding service unavailable:** the upload fails hard (user retries with
  a file error); no Entity is created, the file is discarded, nothing leaks.

The `signature` column is never user-entered on the front end.

# Access

Entity reads are gated by `EntityAccessService` (see the [Entity Access](
../../CONTEXT.md#entity-access-context) context). A new upload is Restricted; only
admin and explicitly granted users may read it until an admin publishes it
(`is_restricted = false`). Library entity lists and per-work counts filter by
`EntityAccessService::readableQuery` / `readableConstraint`; the detail pages
403 accordingly, and reading an `EntityMatch` in the simulator requires grants
on **both** of its Entities (see ADR
[0013](../../docs/adr/0013-default-restricted-uploads-and-per-entity-grants.md)
/ [0014](../../docs/adr/0014-per-entity-grants-require-both-sides-for-simulator.md)).
Works themselves are a public catalog (ADR 0021) — a work with zero readable
entities still appears in every user's Library grid, revealing nothing about
the restricted entities it may hold.
