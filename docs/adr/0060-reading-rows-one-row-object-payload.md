# ADR 0060: Reading rows — one row-object payload for the reading surfaces

Date: 2026-10-03
Status: Accepted (amended 2026-10-04)

## Context

The reader and the simulator consumed five index-parallel arrays — `rows`,
`rowImages`, `rowKeys`, `stressedRows`, `phrasalRows` (snake_case on the
simulator's `/text` endpoint) — whose row-alignment, `"\n"`-join convention
and three-level null semantics were caller knowledge, enforced nowhere. The
friction was measured and shipped:

- Adding one per-sentence annotation cost ~14 files across two surfaces
  (traced on intonation's add/remove cycle).
- Forgetting one of them shipped bugs: Inertia partial reloads replaced
  `rows` but kept stale `stressedRows`/`phrasalRows` (the `PAGED_PROPS` bug,
  fixed in the commit preceding this ADR), and a sparse PHP list once
  JSON-encoded as an object and whited out the reader (`2f31b3f`).
- Side flipping happened twice per surface (server-side
  `normalizeRowsForReadingSide` in the reader, then four flip memos per
  page on top — nine total), and the simulator's endpoint didn't flip at
  all while the reader's did.
- The word-explanation flow ran on a positional contract: the client sent
  `meaning_match_id + side + sentence_index`, and the server rebuilt the
  side's sentence list — the same filtering the presenter does — so the
  index would line up. One invariant, three enforcement sites.

An architecture review (2026-10-03) surfaced this seam as its top
recommendation; the grilling session that shaped this ADR settled the
decisions below.

## Decision

**One array of Reading rows replaces the five parallel arrays**, built by a
new `App\Classes\ReadingRowsPresenter` (the five simulator builders and
their side helpers are deleted from `MeaningMatchPresenter`, which keeps
`toDisplayRows` + `meaningMatchesQuery` for Filament/the editor):

- Shape: one row per meaning match — `{key: "mm:{id}", a: {sentences},
  b: {sentences}}` — canonical side order (CONTEXT.md: sides are
  positional, not semantic). One row per entity sentence for
  single-language texts, with `b: null`. Empty meaning matches carry empty
  sentence lists, not `["", ""]`.
- Sentence objects are self-describing: `{id, text, stressed?, phrasal?}`
  for text (annotation keys present only when the data exists — no null
  levels), `{id, image: {url, width, height}, text: caption}` for
  illustrations (ADR 0050's picture sentences ride the ordered list and
  render in document order, interleaved with the text — a deliberate
  visual change from "images always above text").
- **Canonical sides from the server; the client owns the flip.** The
  server ships `defaultSide` (the ADR 0037 side rule) plus side-keyed
  companions — reader: `langs`, `wordMaps`, `highlightable`, `explainable`
  (replacing the `primary*`/`translation*` prop families); simulator:
  `word_maps`, `languages`, `default_learning_side` as before. Which side
  plays each display column is derived in render by
  `resources/js/lib/readingRows.mjs` — the only module holding that
  mapping. No row copying: the nine flip memos and the server-side
  normalization are deleted.
- **Sentence identity is an id everywhere.** Reading rows carry each
  sentence's entity-sentence id, and `/ai/word-explain` accepts only
  `{entity_sentence_id, word_id, surface}`; the positional address, the
  request rules, and the server-side sentence-list rebuild are deleted
  (access checking becomes `canRead` on the clicked sentence's entity —
  the same rule the single-language path already used).
- **Components reshape with the payload.** `ReaderRow` (was 26 props) takes
  a row plus two display-column descriptors `{side, wordMap, highlightable,
  explainable}`; `TextContent` takes canonical rows plus
  `firstSide`/`secondSide`; `WordText` takes one side's sentence list and
  renders text and illustrations in document order (absorbing the
  plain-text fast path), keyed by sentence id for the explain payload.
  `IllustrationFigure` is shared (`Components/IllustrationFigure.jsx`)
  instead of copied per surface.
- **The legacy simulator filename mode is removed.** The picker lists only
  entity matches, so `textFromFilename`, the `filename` request rule, and
  the two unused `public/texts/simulator/*.txt` fixtures are deleted;
  `entity_match_id` is required. A stale localStorage `currentText`
  pointing at a filename simply needs re-picking.
- The `row_key` familiarity contract is unchanged (`mm:`/`es:` prefixes,
  ADR 0028) — it just moves onto the row object, so no parallel key list
  exists. `PAGED_PROPS` shrinks to `['rows', 'wordMaps', 'meta']`: one
  payload, nothing that can go stale relative to another prop.

## Consequences

- Adding a per-sentence annotation now touches the presenter and `WordText`
  (plus the storage/enricher half, unchanged by this ADR) instead of ~14
  files; the stale-partial-reload and sparse-array bug classes are
  structural impossibilities in the new shape.
- The side rule stays server-owned (it needs the user's native language and
  the work's original language); only the flip *mechanics* moved client-side.
- Visual change: illustrations render in document order between text
  sentences rather than always above a row's text (both surfaces).
- Payload size is roughly flat: repeated key names offset the dropped
  `"\n"`-join and removed parallel arrays.
- `/ai/word-explain` is a breaking request-contract change; both senders
  ship in this repo, and `AiWordExplainEndpointTest` pins the new address
  plus the retired one's 422.
- Reader render work per toggle drops from five array re-maps to a side
  letter derivation; `WordText` is still `React.memo`ized and rows keep
  stable `key`s, so the freeze-work invariants (reader.md) are preserved.
- Tests rewritten to the row-object shape: `ReaderPageTest`,
  `EntityEnrichmentTest` (reader payload pins), `SimulatorTextEndpointTest`
  (including filename-mode rejection), `EntityIllustrationAlignmentTest`
  (presenter-level, now `ReadingRowsPresenter`), `AiWordExplainEndpointTest`.

> **Amendment (2026-10-04): one word-map / meta builder for both surfaces.**
> The follow-up architecture-review pass found the payload assembly *around*
> the rows still duplicated: the reader's `wordMapForRows`, `isNotNative`
> and `metaFor` helpers vs the simulator's `wordMapsFor` and an inline meta
> array — with two real divergences hiding in the sameness. Three decisions:
>
> - **`ReadingRowsPresenter` is the reading-surface payload seam.** It gains
>   `wordMapsFor(sideEntities, userId, nativeLanguageId, rows)` — word maps
>   plus the `highlightable`/`explainable` eligibility flags ("not the
>   user's native language", null-entity safe) — and `metaFor(paginator)`.
>   Both controllers' private helpers are deleted.
> - **Eligibility flattens to siblings on the simulator too.** The simulator
>   had nested `highlightable`/`explainable` *inside* the `word_maps` blob,
>   so the client's word-map state carried eligibility keys that every
>   familiarity patch had to preserve. The `/text` response now ships
>   `word_maps {a, b}`, `highlightable {a, b}`, `explainable {a, b}` as
>   siblings — the reader's shape — and `word_maps` is always present (the
>   legacy "null when either entity is gone" path is gone with file mode).
>   The wire stays snake_case; `Bilinguals.jsx` keeps its rename lines.
> - **Both surfaces page-filter their maps.** The reader already filtered
>   its word map to the tokens on the current page's rows; the simulator
>   shipped full entity-wide maps that the client never read off-page. The
>   shared `wordMapsFor` always filters, so the simulator's payload shrinks
>   to what its page can render. Client behavior is unchanged — `WordText`
>   and the familiarity helpers tokenize rows before lookup.
>
> `SimulatorTextEndpointTest` asserts the sibling eligibility keys (the
> simulator's `explainable` was previously untested) and gains a
> page-scoping test mirroring the reader's.
