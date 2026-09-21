# Exact-copy cloning and hash-based alignment reuse

ADR 0013 detected duplicate uploads by embedding similarity: a synchronous
Python `/embed` call blocked the upload request (and hard-failed it when the
service was down), and a ≥0.95 same-language cosine match discarded the upload
in favor of an access grant on the existing entity — with an async second pass
that deleted near-duplicates and migrated grants. Meanwhile a whole-book
alignment between two entities takes roughly half an hour, and identical texts
were being re-aligned from scratch every time a second copy appeared.

We replace similarity-based deduplication with **exact-copy detection by
hash**:

- Every entity carries a **text hash** — sha256 over its sentence contents in
  document order, each sentence whitespace-normalized (case and punctuation
  preserved; this stays an *exact* copy check) — maintained by an explicit
  `sentences_updated_at` / `text_hashed_at` staleness pair. Every sentence
  mutation (including the bulk writers that bypass model events: the splitter,
  the importer, the alignment-editor persister, reorder) bumps
  `sentences_updated_at`; a scheduled `entities:refresh-text-hashes` command
  (every five minutes, mirroring `crossword:refresh`) dispatches unique
  per-entity rehash jobs, and any code needing a hash *now* (alignment-copy
  lookup) recomputes it synchronously — it is a local sha256, no service
  dependency. An explicit timestamp is required rather than a
  `max(sentences.updated_at)` check because deletions leave no trace to
  compare against.
- Uploads also take a **file hash** (raw bytes) at creation. A byte-identical
  re-upload skips the entire pipeline: the uploader gets their **own**
  restricted entity — their metadata, their work — cloned from the existing
  one (sentences, embedding signature, word statistics, hash), with no Python
  calls at all. A text hash match discovered after the split copies the
  signature and word statistics, skipping the embed.
- The embedding signature survives only as a candidate finder (Filament "Find
  Match" cross-language suggestions and the ≥0.70 pre-align verification
  gate), generated in the background after creation. Uploads never call the
  Python service synchronously and never fail because of it.
- Creating an entity match between exact copies **copies a completed
  alignment** from an existing pair (equal hashes, equal languages, either
  orientation) instead of running the pipeline: meaning matches and junctions
  are cloned with a positional sentence mapping (the Nth sentence of a copy
  corresponds to the Nth sentence of its source), human-confirmed landmarks
  included; the match is `completed` at creation. The source with the most
  human-confirmed rows wins. Any structural mismatch (sentence counts, an
  unmappable junction) falls back to the normal pipeline.

## Consequences

- **Supersedes ADR 0013's deduplication flow.** Near-duplicate (0.95–1.0
  cosine) uploads now coexist as independent entities; grants are no longer
  auto-created by uploads, and `ProcessEntityFile` no longer deletes
  "duplicates" or migrates grants. Existing data is untouched — no mass
  cleanup — and deleting an entity never touches its copies: clones share no
  foreign keys and no provenance column (deliberately — copies are fully
  independent).
- **Exact copies have identical sentence sets by construction.** Clones
  receive the source's sentences verbatim, so their text hashes match and
  alignment reuse works even if the splitter is upgraded later (a fresh
  upload re-split by a new splitter version may hash differently from a
  legacy entity; accepted — copy detection between old and new versions may
  miss).
- **Upload UX**: an upload of an existing text now lands the user on *their
  own* entity page (previously: a grant on someone else's entity). Entity
  lists may show several copies of the same text, told apart by uploader and
  label.
- **Timing**: a new upload becomes alignable only after the background
  signature job finishes (`alignableWorks` already filters unsigned entities).

## Considered options

- **Keep the sync embedding dedup and add hashes alongside** — rejected:
  uploads stay coupled to Python availability, and near-dup merging kept
  destroying user uploads that were not duplicates.
- **One hash column (text hash only) checked before the split** — rejected:
  comparing against sentence-derived hashes requires sentences, so the split
  would have to run before detection and the "skip splitting for identical
  files" win would be lost.
- **Aggressive normalization (case/punctuation-insensitive)** — rejected by
  the user: risks false "exact copies" between genuinely different editions.
- **A `copied_from` provenance column on copied alignments** — rejected by
  the user: copies must be independent; deletion of a source must not affect
  anything else.
