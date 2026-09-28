# ADR 0050: Illustrations are sentences

Date: 2026-09-28
Status: Accepted

## Context

Books carry inline illustrations; this app's texts did not. The user wants to
add images to an entity (the per-language text), match them across editions
through meaning matches in the alignments editor, and see them inline in the
reader and the simulator.

A meaning match's participants are strictly `entity_sentences` rows joined
through `sentence_meaning_matches` under ADR 0048's invariants (junction
uniqueness, total completeness, document-order resequencing). Every alignment
machine — chunking, cursors, repair, the needs-review convention of ADR 0049
— operates on that sentence stream. There was no image anywhere in the stack:
no columns, no upload path (entity uploads are txt-only), and no `<img>` in
any reading surface.

## Decision

An **illustration is a sentence**: an `entity_sentences` row whose non-null
`image_path` marks it (new columns `image_path`, `image_hash`, `image_width`,
`image_height`, `image_mime`), seeded with the new `illustration` sentence
type. Its text content is the optional caption; `content` stays NOT NULL and
empty captions are the empty string.

The alternatives were rejected because each one forks machinery this design
gets for free:

- **A separate `entity_images` table** interleaved into the text would need a
  second stream merged into alignment chunking, the junction/completeness
  invariants, the repair command, reader/simulator pagination, and word-map
  scoping.
- **Work-level covers** are not inline images at all.

Consequences that follow from the choice:

- **The aligner works in image-less sentence space.** Illustrations never
  enter a chunk window, a count, or a cursor (`AlignEntitySentences` filters
  them in `sentenceSlice`, `sentenceIndex`, `rollbackOffset`, and the
  begin-time totals; `a_total_sentences` counts alignable sentences only).
  Captions are not sent to the Python `/align` endpoint — the DP cannot
  steal a wrong junction for an image via its caption. At completion,
  `finalize()`'s total-completeness repair (ADR 0048) backfills every
  illustration as a **single-sided row at similarity 0.0**, which lands it in
  Needs review per ADR 0049; humans pair illustration↔illustration by hand
  and the editor writes the 1.0 that retires the row from review.
- **Meaning matches group illustrations with anything** (illustration↔text,
  single-sided included): the junction is sentence-level and gains no
  validation, so real edition differences (a picture one language lacks) need
  no special cases.
- **Uploads happen only on the entity edit page** — the sentence manager's
  add form grows an image file when the illustration type is selected; the
  alignment editor moves and links them like any sentence.
- **Files live on the private `local` disk** at
  `illustrations/{lang}/{sha256}.{ext}`, served through
  `GET /illustrations/{sentence}`, which gates on
  `EntityAccessService::canRead` for the owning entity — a Restricted
  entity's illustrations stay unreachable without a grant (same-origin
  `<img>` requests carry the session cookie, so no signed URLs are needed).
  Content-hash naming means identical uploads (the same scan in two
  editions) share one file; deletion is reference-counted.
- **Constraints**: jpg/jpeg/png/webp/gif, 10 MB cap (the entity-upload cap);
  dimensions recorded at upload via `getimagesize` so reading surfaces can
  reserve the aspect ratio; no thumbnails or image processing (no such
  dependency in the stack).
- **The text hash includes the image hash** (appended to the sentence's
  line), so texts differing only in their pictures are not Exact copies and
  alignment-copy reuse never clones across different scans. Image-less
  sentences hash byte-identically to the old recipe, keeping pre-illustration
  hashes valid.
- **Reading surfaces render per side**: `MeaningMatchPresenter::toSimulatorRows`
  drops illustration contents from row text, and the new `toSimulatorImages`
  returns a row-aligned `[aImages, bImages]` payload; reader and simulator
  render each side's images in document order inside that side's column (so
  a picture both editions carry shows in both columns). The Filament
  diagnostic viewer keeps showing captions as text.

## Consequences

- Adding an illustration to an already-aligned entity flips its matches to
  `pending` (the existing sentence-mutation rule), signalling a re-align;
  human rows survive re-runs via landmark preservation.
- Caption tokens are not part of the reader's page word maps (the word map
  scopes by row text), so caption words are not Ctrl-clickable.
- `a_total_sentences` now means "alignable sentences" and can be lower than
  the entity's sentence count on the alignments editor header.
