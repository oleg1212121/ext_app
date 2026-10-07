# ADR 0073: Entity routes move onto the work branch

Date: 2026-10-07
Status: Accepted

## Context

An entity's page addresses carried a language segment —
`/entities/{lang}/{entity}` for the view, `/entities/{lang}/{entity}/edit`
for the editor, plus a flat JSON sentence API (`entities.sentences.*`), the
approval toggle, and the metadata PATCH under the same prefix. The segment
was a leftover from the days before the `/works` URL space (ADR 0039): the
language it names is already a property of the entity itself
(`entities.language_id`), and the page header even displays it. Worse, the
segment was load-bearing for lookup only — `EntityController` resolved it to
a Language and 404'd when it did not match the entity — so `/entities/ru/20`
and `/entities/en/20` were two addresses for one resource, one of them
always a 404.

Meanwhile the rest of the entity lifecycle already lives on the work branch:
the per-work list (`/works/{work}/entities`) and the create form
(`/works/{work}/entities/create`, ADR 0039). And the alignment editor had
just moved onto the work branch too (ADR 0072), setting the pattern: the
URL names the work, controllers verify membership, old flat routes die
without redirects.

`entities.work_id` is a NOT NULL foreign key — an entity belongs to exactly
one work — so a work-nested entity URL is unambiguous and the work segment
is fully verifiable server-side.

Constraints:

1. Every entity URL must name the resource without a redundant segment;
   wrong-work URLs must 404 like wrong-work alignment URLs do.
2. The per-work entities list already owns the route names
   `works.entities.show`/`works.entities.index`; nothing can reuse them.
3. Single-user, auth-gated tool: no external deep links to preserve.

## Decisions

### 1. The whole entity surface nests under the work: `/works/{work}/entities/{entity}...`

View (`entities.show`), edit (`entities.edit`), metadata PATCH
(`entities.update`), approval toggle (`entities.approved.update`), and the
five sentence JSON endpoints (`entities.sentences.*`) all move to the
work-nested prefix. The language segment is gone; the language is read from
the entity. Controllers bind `Work $work, Entity $entity` and verify
membership with `abort_unless($entity->work_id === $work->id, 404)` — the
ADR 0072 pattern (there the match had to be reached through its A-side
entity; here the column is right on the entity).

Alternatives rejected: moving only the GET pages and leaving the JSON API
flat (splits one surface across two conventions — the exact mess ADR 0072
removed for alignments); scoping the route binding
(`entities() ->whereNumber` nested binding) in favor of the explicit
controller check used everywhere else.

### 2. Old flat routes are deleted without redirects — including flat create/store

`/entities/{lang}/{entity}...` 404s, pinned by test. The flat create/store
pair (`GET /entities/{lang}/create`, `POST /entities/{lang}`) — a survivor
of the ADR 0039 move kept for deep links — dies with them: its React page
(`Entities/Create`) duplicated the work-nested `Library/CreateEntity` form,
nothing linked to it, and its unique behaviors (inline "new work" fields)
are retired with it. The two language-first *browse* redirects
(`/entities`, `/entities/{lang}` → `/works/entities`) predate this ADR and
stay.

Alternatives rejected: 301/308 redirects for the GETs (a method-preserving
308 across six mutation endpoints would be dead weight for a surface where
every in-app entry point is updated in the same commit); keeping the flat
store as an alias.

### 3. Route names keep the `entities.*` prefix

`entities.show`, `entities.edit`, `entities.update`,
`entities.approved.update`, `entities.sentences.*` keep their names — only
the URIs change. The nested alternative (`works.entities.detail` &c.) reads
better but `works.entities.show` (per-work list) and `works.entities.index`
(global browse, ADR 0039) already occupy the namespace, and renaming
`entities.sentences.*` would churn the page prop, the editor's fetches, and
six PHP call sites for no behavioral gain. The asymmetry with the
alignment editor (whose JSON endpoints are unnamed) is accepted.

### 4. Back links name the work branch

The entity page's back link lands on the work's Entities page —
`/works/{work}/entities` — labeled "← {work title} entities": the list
mixes languages by design (ADR 0039), so the old "← Russian entities"
wording (which pointed at the *global* browse page via a redirect, losing
both work and language context) would be misleading. The entity's language
moves into the page subtitle. The editor's back link and cancel link land
on the entity's view page ("← Back to {name}"), as before. No language
filter is added to the list.

## Consequences

- `/works/4/entities/20` and `/works/4/entities/20/edit` are the canonical
  addresses; `/entities/{lang}/{id}` 404s (test-pinned).
- The wrong-language 404 becomes a wrong-work 404; the URL's only scope is
  the work, and it is verified on every action.
- `EntityController` loses `create`/`store` and the language-resolution
  helpers; `Entities/Create.jsx` and `StoreEntityRequest` are deleted; the
  14 ui-strings keys that only the create form used go with them.
- `LibraryController::redirectFromCreation` routes through the created
  entity's `work_id` — store redirects and the page's links all build from
  payload `work_id`, as the alignment editor does.
- NavBar highlighting needed no change: its Entities-branch matcher already
  matched `/works/{id}/entities` prefixes of any depth.
