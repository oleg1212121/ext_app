# ADR 0075: A recommendations page re-ranks the viewer's opened texts by word knowledge

Date: 2026-10-07
Status: Accepted

## Context

The entity detail page shows a per-user **Word knowledge** percentage (ADR
[0074](0074-entity-word-knowledge.md)) for the one text being viewed, but a
learner has no surface that turns those numbers into a reading queue: "which
of the texts I've opened can I read comfortably right now, and which of
those still teaches me something?" The natural place to build it is on top
of the snapshot table ADR 0074 introduced
(`user_entity_word_knowledge`, one row per viewer × text, created when the
viewer first opens the text).

Constraints that shaped the design:

1. **The only difficulty signal is the snapshot table.** Computing scores
   live for every readable text would be a per-view aggregate over
   `entity_words × user_word` per entity — a different cost class from the
   detail page's one-entity computation.
2. **The snapshots are sparse by design and nothing backfills them.** The
   scheduled sweep (`entities:refresh-word-knowledge`) only refreshes rows
   that already exist; a row is born the first time that viewer opens that
   text. Any surface that only reads the table can therefore never mention
   a text its viewer has not opened.
3. **"Percentile" stays an avoid-term** (ADR 0074 decision 1): the metric
   is a per-user percentage, not a rank against other users.

## Decisions

### 1. Word knowledge, not a new metric

The page filters and sorts by the exact number the entity detail page
shows — the ADR 0074 word-knowledge percentage. No new computation, no new
aggregation, no cross-user comparison. The threshold compares against
`user_entity_word_knowledge.score` directly.

### 2. Read the snapshot table only — no backfill

The page performs zero writes: it never computes missing scores and never
bulk-inserts snapshots, so a fresh viewer sees an empty page until they
open texts elsewhere. This makes the page a **re-ranking of previously
opened texts**, not a discovery tool — accepted deliberately. Alternatives
rejected: a per-visit backfill of every readable entity (a heavy first
load and a write burst per user per language, adopting a job that would
need its own throttling and staleness story), live per-view computation
(the cost class above), and ranking only entities with snapshots *without
saying so* (the empty page would read as a bug — the empty state says
plainly that recommendations rank texts the viewer has opened).

### 3. Minimum threshold, ascending — easy-read semantics

The knowledge filter is a **minimum**: the default 90% lists texts the
viewer knows at 90–100%, ordered **least known first**. Everything on the
page is comprehensible; the ordering maximizes new-word exposure within
that set — comprehensible-input reading (i+1), not a challenge finder.
The parameter is clamped to 0–100; a non-numeric value falls back to 90.

### 4. Works are the rows; a work sorts by its weakest qualifying text

Rows are works (15 per page, the Library list convention), each expandable
to its qualifying texts ordered by knowledge ascending. A work is ordered
by `MIN(score)` of its qualifying texts, so the page reads as one global
least-known-first list top to bottom. Pagination counts works: paginating
texts would split a work's dropdown across pages. Ties break on title.

### 5. Unrankable texts are excluded silently

A text without a non-null snapshot, a null score (no dictionary-linked
words), a word list mid-rebuild or never built (the detail page's
"calculating" state), or a non-completed upload never appears. The page
also applies the entity read-access rules (`EntityAccessService`), so a
restricted text without a grant is out even if a snapshot exists — and a
restricted text the viewer *could* open (granted) stays listed.

### 6. Top-level nav item after Resources

"Recommendations" is a personalized surface, so it sits as a top-level
navbar item after the Resources dropdown (reference tools), before Admin —
not inside Resources, whose entries are static references. Route:
`GET /recommendations`, `recommendations.index`, inside the
`auth + approved` group like every other user surface.

Search matches work title/author **or** the qualifying texts' name/label —
any hit surfaces its work. The language filter defaults to English and
accepts only enabled language codes (unknown code → 404), matching the
reader's resolution rule.

## Consequences

- A viewer who has never opened a text sees an empty page with a pointer
  to the Library; the word-test hint is added when they have no
  `user_word` rows at all (same pairing as ADR 0074's blank slate).
- Opening a text feeds the page: its snapshot is computed on that first
  detail-page view, and the next recommendations load ranks it. No new
  data path exists between the two pages.
- Texts the viewer never opens remain invisible here — discovery is out
  of scope until a backfill decision is made (a future ADR).
- Filtering/sorting happens in SQL over the snapshot table with the
  mid-rebuild guard mirrored from `EntityWordKnowledgeService::refreshStale`;
  the two staleness definitions must move together if either changes.
- The 89.99-vs-90 boundary is exact string-decimal comparison against the
  stored two-decimal score — the same number the detail page renders.
