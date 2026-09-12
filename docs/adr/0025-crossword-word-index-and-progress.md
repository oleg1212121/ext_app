# ADR 0025: Crossword word index and progress on the new schema

Date: 2026-09-12
Status: Accepted

## Context

The 2025 crossword feature (deleted with the legacy vocabulary domain in
ADR [0018](0018-works-and-unified-language-keyed-tables.md)) depended on
per-book word tables (`book_word`) and frequency boolean columns
(`less_100` … `less_1000000`) on the legacy `words` table. The new
language-keyed schema has no word↔entity relation at all, and
`words.frequency` is unpopulated by the kaikki import. Restoring the
crossword requires deciding how the entity's word list, the "level of
words" selection, and player progress are modelled.

## Decisions

### 1. Token-first entity word list, dictionary link later

`entity_words` (`entity_id`, `l_word`, `token`, `count`, nullable
`word_id`) is built directly from `entity_sentences` text with a PHP
Unicode tokenizer (`WordTokenizer`, replaces the dead `Parser.php`). The
nullable `word_id` is filled afterwards by `crossword:link`, which matches
`(language_id, l_word)` against imported dictionary words (noun-first
class priority). Alternatives rejected:

- *Rows only for dictionary-matched tokens*: the word list would be
  empty/partial until a kaikki import runs; the count inventory is the
  feature's core data ("how many times is each word used in this text")
  and must not depend on dictionary coverage.
- *Python tokenization*: sentence splitting legitimately needs pysbd/razdel;
  word tokens need only a Unicode regex, so a service round-trip is unjustified.

The index carries `entities.words_indexed_at` and is rebuilt (lazily on
generate, or via `crossword:index`) when any sentence's `updated_at`
outruns it. It is intentionally NOT a pivot through `words`: tokens exist
before their dictionary entry.

### 2. Level = global frequency-rank band

`words.frequency` stores a rank (lower = more common; 0 = unranked,
populated by `words:import-frequency` from committed `rank,word` CSV
lists). A crossword "level" is a band cutoff
(`CrosswordLevel`: 100 / 500 / 1000 / 3000 / 5000 / 10000 / 20000 /
1000000), mirroring the legacy `less_*` columns without repeating the
eight-boolean-column mistake. CEFR levels were rejected: kaikki carries no
CEFR data and a third-party mapping adds a data dependency.

### 3. Global per-user progress

`user_word` (`user_id`, `word_id`, `status` ∈ learning/solved/known) is
one row per user+word — progress applies across all works. The legacy
`book_word.is_solved` (per book) was rejected: it made puzzles repeat the
same solved words in every other book. Generation excludes solved+known
words and deterministically orders by frequency, so completing a puzzle
advances the learner down the band. Generate marks selected words
`learning`; a completed grid marks them `solved` (`crossword/complete`);
"I know this word" sets `known` (`crossword/word/know`).

## Consequences

- The crossword is usable as soon as an entity has sentences (definitions
  panel fills in as dictionary import + linking catch up).
- Frequency data is an operational prerequisite for level bands; without
  it every band is empty (generate returns 422, surfaced in the UI).
- `entity_words` rows are rebuilt wholesale, so the table is a derived
  cache — never hand-edit it.
