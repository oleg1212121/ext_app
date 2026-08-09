# React alignment editor with immediate persistence

Status: accepted

A new Inertia/React "Alignments" editor (`/alignments`, `/alignments/{entityMatch}`)
is the primary editing surface for meaning matches, replacing the Filament
`EditEntityAlignment` page as the top-menu entry point. Unlike the Filament editor's
session-draft + full-rebuild persister, it writes immediately through surgical
endpoints (create/delete meaning match, add/edit/unlink/hard-delete sentence, DnD
move) that touch only affected rows. `sentence.order` is the single ordering truth
for in-row sequence; junction `order` mirrors it so existing consumers
(`MeaningMatchPresenter`, `AlignmentEditorPresenter`) keep working unchanged. The
Filament resource and editor remain functional in parallel for admin use. Empty
meaning matches persist (see ADR 0001), structural edits reset a row's similarity
to 1.0, and `linked_count` counts all meaning matches.
