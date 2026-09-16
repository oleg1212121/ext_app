# 0029 - Import the monolithic raw wiktextract dump via per-language extraction

We import en+ru dictionary data from kaikki.org's `raw-wiktextract-data.jsonl.gz`
(~2.7GB compressed, ~all 2400+ Wiktionary languages interleaved on one line
each) through a two-stage pipeline: `wiktionary:import-raw` first splits the
raw dump into per-language extract files (`RawWiktextractExtractor`, streaming
one line at a time with a substring pre-filter), then feeds each extract
through the existing single-language `wiktionary:import` path
(`WiktionaryParser`), and finally runs `wiktionary:link-translations`.

The alternative — teaching `WiktionaryParser` multiple languages in a single
pass — was rejected because the parser merges adjacent lines sharing a
`word|pos` key **without language**: the raw dump interleaves languages, so
e.g. the English and French entries for "murder" would merge into one record.
Making the merge/upsert logic language-aware would rewrite proven, tested code
for both languages at once. The extract step costs one extra decompression pass
(~3.5GB on disk, kept and reused via `--skip-extract`) but leaves the
single-language invariants intact, produces inspectable intermediate files, and
makes the run restartable per phase.

## Consequences

- Re-importing a newer dump never deletes stale definitions (upsert +
  text-dedupe only); `wiktionary:import-raw --fresh` wipes the selected
  languages' dictionary data first — which cascades `user_word` familiarity
  rows and resets `entity_words` links, so it requires `--force` on
  non-interactive runs.
- `--langs` is generic (any languages-registry code): adding a language later
  is a flag, not a schema change.
- The monolithic raw dump is the only file that must go through the extractor;
  the per-language kaikki.org downloads still import directly via
  `wiktionary:import` (which now also reads `.jsonl.gz` transparently).
