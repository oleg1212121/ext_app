# 0039 - The Library dropdown and the works URL branches

The Library was a single navbar link to `/library`, and a work's page
(`/library/{work}`) packed its texts and its entity matches behind
`?tab=entities|alignments`. Two problems: the navbar gave no hint the section
had three distinct browsing goals (find a work, browse texts, browse
alignments), and a tab cannot be linked, shared, or deep-linked from other
surfaces without carrying a query param. The owner reworked the section into
a three-entry dropdown over a dedicated `/works` URL space.

Decisions:

- **A "Library" dropdown with three children: Works, Entities, Alignments.**
  The label "Library" survives as the section name (the glossary term stays);
  the children are `nav.works` / `nav.entities` / `nav.alignments`. The React
  navbar's generalized dropdown machinery (ADR 0038) takes the group as-is;
  the Blade mirror gets the same dropdown and mobile accordion — which also
  closes the gap where the Blade nav never exposed the Library at all.
  Because all three children share the `/works` prefix, navbar active-state
  cannot use naive prefix matching: each child carries an explicit URL match
  rule (Works: the catalog, `/works/create`, and work landing pages;
  Entities/Alignments: their branch lists and per-work branch pages), and the
  Blade side uses `routeIs` groups with the same split.
- **The URL space moves to `/works`, tab-free.** `GET /works` (catalog),
  `GET /works/entities` and `GET /works/alignments` (branch works-lists —
  the same public catalog with entity / alignment counts and cards targeted
  at the matching branch page), `GET /works/{work}` (a work landing page:
  catalog metadata plus readable counts linking to both branch pages),
  `GET /works/{work}/entities` and `GET /works/{work}/alignments` (the former
  tab contents as real pages), and the unchanged create/store forms re-prefixed.
  `whereNumber` on `{work}` keeps the static branch lists disjoint from work
  ids. Route names move `library.*` → `works.*`; the controller class stays
  `LibraryController` (the section is still the Library — renaming was churn).
- **Work landing pages instead of card → tab.** Cards on `/works` lead to
  `/works/{work}`, which shows the work's metadata and its readable
  entity/alignment counts, each linking to the branch page. The per-work
  counts are computed with a `Work::alignments` hasMany-through relation
  (via the A-side entity — canonical side order puts the A-side of every
  same-work pair in that work) constrained by the new
  `EntityAccessService::readableMatchConstraint`, extracted from
  `readableMatchQuery` so `withCount` can reuse the same readability rule.
- **Branch lists show all works.** The Works catalog is public (ADR 0021);
  the Entities/Alignments branch lists keep that property — counts are
  readable-only, so a zero-count work reveals nothing about its restricted
  content, and a work with no matches is still listed (its alignments page
  keeps the create-match entry point).
- **Old `/library/*` URLs are removed outright, no redirects** — the owner
  called them obsolete and the section never shipped long. The pre-existing
  legacy redirects `/entities` and `/entities/{lang}` are retargeted to
  `/works/entities` (their original target moved).

Alternatives considered: keeping a single work page with tabs was the thing
being replaced; branching via a `?view=` query param was rejected — the two
surfaces would not get distinct addresses; a symmetric `/library/works|alignments`
prefix was dropped when the owner reshaped the section to `/works`;
redirecting `/library/*` was rejected as coddling dead URLs.
