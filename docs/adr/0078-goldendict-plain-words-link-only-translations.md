# ADR 0078: GoldenDict imports store plain words, keep phrases out of translations, and never create target words

Date: 2026-10-17
Status: Accepted

Amends ADR 0059's multi-word handling on the GoldenDict import paths.

## Context

The first real GoldenDict imports (Oxford En-Ru, Mostitsky Ru-En) surfaced
three classes of junk in the `words` table:

1. **Stressed twins.** The parser kept combining stress acutes (U+0301) in
   the display `word` because the Wiktionary convention stores them there.
   A bilingual import then created both "маши́ны" (from a translation
   candidate) and "машины" (from another candidate or the kaikki import) —
   the same word twice, differing only by a mark: 33,677 of 54,489 ru rows
   carried acutes, and the duplicates multiplied on every import.
2. **`phrase` as a junk drawer.** The `phrase` word class (an importer
   invention, not a part of speech a dictionary teaches) was assigned to
   every multi-word headword *and* every multi-word translation candidate.
   Candidate mining is lossy: comma-split article fragments produced name
   lists ("Кулешова, Роза Алексеевна") and clause leftovers ("жировые
   складки на талии") as so-called words — 21,980 ru rows landed in
   `phrase`, most of them translation-created stubs.
3. **Translations created words.** Bilingual mode `firstOrCreate`d a target
   word for every surviving candidate, so the ru dictionary filled up with
   bare rows (no definitions, no forms, no transcriptions) that exist only
   as link targets: 40,398 of 54,489 ru words were bare, and 96.9% of all
   `word_translations` pairs pointed at one.

## Decision

Three rules for the GoldenDict importer (`goldendict:import`), keeping the
unified schema untouched:

1. **Plain display forms for the stress-mark languages (ru/be/uk).** The
   `StressMarks` rule that already governed `l_word` now also strips the
   stored display forms: headwords, alt forms and translation candidates
   store as plain letters, so a stressed dictionary variant collapses into
   the same row as the plain word (the unique triple is formed after
   stripping). Definition prose keeps its marks. The Wiktionary importer
   keeps its stressed display headwords — the kaikki `word` field for ru
   is the unstressed lemma — but strips its inflected **forms** for
   ru/be/uk: in the raw dump the stressed variants ("до́ма" for "дом")
   live in the form lists, and unstripped they would sit beside their
   plain twins in every word-family view.
2. **`phrase` only from the dictionary itself, and only when it looks like
   a phrase.** Multi-word headwords keep the `phrase` class (the dictionary
   vouched for the entry), but a multi-word headword is rejected as junk
   when it carries sentence punctuation (`.,;:!?`) or more than 3 tokens
   (rejected entries are counted in the new `headwords_rejected` stat).
   Translation candidates never determine a word class anymore — they are
   not words the import owns.
3. **Bilingual mode is link-only.** A candidate resolves by `l_word` to an
   existing target-language row (same class preferred, multi-word prefers
   `phrase`, else lowest id) and links it via canonical
   `word_translations`; unresolved candidates stay staged in
   `words.translations` for a later pass and are counted in the new
   `translation_targets_unmatched` stat. The importer never creates target
   rows: the target language's word list belongs to its own imports
   (kaikki, frequency lists, cmudict). `target_words_created` is gone.

   A `forms` profile selector kind joins `transcriptions`/`translations`/
   `definitions`/`pos`/`strip`: comma/semicolon/slash-split form lists that
   merge with the entry's alts, so per-dictionary profiles can fill the
   forms table where the converter's `alts` fall short.

## Consequences

- The popup's translation list still works in full: it reads
  `word_translations`, and a freshly imported bilingual dictionary links
  only when the target word exists — until the target language's own
  import runs, candidates are staged but unlinked. Re-running
  `goldendict:import` after the target import links them; the staged JSON
  is never clobbered.
- Import order matters: base dictionaries (kaikki, frequency, cmudict)
  before bilingual GoldenDict runs, or the first run links almost nothing.
- `FetchWordTranslations` (the popup's on-demand provider fetch) still
  creates target words and still files multi-word candidates under
  `phrase` — deliberately left out of scope here; it is an interactive
  path with provider-supplied candidates, not a bulk importer. Flagged for
  a follow-up decision.
- The existing dev/prod data was wiped for en+ru and re-imported through
  the reworked pipeline (kaikki en+ru with `--fresh`, cmudict, frequency,
  then both GoldenDict dictionaries) — see
  `wiki/playbooks/import-dictionary-data.md`.
