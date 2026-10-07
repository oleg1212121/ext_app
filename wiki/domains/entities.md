---
type: Feature
title: Library & entities (management surface)
description: Work-first Library browse surface (/works) — the works catalog, its Entities and Alignments branch lists, per-work landing/branch pages (ADR 0039), and the work-nested entity detail/edit pages with their sentence JSON API (ADR 0073), plus inline illustration upload (ADR 0050) and the per-user word-knowledge stat (ADR 0074), driven by enabled languages.
tags: [entities, works, library, alignments-page, inertia, react, languages, hash, clone, illustrations, word-knowledge]
status: stable
stale_after: 2026-12-20
generated: { by: agent:zcode, at: 2026-10-07T15:00:00Z }
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
   - id: knowledge
     resource: laravel/app/Classes/EntityWordKnowledgeService.php
     title: EntityWordKnowledgeService
   - id: knowledge-command
     resource: laravel/app/Console/Commands/RefreshEntityWordKnowledgeCommand.php
     title: RefreshEntityWordKnowledgeCommand
   - id: request
     resource: laravel/app/Http/Requests/StoreWorkEntityRequest.php
     title: StoreWorkEntityRequest
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
It is **work-first**: the works catalog is public — every approved user sees
every work, empty ones included (ADR
[0021](../../docs/adr/0021-works-are-a-public-catalog.md); works carry no access
semantics) — while a work's pages show its entities and matches **the user can
read** (public + granted; Readable count). Since ADR
[0039](../../docs/adr/0039-library-dropdown-works-branches.md) the section
lives under `/works` behind a three-entry **Library** navbar dropdown
(**Works / Entities / Alignments**), and the former per-work tab layout is
gone: `/works/{work}` is a work **landing page** (metadata + readable counts
linking onward), and the former tab contents are standalone pages —
`/works/{work}/entities` (search, add-entity card, entity cards) and
`/works/{work}/alignments` listing the work's readable
[entity matches](/database/entities-alignment.md) with add-alignment entry
(the former global `/alignments` surface is gone — see
[sentence alignment](/domains/sentence-alignment.md)). The old `/library/*`
URLs were removed without redirects (ADR 0039); the pre-existing `/entities`,
`/entities/{lang}` legacy redirects still target `/works/entities`. Entity
detail, editing, and sentence management are work-nested too
(`/works/{work}/entities/{entity}...`, ADR 0073) — the language segment is
gone (the entity carries its language) and the URL names the work; the old
flat `/entities/{lang}/...` routes are gone without redirects. Entity
deletion remains admin-only (Filament).

Since ADR
[0018](../../docs/adr/0018-works-and-unified-language-keyed-tables.md) every
entity belongs to a **work** (`work_id` NOT NULL) and carries a `language_id`;
a work may hold several entities in the same language, told apart by
`entities.label` (translator/edition).

The surface is a production consumer of the `Language` model — language
selects and the language shown on entity pages are driven by
`Language::enabled()`. See ADR
0002 (languages table wired into a production code path).

# Routes

| Route | Handler | Purpose |
|-------|---------|---------|
| `/works` | `LibraryController::index` | Works catalog: `?q=` search (title/author ilike), plus-card → create work, per-work **readable** entity + alignment counts, 15/page. Named `works.index`. `entitiesIndex` / `alignmentsIndex` serve the same list for `/works/entities` / `/works/alignments` (`works.entities.index` / `works.alignments.index`) — the branch works-lists; only the card count and card target differ |
| `/works/create` (GET/POST `/works`) | `LibraryController::createWork` / `storeWork` | Create-work form (title, author, description, original language — must be enabled) → redirect to the work landing page. Named `works.create` / `works.store` |
| `/works/{work}` | `LibraryController::showWork` | Work landing page (ADR 0039): catalog metadata + readable `entities_count` / `alignments_count`, each linking to the work's branch page. Named `works.show` |
| `/works/{work}/entities` | `LibraryController::workEntities` | The work's readable entities (former entities tab): `?q=` search (name/label), 15/page, plus-card → add entity. Named `works.entities.show` |
| `/works/{work}/alignments` | `LibraryController::workAlignments` | The work's readable entity matches (former alignments tab; ADR 0036): either side's name search, each payload via `AlignmentEditorApiPresenter::matchPayload` + a server-computed `reader_target` (the non-native side; original-side then A-side tiebreaks), plus-card → add alignment. Named `works.alignments.show` |
| `/works/{work}/entities/create` (GET) + POST `/works/{work}/entities` | `LibraryController::createEntity` / `storeEntity` | Work-scoped entity creation (the only create surface since ADR 0073): work fixed, language picked from enabled languages. Runs the shared `EntityCreationService` pipeline; redirects to the new entity's work-nested page. Named `works.entities.create` / `works.entities.store` |
| `/works/{work}/entities/{entity}` (GET/PATCH) | `EntityController::show` / `update` | Detail page / metadata update, named `entities.show` / `entities.update` (ADR 0073). Binds `Work $work, Entity $entity` and 404s unless `$entity->work_id === $work->id` |
| `/works/{work}/entities/{entity}/approved` (PATCH) | `EntityController::updateApproved` | Flip the approval edit-lock (uploader or admin), named `entities.approved.update` |
| `/works/{work}/entities/{entity}/edit` | `EntityController::edit` | Combined edit page: metadata form + drag-and-drop sentence manager (ADR 0015), named `entities.edit` |
| `/works/{work}/entities/{entity}/sentences` (GET/POST) + `/reorder` + `/{sentence}` (PATCH/DELETE) | `EntityController::sentences*` | JSON sentence list + insert / reorder / update / delete, named `entities.sentences.*` — same work-nested prefix and wrong-work 404 as the pages |
| `/works/{work}/alignments/create` (GET) + POST `/works/{work}/alignments` | `LibraryController::createAlignment` / `storeAlignment` | Work-scoped entity-match creation (ADR 0036): two entity selects of the work's alignable entities (readable + signature + sentences), `chunk_size`/`max_n`; canonical a/b order, duplicate-pair guard, alignment-copy fast path else `AlignEntitySentences::beginFromScratch`; redirects back to the work's Alignments page. Named `works.alignments.create` / `works.alignments.store` |
| `/entities`, `/entities/{lang}` | redirect → `/works/entities` | Legacy language-first browse pages (picker + per-language table). The deeper `/entities/{lang}/...` routes are gone without redirects (ADR 0073) |

All routes sit in the `['auth','approved']` group. Route names keep the
`entities.*` prefix (`works.entities.show`/`index` are already the per-work
list and the global browse); `{work}`/`{entity}`/`{sentence}` are numeric.

# Frontend

Inertia pages under `resources/js/Pages/` — `Library/Index` (the works list,
one component for all three lists via a `variant` prop — `catalog` /
`entities` / `alignments` pick the count chip and the card target; search,
dashed plus-card on the catalog only, work cards: title, author,
original-language chip, readable count), `Library/CreateWork`,
`Library/ShowWork` (the work landing page: metadata + two branch cards),
`Library/WorkEntities` (per-tab search + plus-card; entity cards linking to
`/works/{work}/entities/{entity}`), `Library/WorkAlignments` (alignment cards via
`Components/AlignmentCard.jsx` — stretched link to the editor
`/works/{work}/alignments/{id}/edit` with Simulator / Read·{LANG} buttons on top),
`Library/CreateEntity` (work fixed, language select), `Library/CreateAlignment`
(work fixed, two entity selects + chunk params), plus
`Entities/Show` (back link "← {work title} entities" to the work's Entities
page; language shown in the subtitle; the viewer's
[word knowledge](#word-knowledge-adr-0074) percentage in the header) and
`Entities/Edit` (metadata form +
dnd-kit sortable sentence manager; back link and cancel to the entity's
view page). The navbar's **Library** dropdown groups
**Works / Entities / Alignments** (ADR 0039; each child carries an explicit
URL match rule because the branches share the `/works` prefix), alongside
**Practice** (the restored Reader index + Simulator picker, ADR 0038); the
Blade nav mirror has the same dropdown. All use the `--wbench-*` tokens to
match the sibling Alignments management surface and the
[design system](/conventions/design-system.md); pagination is the shared
`Components/LinkPagination.jsx` (prev/next Inertia links preserving
`q`/`page`); alignment display helpers (status badge, similarity
grading) live in `lib/alignmentDisplay.js`.

# Editing (ADR 0015)

The `Edit` page combines a metadata form (Inertia `PATCH` →
`entities.update`) with a sentence manager backed by `@dnd-kit/sortable`.
Sentence CRUD is JSON-driven (mirrors `AlignmentEditorController`): the page
fetches the sentence list from `entities.sentences`, and each mutation
(insert / update / delete / reorder) returns the updated list. Insert and
reorder place through `SentenceOrderService::place` (ADR 0064) with
`after_sentence_id = 0` as the "at the beginning" wire convention and
`null` = append at the end.

**Illustrations (ADR 0050)**: selecting the seeded `illustration` sentence
type in the add form grows an image file input (jpg/jpeg/png/webp/gif,
10 MB; multipart submit) and frees the caption from the non-empty rule;
inline editing of an illustration edits the caption and may replace the
image, and the type is pinned. Files store on the private `local` disk
(`IllustrationStorage`, content-hash named so identical uploads share one
file, reference-counted delete) and serve through `GET /illustrations/{sentence}`
behind `EntityAccessService::canRead`. Illustration mutations flip matches to
`stale` like any sentence mutation.

**Access**: `EntityAccessService::canEdit` mirrors `canRead` — admin bypass;
Restricted editable by grantees; Public editable by any approved user —
**except** an approved entity (`is_approved`), which is editable by admin only
(ADR 0034); its sentence endpoints and the edit page 403 for everyone else,
including the uploader, until approval is lifted.

**Cascade delete**: deleting a junctioned sentence cascades — the sentence
models' `deleting`/`deleted` hooks remove junctions, delete any meaning match
left empty, and update `linked_count`. This diverges deliberately from the
alignment editor's unlink-before-delete rule (422 if linked).

**Match staleness (ADR 0055)**: every sentence mutation — the entities
frontend endpoints and the Filament relation manager alike, through one
write path (`EntitySentenceStore`, ADR 0065) — flips all `EntityMatch` rows
involving the entity (either side) to `status = 'stale'`, surfacing the need
to re-align; only an explicit Re-align / Run from scratch acts on it (the
alignment editor's sentence edits never raise or clear the flag — ADR 0062).
The entity `signature` is intentionally left stale — but the
**text hash** is not: every mutation also bumps `entities.sentences_updated_at`
(model events for Eloquent writes; explicit touches at the bulk sites), which
the `entities:refresh-text-hashes` scheduler uses to rehash (see below).

# Creation pipeline (ADR 0033)

The Library's work-scoped form (`/works/{work}/entities`, work fixed,
language chosen — the only entry point since ADR 0073 removed the
language-first form) runs `App\Classes\EntityCreationService::create()`.
Entity fields are
`name` (required), `label` (optional), `description` (optional), `file`
(optional `.txt`), plus the language and the path's work. The service stores
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
  is dispatched (which zeroes the split progress markers — a re-uploaded
  file always splits from byte 0), chaining `SplitEntityFileSentences` →
  `FinalizeEntityDerivations`: compute the text hash, then copy signature +
  word statistics from a text-hash-equal source if one exists, else generate
  the embedding signature in the background. The splitter runs in bounded
  runs (ADR 0043): each feeds at most 8 byte-chunks to Python, committing
  the inserted sentences together with the resume point
  (`entities.split_offset` + `split_remainder`) in one transaction, then
  re-dispatches itself until the file is consumed; `FinalizeEntityDerivations`
  is dispatched only at end-of-file, so a retry resumes from the last
  committed chunk instead of re-splitting. Finalization then dispatches
  `EnrichEntitySentences` (sentence stress marks / phrasal verbs,
  ADR 0052 — see [Sentence Enrichment](sentence-enrichment.md)); like the
  other derivations it never re-enters `processing`. Uploads are capped at
  10 MB on every path (form requests `max:10240`, Filament `FileUpload
  ->maxSize`).

The `signature` column is never user-entered on the front end. Near-duplicate
merging (the ≥0.95 grant/merge/delete flow of ADR 0013) is gone; the
signature's remaining uses are cross-language candidate finding (Filament
"Find Match") and the ≥0.70 pre-align verification gate.

# Entity status & creation limits (ADR 0044)

`entities.status` is the explicit lifecycle of the upload pipeline:
`processing` from creation (any file upload, or a clone whose exact-copy
source was still mid-pipeline) until the pipeline finishes → `completed`
(split + text hash + signature done); any pipeline job exhausting its 5
retries fires a `failed()` hook that marks a signature-less entity `failed`
(signed entities keep their state — enrichment is optional). An entity
created without a file is born `completed`; scheduled enrichment (word index,
frequency) never re-enters `processing`. The Filament `Signature` action, the
`entity:generate-signatures` sweep, and a Filament file re-upload flip the
entity back to `processing`. The legacy `signatureStatus()` display
derivation (`generated|pending|none`) is untouched and shown beside the
status (a red `failed` badge on both entity surfaces).

Non-admin users hold at most `limits.entities_processing_per_user`
(`config/limits.php`, env `LIMIT_ENTITIES_PROCESSING_PER_USER`, default 2)
entities with status `processing`. The count-then-create runs inside
`EntityCreationService::create()` under the creator's locked user row
(`ProcessingLimits::underCreatorLock`), so parallel submissions cannot both
pass; born-`completed` outcomes (no file, finished clone) skip the check.
Hitting the limit deletes the just-stored upload and redirects back with a
`limit` validation error, shown as a banner on the create form. Approved
admins are exempt (same bypass as `EntityAccessService`).

# Text hash maintenance

`EntityTextHasher` computes `text_hash` = sha256 over sentence contents in
`order`, each whitespace-normalized (case/punctuation preserved). The digest
streams the sentences with an `orderBy('order')->cursor()` + incremental
`hash_update` — never `chunkById` (id order ≠ document order after a
rebalance; the digest would change) — so an entity's whole text is never
hydrated into memory (ADR 0043). Staleness:
`text_hash IS NULL OR text_hashed_at < sentences_updated_at`. The
`entities:refresh-text-hashes` command (scheduled every 5 min with
`withoutOverlapping`, `--limit`/`--dry-run`) dispatches `ShouldBeUnique`
`ComputeEntityTextHash` jobs that re-check staleness at run time. The
alignment-copy lookup (`AlignmentCopyService`) recomputes synchronously when
stale — a local sha256, not a service call. Equal text hashes ⇒ exact copies ⇒
a completed alignment between one copy pair is reused for another (see
[sentence alignment](/domains/sentence-alignment.md)).

# Word knowledge (ADR 0074)

The entity Show page carries a per-user **Word knowledge** percentage (see
the [Library context](../../CONTEXT.md#library-context) for the term):
the occurrence-weighted share of the text's dictionary-linked word
occurrences the viewer knows, `100 × Σ(count × min(familiarity, 60)) /
(60 × Σ count)` over `entity_words` joined to the viewer's `user_word`
rows — 0–60 familiarity maps linearly onto 0–100%, above 60 is fully
known, a word with no `user_word` row counts as unknown, and unlinked
tokens are excluded from both sides. An entity with no linked words has
a null score.

`EntityWordKnowledgeService` computes the score in one aggregate SQL and
stores it in `user_entity_word_knowledge` — one sparse, in-place-updated
row per (user, entity), created the first time that user opens the page
and refreshed whenever it is stale: stale means computed before the
entity's word list was last rebuilt (`computed_at <
entities.words_indexed_at`, which sentence edits cause via the
`crossword:refresh` sweep) or older than three days (familiarity drift
from reads/lookups/crosswords). `ensure` refuses to compute while
`EntityWordIndexer::isStale` — the page then shows a "calculating" state
— and a viewer with zero `user_word` rows sees their 0% with a hint
linking to the word test. The scheduled `entities:refresh-word-knowledge`
command (every 5 min, `withoutOverlapping`, `--limit=100`) recomputes
stale pairs without a visit, skipping entities whose index is
mid-rebuild. The table's only consumer beside the Show page is the
[Recommendations](/domains/recommendations.md) page (ADR 0075), which
reads it without ever writing.

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
`EntityAccessService::readableQuery` / `readableConstraint` (matches via
`readableMatchConstraint`); the detail pages
403 accordingly, and reading an `EntityMatch` in the simulator requires grants
on **both** of its Entities (see ADR
[0013](../../docs/adr/0013-default-restricted-uploads-and-per-entity-grants.md)
/ [0014](../../docs/adr/0014-per-entity-grants-require-both-sides-for-simulator.md)).
Works themselves are a public catalog (ADR 0021) — a work with zero readable
entities still appears in every user's works lists, revealing nothing about
the restricted entities it may hold.
