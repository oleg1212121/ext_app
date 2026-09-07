# Document order is the single ordering truth in the alignment editor

Status: accepted

Commit `b197ae5` ("drag and drop fixes", Aug 2026) dropped the junction `order`
column and made `sentence.order` the only ordering surface, but left two gaps
that produced real editor bugs: cross-row drops ignored the drop index
(`link()` never renumbered, so a moved sentence landed wherever its stale
document order sorted, and dragging it back never repaired anything — rows
could permanently show sentence number 2 above sentence number 1), and edge
renumbering was unbounded (`between(null, $order)` = ±1024 could leapfrog the
adjacent rows' sentences, scrambling the global numbering).

Decision (confirmed with the product owner): **the drop position wins**. Every
drag — within a row, across rows, or from the unmatched pool into a row —
renumbers the moved sentence's document order so it sorts exactly where it was
dropped:

- `AlignmentEditorController::reorderRowJunctions` clamps its `between()`
  neighbors by the destination row's global bounds (the nearest sentences
  outside the row's span), so a drop at a row edge lands in the inter-row gap
  instead of leapfrogging the adjacent row. The moved sentence's stale order is
  excluded when deriving those bounds, so a sentence arriving from a far-away
  row cannot widen the span a fallback `spreadOrders()` is confined to.
- A drop into a row that is empty on that language side places the sentence
  between the closest populated rows (`emptyRowPlacementOrder`), so moving a
  sentence back to its own row restores a numbering monotonic with row order.
- Row → unmatched keeps the document order (a membership change only).
- The editor UI renders an explicit drop slot above the first, between every
  pair, and below the last sentence of each column (a tall standalone slot when
  the column is empty). Slots are permanently visible as thin, faint, dashed
  lines, so the page structure never changes when a drag starts. The slot the
  pointer rests on lights up, and a drop lands exactly there: slot boundary
  `n` maps to insertion index `n`, clamped to the last "other-sentence"
  position (so a drop on the bottom slot is the real last position, never one
  early). While a drag is in progress, the two slots directly above and below
  the dragged sentence (inside its own column, which still shows it at its home
  spot) are rendered as inert spacers — they would otherwise mean "keep it"
  and "move it one down", which reads as a no-op or an off-by-one. Dropping
  back onto the sentence itself is a no-op. Container lists are not mutated
  while the drag is in progress — the highlighted slot is the only feedback,
  and the drop writes the new ordering exactly once, so the pointer can never
  chase a slot the preview just pushed out from under it (that chase was the
  cause of an unbounded setContainers loop, React error #185). Direction-
  agnostic — the drag direction is never consulted. This replaces the earlier
  "hovered sentence" and "empty-padding pointer-edge" readings, which had no
  visible target and repeatedly landed the drop one position early. (2026-09-05
  addendum, fifth and final form; supersedes the "direction default — first
  when moving down, last when moving up" spec 3.4/3.5 and the subsequent
  pointer-edge / hovered-item / live-preview amendments.) A drop into a column
  holding no sentences at all lands at position 0.

Consequences: junctions stay pure association tables with no `order` column;
the per-sentence badge keeps showing the global document rank; a drag
physically reorders the document that the reader and a later Re-align consume
(Re-align re-creates machine rows from the edited order; approved rows keep
their grouping as landmarks). Supersedes ADR 0005.
