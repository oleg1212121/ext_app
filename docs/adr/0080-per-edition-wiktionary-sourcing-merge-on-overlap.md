# ADR 0080: Each language's dictionary sources from its own Wiktionary edition dump, and editions merge on overlap

Date: 2026-10-18
Status: Accepted

Implements the ru-edition-import spec on top of ADR
[0079](0079-form-of-relays-demote-to-forms-stage-link-prune.md) (whose
purity rules the ru edition import ran under) and ADR
[0029](0029-import-raw-dump-via-per-language-extraction.md) (the raw-dump
extractor the ru edition rode through unchanged). Records the
per-edition sourcing decision, the merge decision on edition overlap,
the two parser field-compat facts that made the ru edition readable at
all, and the numbers of the real dev-DB run — including the one
planning assumption the data refuted.

## Context

Every "Russian" word in the dictionary was sourced from the **English**
Wiktionary: kaikki's monolithic en-edition dump
(`raw-wiktextract-data.jsonl.gz`) imported with `--langs=en,ru`, so the
ru rows are en.wiktionary's `lang_code=ru` sections. That source is
lemma-poor by construction — after the ADR 0079 relay demotion only
79,450 ru word rows survived of ~440k en-edition ru entries (86% were
form-of relays) — while the **Russian Wiktionary edition**
(ru.wiktionary.org) carries ~470k entries of its own, glossed in
Russian. kaikki extracts the editions separately: the en edition at
[kaikki.org/dictionary/rawdata.html](https://kaikki.org/dictionary/rawdata.html),
the ru edition at
[kaikki.org/ruwiktionary/rawdata.html](https://kaikki.org/ruwiktionary/rawdata.html)
(dump dated 2026-10-01: 2,729,136 lines total — 1,002,924 hard-redirects
the parser already skips, 489,522 `lang_code=ru` entries, 11,869
form-of relays = 2.4%, because ru.wiktionary keeps inflections inside
the lemma entry). kaikki's pre-split "Russian" files
(`kaikki.org-dictionary-Russian.jsonl`) are a third thing entirely —
also en.wiktionary, just pre-split — and stay lemma-poor the same way.

Two field shapes in the ru edition dump the parser did not read
(everything else — `word`/`pos`/`senses[].glosses`/`forms[].form`/
`sounds[].ipa`/`form_of`, the pos alias table, the stress-mark strip —
verified compatible as-is against the real file):

1. **Etymologies are `etymology_texts`, plural** — an array of text
   blocks (453k entries; the singular `etymology_text` has zero hits in
   the dump). The parser read only the singular.
2. **Translation objects carry only `lang_code`** — no `code` (zero
   hits in the dump; sample `{"word": "字母ё", "lang_code": "ja",
   "lang": "Японский"}`). The parser keyed staged candidates on `code`
   and would have staged zero.

And the merge question: the ~79.4k stored ru rows carry English
definitions learned from en.wiktionary. Replacing them (a `--fresh`
re-import) would delete that. The user's explicit call: keep both.

## Decision

Three rules:

1. **Each language's dictionary sources from that language's Wiktionary
   edition.** English words stay sourced from the en-Wiktionary dump
   (untouched by the ru run — en 1,013,927 rows exactly preserved);
   Russian words come from the ru-Wiktionary edition dump, imported
   with `wiktionary:import-raw kaikki/ru-raw-wiktextract-data.jsonl.gz
   --langs=ru --target-langs=en`. `--target-langs` is **required** for
   a single-language import: the default staging targets are "every
   other imported language", so with `--langs=ru` alone the set is
   empty and the parser refuses to start ("At least one target
   language is required"). No extractor change was needed —
   `RawWiktextractExtractor` filters on top-level `lang_code` and is
   edition-agnostic; the extract lands at
   `kaikki/ru-raw-wiktextract-data.ru.jsonl` next to the dump.
2. **The parser reads both editions' field shapes.** Two field-compat
   fixes (en edition regression-guarded): etymology reads the singular
   `etymology_text` when present, else the plural `etymology_texts`
   array joins its non-empty parts with newlines; the staged-translation
   target language reads `code` falling back to `lang_code` — en-edition
   objects carry both fields with equal values, so no en-side behavior
   change and no dedupe risk (staged strings array_unique anyway).
3. **A second edition merges, it does not replace.** No new merge code
   was needed — the import semantics are already merge-shaped (ADR
   0079's toolkit): word rows upsert on
   `(word, language_id, word_class_id)`, definitions/etymologies are
   insert-new-only and text-deduped, forms/transcriptions upsert. So
   pre-existing ru rows **keep their English definitions and gain the
   ru edition's Russian glosses, forms, IPA and etymologies** —
   bilingual definitions on overlap, English first, then Russian. The
   staged `words.translations` JSON on overlap rows is overwritten by
   the ru edition's candidates: benign — the old en-sourced candidates
   were unconverted and unmatchable, and the link pass converts the new
   ones.

## Consequences

The first real run (2026-10-18, dev DB, ~29 min detached; extract read
2,729,136 lines and kept 489,522 — both exactly as classified) validated
all three rules and refuted one planning assumption:

- **The merge is real in the data**: собака survived as the same row
  (id 1963630) and grew 14 → 22 definitions — the English ones verbatim
  ("dog", "hound", "(computing slang) watchdog timer", …) plus 8
  Russian ones from the ru edition ("домашнее животное семейства
  псовых…").
- **Import stats**: 471,292 words imported, 6,963 relays demoted at
  import, 248 relay bases created (proxy headwords for bases the dump
  never lists as lemmas), 6 placeholder word classes auto-created
  (abbrev/interjection/root/onomatopeia/gerund/circumfix — ru-edition
  pos slugs PosAliases has no canonical class for; titles want `/admin`
  curation).
- **Link pass** (ran automatically at the end): 109,689 staged
  candidates converted into links (EN→RU 29,709 / RU→EN 79,980 — the ru
  edition's translation sections point three-to-one at English words);
  raw `word_translations` 111,556 → 186,353.
- **Post-pipeline repairs**: `words:normalize` merged 1,167
  unknown-class twins (mostly lone en-era `unknown` rows whose `l_word`
  the ru import properly classed) and backfilled 154 lemma forms; the
  frequency re-clamp (`words:import-frequency ru-rnc`) ranked 18,324 of
  the new rows (56,887 ru words carry a real rank after it).
- **The band missed, and the assumption was wrong.** The plan expected
  ru 460–470k on the assumption that the en-Wiktionary rows all live
  inside the ru edition's vocabulary. Actual **500,430**: the assumption
  "en-wiki ru words ⊆ ru-wiki" is false for 29,037 pre-existing rows
  the ru import stream never touched (composition sampled and verified
  against the extract) — genuinely absent headwords ("Голубая планета",
  "камчатская пирамида"); 5,742 rows whose word the dump lists only
  under a **different class** (Лета: the en-era `name` row stands
  beside the dump's noun); and **14,899 relay-shaped en-era rows whose
  surface the ru dump demotes** (the dump carries the surface only as
  another word's form — a fresh import would demote them under ADR 0079
  rules, but a merge never deletes). The corrected story: the ru side
  is the ru-Wiktionary vocabulary **plus ~29k en-Wiktionary-only
  leftovers**, and landing in the old band would have required deleting
  legitimate rows. Cleanup candidate for the relay-shaped slice: a
  `wiktionary:demote-form-of` pass against
  `kaikki/ru-raw-wiktextract-data.ru.jsonl` (the command's `--file=`
  takes any dump path) — noted follow-up, not done here.
- **The residual is the edition's own, not the merge's**: 17,890 ru
  rows lack any definition (pre-run 173) — ru-edition entries the dump
  itself carries without glosses (forms/related-terms-only entries,
  including the 248 relay-base proxies; each carries its lemma form),
  not en-Wiktionary proxy rows as first guessed.
- The other languages an edition covers are one flag away: the ru dump
  carries `lang_code=be` entries, so a future `--langs=be
  --target-langs=…` run populates the Belarusian dictionary the same
  way.
