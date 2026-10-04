# ADR 0062: One alignment-editing domain

Date: 2026-10-04
Status: Accepted (amends ADR 0002's two-editor split)

## Context

ADR 0002 left the project with two alignment editors over the same tables:
the Filament draft editor (`EditEntityAlignment` + `AlignmentEditorPersister`
+ `AlignmentEditorDraftStore`, session-draft mutated in place, full-rebuild
persist) and the React editor (`/alignments/{id}` over the surgical
`AlignmentEditorController` endpoints, immediate persistence). Four years of
feature work went into the React editor — drag-and-drop with drop slots, the
needs-review list, approve-as-landmark, optimistic UI, 39 API tests — while
the Filament editor stagnated: no feature commits since the 2026-09-10
schema cutover, dead helpers from the pre-sparse-order era, Save/Discard
buttons not wired to any UI, and destructive quirks the React editor does
not have (a blanked sentence silently hard-deletes on save; illustration
sentences are not exempt from the persister's deletion sweep). Every
user-facing surface links into the React editor; nothing links into
Filament. Meanwhile the alignment invariants — `linked_count` resync, the
image-less `a/b_total_sentences` recount, the `-1` human-made
`alignment_chunk` sentinel, `nextAlignmentChunk` — were re-implemented at
~10 write sites across the editor controller, the aligner job and
`SentenceAlignmentService`, the importer, `AlignmentCopyService`, and the
repair command, in four spellings, with the chunk helper existing as two
byte-identical private copies.

## Decision

- **One editing surface.** The Filament draft editor is deleted: the page,
  persister, session draft store, presenter, page blade and
  `alignment-sentence-editor` partial, and their tests. The Filament
  `EntityMatch` resource remains the operations console — list, Re-align,
  Run from scratch, publish/delete — and its "Edit alignment" action (and
  the `ViewEntityMatch` header action) open the React editor via
  `route('alignments.show', ...)`. The read-only Filament view page and the
  shared `alignment-pagination` partial stay.
- **Editing never completes a match.** The persister's
  `status = 'completed'` write disappears with it: editor edits leave a
  stale match stale; only Re-align / Run from scratch / a sentence re-import
  clear or complete it. This makes ADR 0055's philosophy consistent instead
  of editor-dependent.
- **Invariants live on the models.** `MeaningMatch::HUMAN_CHUNK`,
  `EntityMatch::nextAlignmentChunk()`, `EntityMatch::syncLinkedCount()`,
  `EntityMatch::recountTotals()` / `syncTotals()`; every writer listed above
  routes through them. Drift between spellings is no longer possible; a
  new writer calls one method instead of copying a formula.
- **A `Side` enum** (`App\Enums\Side`, backed `a`/`b`) owns the side-key
  mapping — `sentencesKey()`, `unmatchedKey()`, `totalColumn()`,
  `entityFor()`, `other()` — replacing the ~26 hand-written
  `$side === 'a' ? … : …` ternaries in the editor classes. The junction
  `side` column and all JavaScript payloads stay string-based.
- **One mutation service.** `AlignmentEditorService` owns the editing
  domain — row create/delete/approve, sentence add/update/unlink/delete,
  move with its placement engine (`sideLayout`, `placeSideSentence` with the
  two-phase negative-park order write, `placeMovedWithinRow`,
  `placeIntoEmptyRow`, `sideAnchorOrder`), transactions, and the invariant
  helpers — while `AlignmentEditorController` keeps only HTTP concerns:
  access gates, FormRequest validation, exception mapping, and the mutation
  envelope. `AlignmentEditorApiTest` passing unmodified is the acceptance
  bar.
- **Entity-frontend sentence mutations resync totals.** The entity CRUD
  paths (`EntityController`, `SentencesRelationManager`) already flipped
  matches stale without touching `a/b_total_sentences`, so the editor header
  showed outdated counts until a Re-align; they now call
  `syncTotals()` per affected match alongside the stale flip.

## Consequences

- ~1,600 lines of draft-editor machinery deleted; the mutation service has
  exactly one client, and the destructive quirks (silent hard-delete of
  blank sentences, illustrations not exempt) are gone with it.
- Session drafts (`alignment_editor_draft.*` keys) simply expire — no
  migration or cleanup needed; there was no data to carry over.
- A Filament user who used the draft editor's bulk-edit-then-save flow now
  works surgically in the React editor; per-mutation persistence replaces
  the single transaction, which is the trade ADR 0002 already accepted for
  the primary UX.
- The totals recount after entity-frontend edits costs one extra pair of
  `COUNT` queries per mutation — negligible against the stale-flag update
  the same endpoints already perform.
- Tests: `EntityIllustrationAlignmentTest`'s draft-apply totals test becomes
  a direct `syncTotals()` test (same ADR 0050 invariant); the persister/
  draft-store/page suites die with their subjects; all other suites pass
  unmodified.
