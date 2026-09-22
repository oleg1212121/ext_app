---
type: Feature
title: Library & entities (management surface)
description: Work-first Library browse surface (/library) with per-work Entities and Alignments tabs, plus the language-scoped entity create/detail/edit pages, driven by enabled languages.
tags: [entities, works, library, alignments-tab, inertia, react, languages, hash, clone]
status: stable
stale_after: 2026-12-22
generated: { by: agent:zcode, at: 2026-09-22T12:00:00Z }
sources:
   - id: controller
     resource: laravel/app/Http/Controllers/EntityController.php
     title: EntityController
   - id: library
     resource: laravel/app/Http/Controllers/LibraryController.php
     title: LibraryController
   - id: creation
     resource: laravel/app/Classes/EntityCreationService.php
     title: EntityCreationService
   - id: hasher
     resource: laravel/app/Classes/EntityTextHasher.php
     title: EntityTextHasher
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
read** (public + granted; Readable count). Since ADR
[0036](../../docs/adr/0036-alignments-live-under-work.md) the work page is
tabbed: an **Entities** tab (the original content — search, add-entity card,
entity cards) and an **Alignments** tab listing the work's readable
[entity matches](/database/entities-alignment.md) with add-alignment entry
(the former global `/alignments` surface is gone — see
[sentence alignment](/domains/sentence-alignment.md)). Entity detail, editing,
and sentence management keep their language-scoped `/entities/{lang}/...` URLs
so Alignments/Reader deep links survive. Entity deletion remains admin-only
(Filament).

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
| `/library/{work}` | `LibraryController::showWork` | Work info + tabbed lists, `?tab=entities` (default) or `?tab=alignments`, per-tab `?q=` search + 15/page. Entities tab: readable entities (name/label search), plus-card → add entity. Alignments tab: work's readable entity matches (either side's name search), each payload via `AlignmentEditorApiPresenter::matchPayload` + a server-computed `reader_target` (the non-native side; original-side then A-side tiebreaks), plus-card → add alignment. Named `library.show` |
| `/library/{work}/entities/create` (GET) + POST `/library/{work}/entities` | `LibraryController::createEntity` / `storeEntity` | Work-scoped entity creation: work fixed, language picked from enabled languages; no existing/new-work choice. Runs the shared `EntityCreationService` pipeline. Named `library.entities.create` / `library.entities.store` |
| `/library/{work}/alignments/create` (GET) + POST `/library/{work}/alignments` | `LibraryController::createAlignment` / `storeAlignment` | Work-scoped entity-match creation (ADR 0036): two entity selects of the work's alignable entities (readable + signature + sentences), `chunk_size`/`max_n`; canonical a/b order, duplicate-pair guard, alignment-copy fast path else `AlignEntitySentences::beginFromScratch`; redirects back to the Alignments tab. Named `library.alignments.create` / `library.alignments.store` |
| `/entities`, `/entities/{lang}` | redirect → `/library` | Legacy language-first browse pages (picker + per-language table) |
| `/entities/{lang}/create` | `EntityController::create` | Language-first create form (work picker + inline "new work" fields), named `entities.create` |
| `/entities/{lang}` (POST) | `EntityController::store` | Creates the entity under the resolved work via `EntityCreationService`; stores an optional file and dispatches `ProcessEntityFile`, named `entities.store` |
| `/entities/{lang}/{entity}` (GET/PATCH) | `EntityController::show` / `update` | Detail page / metadata update, named `entities.show` / `entities.update` |
| `/entities/{lang}/{entity}/approved` (PATCH) | `EntityController::updateApproved` | Flip the approval edit-lock (uploader or admin), named `entities.approved.update` |
| `/entities/{lang}/{entity}/edit` | `EntityController::edit` | Combined edit page: metadata form + drag-and-drop sentence manager (ADR 0015), named `entities.edit` |
| `/entities/{lang}/{entity}/sentences` (GET/POST) + `/reorder` + `/{sentence}` (PATCH/DELETE) | `EntityController::sentences*` | JSON sentence list + insert / reorder / update / delete, named `entities.sentences.*` |

All routes sit in the `['auth','approved']` group. `{lang}` is validated against
enabled language codes (404 otherwise), not the hardcoded `en|ru` regex used by
other surfaces; `{work}` is numeric.

# Frontend

Inertia pages under `resources/js/Pages/` — `Library/Index` (works grid with
search, dashed plus-card, work cards: title, author, original-language chip,
readable entity count), `Library/CreateWork`, `Library/ShowWork` (work info,
tab bar, per-tab search + plus-card; entity cards linking to
`/entities/{code}/{id}`, alignment cards via `Components/AlignmentCard.jsx` —
stretched link to the editor `/alignments/{id}` with Simulator / Read·{LANG}
buttons on top), `Library/CreateEntity` (work fixed, language select),
`Library/CreateAlignment` (work fixed, two entity selects + chunk params),
plus the surviving `Entities/Create`, `Entities/Show`, `Entities/Edit`
(metadata form + dnd-kit sortable sentence manager). The navbar entry is
**Library** (the Alignments navbar item is gone — ADR 0036). All use the
`--wbench-*` tokens to match the sibling Alignments management surface and the
[design system](/conventions/design-system.md); pagination is the shared
`Components/LinkPagination.jsx` (prev/next Inertia links preserving
`tab`/`q`/`page`); alignment display helpers (status badge, similarity
grading) live in `lib/alignmentDisplay.js`.

# Editing (ADR 0015)

The `Edit` page combines a metadata form (Inertia `PATCH` →
`entities.update`) with a sentence manager backed by `@dnd-kit/sortable`.
Sentence CRUD is JSON-driven (mirrors `AlignmentEditorController`): the page
fetches the sentence list from `entities.sentences`, and each mutation
(insert / update / delete / reorder) returns the updated list. Drag-to-reorder
uses `SparseOrderService::orderForInsertAfter` with `after_sentence_id = 0`
sentinel for "at the beginning".

**Access**: `EntityAccessService::canEdit` mirrors `canRead` — admin bypass;
Restricted editable by grantees; Public editable by any approved user —
**except** an approved entity (`is_approved`), which is editable by admin only
(ADR 0034); its sentence endpoints and the edit page 403 for everyone else,
including the uploader, until approval is lifted.

**Cascade delete**: deleting a junctioned sentence cascades — the sentence
models' `deleting`/`deleted` hooks remove junctions, delete any meaning match
left empty, and update `linked_count`. This diverges deliberately from the
alignment editor's unlink-before-delete rule (422 if linked).

**Match staleness**: every sentence mutation flips all `EntityMatch` rows
involving the entity (either side) to `status = 'pending'`, surfacing the need
to re-align. The entity `signature` is intentionally left stale — but the
**text hash** is not: every mutation also bumps `entities.sentences_updated_at`
(model events for Eloquent writes; explicit touches at the bulk sites), which
the `entities:refresh-text-hashes` scheduler uses to rehash (see below).

# Creation pipeline (ADR 0033)

Both entry points — the Library's work-scoped form (work fixed, language
chosen) and the legacy language-first form (language fixed, existing-or-new
work) — run `App\Classes\EntityCreationService::create()`. Entity fields are
`name` (required), `label` (optional), `description` (optional), `file`
(optional `.txt`), plus the language and the resolved work. The service stores
the file to `entities/{lang}` on the `local` disk and hashes the raw bytes
(`file_hash`, local sha256 — **no Python call is made synchronously and an
upload never fails because of the embedding service**):

- **Exact copy found** (same `file_hash` + language, source has sentences):
  the uploader still gets their **own** Entity — their metadata and work,
  `is_restricted = true`, `created_by` = uploader, creator grant — **cloned**
  from the source: sentences (content, type, order), signature vector, word
  statistics (`entity_words` + `words_indexed_at`), and the text hash are
  copied verbatim. No split, no embed, no Python at all. Redirect carries a
  "created from an exact copy" status. The clone is fully independent — no
  foreign keys to the source; deleting either never touches the other.
- **No exact copy:** the Entity is created (`created_by`, `file_hash`,
  `sentences_updated_at = now`, signature still null) and `ProcessEntityFile`
  is dispatched, which now just chains `SplitEntityFileSentences` →
  `FinalizeEntityDerivations`: compute the text hash, then copy signature +
  word statistics from a text-hash-equal source if one exists, else generate
  the embedding signature in the background.

The `signature` column is never user-entered on the front end. Near-duplicate
merging (the ≥0.95 grant/merge/delete flow of ADR 0013) is gone; the
signature's remaining uses are cross-language candidate finding (Filament
"Find Match") and the ≥0.70 pre-align verification gate.

# Text hash maintenance

`EntityTextHasher` computes `text_hash` = sha256 over sentence contents in
`order`, each whitespace-normalized (case/punctuation preserved). Staleness:
`text_hash IS NULL OR text_hashed_at < sentences_updated_at`. The
`entities:refresh-text-hashes` command (scheduled every 5 min with
`withoutOverlapping`, `--limit`/`--dry-run`) dispatches `ShouldBeUnique`
`ComputeEntityTextHash` jobs that re-check staleness at run time. The
alignment-copy lookup (`AlignmentCopyService`) recomputes synchronously when
stale — a local sha256, not a service call. Equal text hashes ⇒ exact copies ⇒
a completed alignment between one copy pair is reused for another (see
[sentence alignment](/domains/sentence-alignment.md)).

# Approval edit-lock (ADR 0034)

`entities.is_approved` (default false) freezes content changes when true:
metadata edits, sentence CRUD (all surfaces), alignment-editor mutations and
re-aligns on matches involving the entity, `alignments:resume` pickup, and
deletion (a model-level `deleting` guard throws for non-admins). Admins bypass
everything (`Gate::before`); the **uploader** (`created_by`) and admins may
flip the flag in both directions via `entities.approved.update` (Inertia
toggle on the entity Show page; Filament toggle too). `created_by` is nullable
(null = system/admin import) and `nullOnDelete` — a deleted uploader's
entities survive, admin-only.

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
