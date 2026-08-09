# Empty meaning matches persist

Status: accepted

An empty meaning match (zero junctions on both sides) is now a persistent,
legitimate state. Previously the app treated it as garbage: `AlignmentEditorPersister`
dropped rows empty on both sides, and the `EnEntitySentence` / `RuEntitySentence`
deletion hooks deleted a meaning match the moment **either** side hit zero
junctions. The new Alignments editor (see ADR 0002) creates empty rows on demand
and lets humans unlink sentences, so both behaviors were changed: deletion cleanup
now fires only when **both** sides are empty, and the persister keeps junction-less
rows. This makes the invariant app-wide — the Filament editor save path no longer
silently destroys empty pairs created in React.
