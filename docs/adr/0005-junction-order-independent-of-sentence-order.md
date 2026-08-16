# Junction order is independent of sentence order

Status: accepted

The React Alignments editor (ADR 0002) used `sentence.order` as the single ordering
truth for in-row sequence, with the junction `order` mirroring it. Dragging a
sentence within a row therefore rewrote the sentence's entity-global order. The
sparse re-spread of the row (`SparseOrderService::spreadOrders`) widens gaps by a
stride of 1024, so a dropped sentence's order jumped (e.g. 3 → 1024) and stopped
reflecting its position in the original text. The alignment pipeline
(`AlignEntitySentences`) consumes sentences strictly ordered by `order`, so the
corrupted document order pushed the sentence to the tail of the next alignment
chunk and it matched against the wrong sentences.

The two concerns are now decoupled:

- **`sentence.order` is the document order** — the sentence's position in the
  original text. It is immutable in the alignment editor and is the order the
  pipeline and the reader rely on. A future sentence-reordering feature (or the
  existing entity *Sentences* tab / import path) is the only way to change it.
- **junction `order` is an independent within-row sequence** — the display order
  of a row's sentences. Dragging within a row, linking into a row, and appending
  a new sentence rewrite junction orders only, using the row-local sparse order
  helpers. Consumers (`MeaningMatchPresenter`, `AlignmentEditorApiPresenter`,
  `AlignmentEditorPresenter`) already sort junctions by junction order and are
  unaffected.

Consequences: within-row switching never corrupts document order, so re-align
places sentences correctly. Because re-align deletes machine rows below the
landmark threshold and re-creates them in document order, a within-row switch on
a non-approved row is reset by the next re-align; approving a row keeps its
arrangement. New sentences added in the editor are placed at the target row's
document boundary (its rightmost sentence in document order) while their junction
appends at the row's end. Existing corrupted orders in the database were not
repaired by this change. Supersedes the mirrored-ordering claim in ADR 0002.
