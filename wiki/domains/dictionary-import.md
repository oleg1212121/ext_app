---
type: Pipeline
title: Dictionary Import
description: Parsing Kaikki/Wiktionary dumps and converted GoldenDict dictionaries into the unified language-keyed dictionary tables and linking translations across every language pair; multi-word headwords are shape-curated so junk verb rows never reach the multi-word-verb lexicon (ADR 0059).
tags: [dictionary, import, wiktionary, kaikki, goldendict, multi-word-verbs]
status: stable
stale_after: 2026-12-16
generated: { by: agent:zcode, at: 2026-10-14T16:54:12+03:00 }
sources:
  - id: wiktionary
    resource: laravel/app/Classes/WiktionaryParser.php
    title: Kaikki/Wiktionary JSONL parser
  - id: extractor
    resource: laravel/app/Classes/RawWiktextractExtractor.php
    title: Raw dump per-language extractor
  - id: import-cmd
    resource: laravel/app/Console/Commands/ImportWiktionaryCommand.php
    title: wiktionary:import
  - id: import-raw-cmd
    resource: laravel/app/Console/Commands/ImportRawWiktextractCommand.php
    title: wiktionary:import-raw
  - id: link-cmd
    resource: laravel/app/Console/Commands/LinkTranslationsCommand.php
    title: wiktionary:link-translations
  - id: goldendict-cmd
    resource: laravel/app/Console/Commands/ImportGoldenDictCommand.php
    title: goldendict:import
  - id: goldendict
    resource: laravel/app/Classes/GoldenDictParser.php
    title: GoldenDict JSONL parser
  - id: goldendict-profiles
    resource: laravel/config/goldendict.php
    title: Extraction profiles (config/goldendict.php)
  - id: lookup-key
    resource: laravel/app/Support/WordLookupKey.php
    title: shared l_word normalization
  - id: reclass-cmd
    resource: laravel/app/Console/Commands/ReclassMultiwordWordsCommand.php
    title: words:reclass-multiword
  - id: shape
    resource: laravel/app/Classes/MultiwordVerbShape.php
    title: MultiwordVerbShape
---

# What it is

The import pipeline that fills the [unified dictionary](
/database/dictionary.md) tables from two source shapes: machine-readable
Wiktionary data (kaikki.org JSONL dumps, parsed directly) and
GoldenDict-format dictionaries (MDict `.mdx`, ABBYY Lingvo `.dsl`,
StarDict — converted to an intermediate JSONL by the python service's
`ai/goldendict_convert.py` first). Since ADR
[0018](../../docs/adr/0018-works-and-unified-language-keyed-tables.md) there
is one language-keyed `words` table (+ satellites) per language instead of
mirrored per-language tables, and a single `word_translations` pivot —
**one symmetric row per word pair** since ADR
[0020](../../docs/adr/0020-symmetric-word-translations.md).

# Components

* `App\Classes\WiktionaryParser` — parses kaikki.org JSONL dumps into the
  unified tables (the former separate `KaikkiParser` was folded into it).
  Reads plain `.jsonl` and, transparently, gzip `.jsonl.gz` via the
  `compress.zlib://` wrapper. Staging targets can be a single target code or
  a list (`new WiktionaryParser($lang, ['ru', 'de'])`) — see
  [ADR 0029](../../docs/adr/0029-import-raw-dump-via-per-language-extraction.md).
* `App\Classes\RawWiktextractExtractor` — streams a **Raw dump** (the
  monolithic kaikki `raw-wiktextract-data` file, every Wiktionary language
  interleaved) once and writes one **Language extract** per wanted code: raw
  lines kept verbatim, next to the dump (`<base>.<code>.jsonl`). A substring
  pre-filter skips `json_decode` for lines that cannot match; the decoded
  top-level `lang_code` decides (nested `lang_code` occurrences inside
  translation/template data are false positives). Memory stays flat — one
  line at a time — and every 100k lines a heartbeat (lines, kept counts, RSS,
  elapsed) goes to the console and the log channel for detached runs.
* `php artisan wiktionary:import {file} --lang= --target-lang=` — parses a
  dump into the unified `words` table (+ definitions, forms, transcriptions
  (+types), etymologies, pronunciations, examples, word classes, tags) keyed
  by `language_id`. `--lang` accepts any registry code; `--target-lang`
  accepts **comma-separated** registry codes. `is_enabled` is irrelevant —
  it gates user-facing surfaces, not CLI data steps; unknown codes fail with
  the available registry codes listed. Translations are **stored, not
  linked**, during import.
* `php artisan wiktionary:import-raw {file} --langs=en,ru` — the raw-dump
  orchestrator ([ADR 0029](../../docs/adr/0029-import-raw-dump-via-per-language-extraction.md)):
  extract → import each language (symmetric staging: every imported language
  stages every other; override with `--target-langs`) → link translations
  (`--no-link` to skip). Also: `--fresh` (wipe the selected languages'
  dictionary data first — cascades user familiarity rows and resets
  `entity_words` links; requires `--force` when STDIN is not a terminal),
  `--extract-only`, `--skip-extract`, `--batch-size=`, `--max-lines=` (smoke
  runs). Extract files live next to the dump and are overwritten per run;
  re-imports upsert and text-dedupe, never delete — hence `--fresh` for a
  clean rebuild from a newer dump.
* `php artisan wiktionary:link-translations` — links words across languages
  through the stored translations into `word_translations` rows, covering
  **every language pair** among languages that have imported words (one
  canonical row per pair, `word_a_id < word_b_id`; stress-mark stripping
  applied when pairing into the Cyrillic stress-mark languages ru/be/uk —
  the shared `App\Support\StressMarks`). Re-runs are idempotent next to
  manually created links. `wiktionary:import-raw` runs it automatically at
  the end (unless `--no-link`).
* `php artisan goldendict:import {file} --lang= [--target-lang=]` — the
  GoldenDict entry point: imports a **converted** JSONL (one
  `{"word", "alts", "html"}` record per line, produced by the python
  service's `ai/goldendict_convert.py` — stage 1 of the pipeline, run on
  the **host** through the bind-mounted venv (pyglossary 5.4.2, pinned in
  `docker-compose/python/requirements.txt` — hence the routine python
  image rebuild on the prod machine after this feature deploys; see
  [Importing Dictionary Data](/playbooks/import-dictionary-data.md) for
  the full runbook including the deploy note).
  Stage 2 is `App\Classes\GoldenDictParser`, a **generic heuristic
  extractor**: article HTML is parsed with native `DOMDocument`/`DOMXPath`
  into plain-text block segments (`definitions`, truncated to the 500-char
  cap), IPA runs in `[brackets]`/`/slashes/` (`transcriptions` under the
  auto-created per-language `ipa` type), POS abbreviations from
  pos-classed/`<i>`/`<abbr>` elements map to word-class slugs, and
  alternate headwords (`alts`, MDX `@@@LINK=` redirects) land as `forms`.
  Multi-word headwords go to the `phrase` class regardless of the POS hint
  (ADR 0059). Raw HTML never enters the database — the JSONL stays on disk
  and a better extractor can re-import it anytime. Flags: `--dry-run`
  (parse and report, write nothing — not even auto-created lookups),
  `--max-entries` (smoke runs), `--batch-size`, `--profile` (below). New
  words get the `FREQUENCY_UNRANKED` sentinel; re-imports refresh `l_word`
  only, so curated frequency ranks and staged translations survive.
* `goldendict:import --profile=<slug>` — **extraction profiles**: a
  declarative XPath selector map per dictionary in
  `config/goldendict.php` (`transcriptions`/`translations`/`definitions`
  keys; a class matches as a whole token via the
  `contains(concat(" ", normalize-space(@class), " "), "…")` idiom)
  layered over the generic heuristics, so structured dictionaries extract
  cleanly without code changes. Per selector kind and per article, a
  selector that matched nothing falls back to the heuristic — an entry is
  never dropped; profile-selected transcriptions skip the IPA-marker gate
  (the profile author vouches for the nodes), profile-selected
  translation cells still pass the noise filters. Articles where NO
  selector matched are counted ("Profile no-match articles" stat row) and
  sampled (capped at 5) into one log notice so profile authors see what
  they are missing; an unknown slug fails with the known list, unknown
  keys or broken XPath fail up front. Tune profiles incrementally against
  real dictionaries (how-to: [Importing Dictionary Data](
  /playbooks/import-dictionary-data.md)) — no schema migrations, the
  JSONL just re-imports.
* `goldendict:import --target-lang=xx` — **bilingual mode**: the bulk
  mirror of the `FetchWordTranslations` semantics. Translation candidates
  come out of each article (comma/semicolon-delimited segments after
  stripping transcription runs, grammar tokens — POS abbreviations,
  gender/number tags — and the leading headword; each piece must survive
  the `usableCandidates` rule: no `[.,;:]`, ≤256 chars), the missing
  target-language words are created (source class slug mapped into the
  target language with the same unseen-lookup auto-creation; multi-word
  candidates → `phrase`, ADR 0059; `l_word` through `WordLookupKey` so
  Cyrillic stress marks live in the display form only), the raw strings
  are staged into `words.translations` JSON (flat deduped list, the
  Wiktionary shape — merged on re-import, never clobbered), and canonical
  `word_translations` links (`word_a_id < word_b_id`, ADR 0020) are
  written directly via batched upserts — no separate
  `wiktionary:link-translations` pass needed for this path. `--no-link`
  skips all three phases (pure monolingual behavior); the target language
  must be a registry code other than `--lang`.

# GoldenDict vs Wiktionary imports

Both importers write the same unified tables with the same conventions
(batched `Word::upsert` on `(word, language_id, word_class_id)`, lookup
auto-creation, text-dedupe definitions, heartbeats) and share the `l_word`
normalization through `App\Support\WordLookupKey` (lowercase + combining-
mark strip via `App\Support\StressMarks` — the display `word` keeps the
marks). The Wiktionary side reads kaikki JSONL with structured fields
(`pos`, `senses`, `sounds`, `translations`); the GoldenDict side extracts
everything heuristically from article HTML and tunes per dictionary with
`--profile` extraction profiles (above). Translation linking
differs: the Wiktionary path **stores** translations during import and a
separate `wiktionary:link-translations` pass links them against
already-existing words; the GoldenDict bilingual path creates its own
target words and writes the links directly (`--no-link` to opt out).

# Performance notes

* `definitions`/`etymologies` carry non-unique indexes on `word_id`
  (`idx_definitions_word_id`, `idx_etymologies_word_id`): the parser's
  `insertNewOnly()` runs `whereIn('word_id', …)` per batch and would
  full-scan once these tables hold millions of rows. Note: the text-dedupe
  itself silently never fired before 2026-10-14 — the existing-rows map was
  filled inside `each(fn …)` and arrow functions capture by value, so
  re-imports duplicated definitions; both parsers now fill it in a plain
  `foreach` and re-imports are truly idempotent.
* The parser batch-flushes (default 500, `--batch-size`) inside a
  transaction and force-flushes when RSS exceeds 100MB; `import-raw` raises
  the CLI `memory_limit` to 1G because single decoded lines (e.g. the entry
  for "the", hundreds of KB of JSON) can spike past the 128M CLI default.
* On a machine with ordinary NVMe the full raw dump extracts in tens of
  minutes; the en import is the long pole (tens of millions of lines) — run
  detached and watch the heartbeats in `storage/logs/laravel.log`.

# Lookup auto-creation

The parser never dead-ends on missing per-language lookups and never drops
data because of them: a dump `pos` with no matching **word class** (or a
sound type with no matching **transcription type**) is auto-created with the
slug as a placeholder `title`, and the word imports under it (the former
`words_skipped_pos` skip is gone). A fresh language therefore needs no
seeders — `INSERT` the language row, run the import, then curate the
placeholder titles in `/admin`. Import stats report the created lookups
(`lookups_created`).

# Multi-word headword hygiene (ADR 0059)

The import stores multi-word headwords under whatever pos the dump claims,
so junk lands under the verb class ("do it", "could have", "be there")
beside idioms ("kick the bucket") — and the translation-fetch job used to
store multi-word provider candidates under the provider pos, creating more
of the same. Since ADR 0059 the multi-word-verb lexicon is gated by
`App\Classes\MultiwordVerbShape` (2–4 tokens, every token after the verb a
closed-class particle/preposition), so polluted rows can no longer produce
false matches regardless; `FetchWordTranslations` now stores multi-word
candidates under the `phrase` class (auto-created after the
lookup-auto-creation convention), and
`php artisan words:reclass-multiword {--dry-run}` reclasses the existing
**English** junk verb rows to `phrase` (rows whose headword already exists
in the phrase class are skipped — the words table is unique per
(word, language, class); resolve those by hand). Other languages are
untouched — Russian multi-word verbs ("выдавать себя за") are legitimate
verbs the English shape has no opinion about.

# Where it surfaces

* Filament admin `/admin` (group "Words"): the per-language `WordResource`
  with relation managers for definitions, forms, translations, etymologies,
  examples, transcriptions, pronunciations, plus **Word class** and
  **Transcription type** resources for CRUD over the lookup tables. The
  **Translations** tab is the manual linking surface (attach / create word
  & link / delete, different languages only).

# Operating it

Step-by-step: [Importing Dictionary Data](/playbooks/import-dictionary-data.md).
