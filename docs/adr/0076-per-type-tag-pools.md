# ADR 0076: Tag pools are per-type — four definition tables and `tagged_<model>` pivots

Date: 2026-10-11
Status: Accepted

## Context

Content needs admin-curated labels: words, works, entities, and entity
matches (Sentence Alignments) each want a small set of named tags — "most
used", "science", "beginner-friendly" — managed in the Filament panel. A
words-only scaffold already exists (the `tags` table, the `word_tags` pivot,
a bare `Tag` model, `Word::tags()`) but is dormant: zero callers, zero UI,
an empty table.

Constraints that shaped the design:

1. **The four content types need different vocabularies.** A word tag
   ("irregular verb") has no meaning for a work ("public domain") or an
   entity match ("hard pairing"). No shared vocabulary across types.
2. **House style is explicit pivots.** The codebase has zero polymorphic
   relations; a shared `taggables` morph pivot would be the first, and would
   couple all four types to one table's indexes and constraints.
3. **Tags must not block learner-facing filtering later**, but v1 ships
   admin UI only — no React badges, no learner filters.
4. **The dormant pivot's name collides one letter away from the future.**
   The words pivot `word_tags` sits next to the works definition table
   `work_tags` — one letter apart, two entirely different things.
5. **Labels are language-neutral.** Unlike WordClass (per language, unique by
   `(language, slug)`), a tag such as "science" describes content across all
   languages; one name + slug per tag, no per-language variants.
6. **A tag applies only to the exact row it is attached to.** No
   inheritance: work tags do not flow to the work's entities or alignments.
7. **Ship empty.** Vocabulary is authored through the admin panel; no
   seeders.

## Decisions

### 1. Four separate pools, one definition table + pivot each

Each content type gets its own definition table and its own pivot:

| Pool | Definition table | Pivot | Model | Relation |
|------|------------------|-------|-------|----------|
| Words | `tags` (existing) | `tagged_words` | `Tag` | `Word::tags()` |
| Works | `work_tags` | `tagged_works` | `WorkTag` | `Work::tags()` |
| Entities | `entity_tags` | `tagged_entities` | `EntityTag` | `Entity::tags()` |
| Alignments | `alignment_tags` | `tagged_alignments` | `AlignmentTag` | `EntityMatch::tags()` |

No shared table, no polymorphism (constraint 2), no inheritance (constraint
6). Ticket 01 builds the words pool; the other three copy it.

### 2. Pivots are named `tagged_<model>`

The words pivot is renamed `word_tags` → `tagged_words` (with its unique
pair index renamed to `uk_tagged_words_pair`), killing the one-letter
collision with the future `work_tags` definition table (constraint 4). All
pivots follow the scheme — `tagged_works`, `tagged_entities`,
`tagged_alignments` — so a pivot is never confusable with a definition
table: `tagged_*` attaches, plain `<type>_tags` defines.

### 3. Single language-neutral slug + name per tag

Every tag is one `slug` (unique, 100) + `name` (256) — no language scoping,
no translations (constraint 5). Word tags are global across languages.

### 4. Admin-curated with inline create

Each pool gets a dedicated Filament resource owning its vocabulary (edit,
delete, bulk delete, usage count). The attach surface is a searchable
multi-select on the owner's form with inline tag creation — so an admin can
tag without leaving the form — but inline *editing* of a tag is deliberately
not offered; the tag resource owns curation.

### 5. Ship empty

No seeders (constraint 7). The dormant scaffold's table was empty, so the
rename carries no data anywhere.

### 6. Alignment tags are orthogonal to the status lifecycle

Alignment tags are flat labels on an entity match, independent of the
pending/stale/aligning/completed/failed status machine — a tag never drives
processing and processing never clears a tag.

## Consequences

- `tagged_words` pivot rows now carry `created_at`/`updated_at`
  (`Word::tags()->withTimestamps()`), matching the migration's existing
  timestamp columns that were never filled before.
- The four pools stay structurally parallel: a new pool is a copy of the
  words pool with a new table pair, model, and resource — no cross-type code.
- Learner-facing filtering (badges, tag-based text lists) is open later —
  the data model imposes nothing — but nothing ships for learners in v1.
- Renaming `word_tags` is safe because the scaffold was dormant (zero
  callers verified before the rename); the Postgres FK constraints follow
  the table rename.
