---
type: Playbook
title: Importing Dictionary Data
description: How to import Kaikki/Wiktionary dumps and GoldenDict-format dictionaries into the unified language-keyed dictionary tables and link translations.
tags: [dictionary, import, wiktionary, kaikki, goldendict]
status: stable
stale_after: 2027-01-17
generated: { by: agent:zcode, at: 2026-10-17T19:53:08+03:00 }
verified: { by: agent:zcode, at: 2026-10-17T19:53:08+03:00 }
sources:
  - id: import-cmd
    resource: laravel/app/Console/Commands/ImportWiktionaryCommand.php
    title: wiktionary:import command
  - id: import-raw-cmd
    resource: laravel/app/Console/Commands/ImportRawWiktextractCommand.php
    title: wiktionary:import-raw command
  - id: link-cmd
    resource: laravel/app/Console/Commands/LinkTranslationsCommand.php
    title: wiktionary:link-translations command
  - id: kaikki
    resource: laravel/app/Classes/WiktionaryParser.php
    title: Kaikki/Wiktionary JSONL parser
  - id: extractor
    resource: laravel/app/Classes/RawWiktextractExtractor.php
    title: Raw dump per-language extractor
  - id: goldendict-cmd
    resource: laravel/app/Console/Commands/ImportGoldenDictCommand.php
    title: goldendict:import command
  - id: goldendict
    resource: laravel/app/Classes/GoldenDictParser.php
    title: GoldenDict JSONL parser
  - id: goldendict-profiles
    resource: laravel/config/goldendict.php
    title: Extraction profiles (config/goldendict.php)
  - id: goldendict-convert
    resource: docker-compose/python/ai/goldendict_convert.py
    title: goldendict_convert.py (MDX/DSL/StarDict → JSONL)
---

# Overview

Dictionary content comes from [kaikki.org](https://kaikki.org) machine-readable
Wiktionary dumps (JSONL, one JSON object per line). Import is a two-phase
process: parse dumps into the unified language-keyed tables, then link
translations between them. Target tables are described in
[Unified Dictionary](/database/dictionary.md) — one `words` table keyed by
`language_id` (ADR
[0018](../../docs/adr/0018-works-and-unified-language-keyed-tables.md))
replaced the old mirrored per-language tables.

Two source shapes exist:

* **Raw dump** — `raw-wiktextract-data.jsonl.gz`, the whole English
  Wiktionary with every language interleaved (~2.7GB compressed, tens of
  millions of lines). Use `wiktionary:import-raw` (below).
* **Per-language download** — kaikki.org's pre-split
  `kaikki.org-dictionary-<Language>.jsonl` files. Use
  `wiktionary:import` directly (bottom of this page).

# Steps — raw dump (wiktionary:import-raw)

1. Download the dump onto the host and put it under `laravel/` (e.g.
   `laravel/kaikki/raw-wiktextract-data.jsonl.gz`) so the container sees it.
2. Ensure the languages exist in the languages registry (seeded en/ru, or
   Filament `/admin` → Languages). `--langs` accepts any registry code.
3. **Smoke-run first** — a capped extraction over the real gz validates
   everything end to end in minutes:

   ```bash
   docker exec ext_app_laravel sh -c 'cd /var/www && DB_CONNECTION=testing php artisan migrate:fresh --seed'
   docker exec ext_app_laravel sh -c 'cd /var/www && DB_CONNECTION=testing php artisan wiktionary:import-raw kaikki/raw-wiktextract-data.jsonl.gz --max-lines=50000'
   ```

4. Run the real import detached, redirecting output (multi-hour run):

   ```bash
   docker exec -d ext_app_laravel sh -c 'cd /var/www && php artisan wiktionary:import-raw kaikki/raw-wiktextract-data.jsonl.gz >> storage/logs/import-raw.log 2>&1'
   ```

   Phases: extract (writes `kaikki/raw-wiktextract-data.en.jsonl` and
   `.ru.jsonl` next to the dump, overwriting) → import each `--langs`
   language (symmetric translation staging by default) → link translations.
   Heartbeats every 100k lines (lines, kept counts, RSS, elapsed) land in
   `storage/logs/laravel.log`, so progress is observable even detached.
5. Useful flags: `--skip-extract` reuses existing extract files (e.g. rerun
   a failed import phase without a second decompression pass);
   `--extract-only` stops after the extract files; `--no-link` skips the
   linking pass; `--target-langs=ru` restricts staging to one direction;
   `--batch-size=500` tunes DB flush size.
6. **Clean rebuild** from a newer dump: add `--fresh --force` (non-interactive
   runs require `--force`). `--fresh` wipes the selected languages' words —
   FK cascades take definitions, forms, etymologies, transcriptions,
   translation links and **user familiarity rows** with them, and
   `entity_words.word_id` links reset (re-run `crossword:link` afterwards).
   Without `--fresh`, re-imports upsert and text-dedupe but never delete:
   glosses that changed between dump versions linger as extra definitions.
7. Verify in the Filament admin (`/admin`, group "Words") and hand-link the
   leftovers via a word's **Translations** tab (see the per-language flow
   below for the exact surfaces).

# Steps — per-language download (wiktionary:import)

1. Download the Kaikki JSONL dump for the language (e.g. `en-wiktionary` or
   `ru-wiktionary` extract) onto the host, anywhere under `laravel/`.
2. Ensure the language exists in the languages registry. `--lang` and
   `--target-lang` (comma-separated) accept any registry code;
   `is_enabled` is not required for import.
3. Run the import:

   ```bash
   docker exec ext_app_laravel php artisan wiktionary:import storage/app/<file>.jsonl --lang=en --target-lang=ru
   ```

   Missing per-language lookups are **auto-created** (unseen dump `pos` →
   word class, unseen sound type → transcription type, slug as placeholder
   title) — nothing is skipped, and a brand-new language needs no seeders;
   curate the placeholder titles in `/admin` afterwards.
4. Link words across languages through the staged translations:

   ```bash
   docker exec ext_app_laravel php artisan wiktionary:link-translations
   ```

   The command links **every language pair** among languages that have
   imported words, writing one canonical `word_translations` row per pair
   (`word_a_id < word_b_id`, ADR 0020). Matching strips the Cyrillic
   stress marks (e.g. `приве́т` → `привет`) when pairing into the
   stress-mark languages ru/be/uk (the shared `App\Support\StressMarks`).
   Re-runs are
   idempotent and never duplicate links created by hand in the admin.
5. Verify in the Filament admin (`/admin`, group "Words"): the `Word`
   resource with relation managers for definitions, forms, translations,
   etymologies, examples, transcriptions, pronunciations; the **Word
   class** and **Transcription type** resources show (and let you edit)
   the auto-created lookups.
6. Link what the command could not match by hand: on a word's edit page,
   the **Translations** tab offers **Attach translation** (pick an existing
   word in another language) and **Create word & link** (create the missing
   target word and link it in one step). Links work from either word.

# Steps — GoldenDict dictionary (goldendict:import)

For GoldenDict-format dictionaries (freemdict etc.). The converter accepts
MDict `.mdx`, ABBYY Lingvo `.dsl` (UTF-8 or UTF-16LE/BE) and StarDict sets
(an `.ifo` path or a directory containing exactly one set) — see the usage
note in `docker-compose/python/ai/goldendict_convert.py`. Two stages: a
host-side pyglossary conversion, then the container-side import. Put the
dictionary file under the repo (e.g. `laravel/goldendict/`) so the
host-side converter and the container-side import see the same files — the
JSONL lands next to the source. Legal note: freemdict dictionaries are
frequently commercial rips (Lingvo etc.) — fine for personal learning use;
do not redistribute the extracted data.

1. **Convert** on the host through the bind-mounted python venv (stage 1,
   writes the JSONL next to the source; encrypted/regcode MDX is rejected
   with a clear error):

   ```bash
   docker-compose/python/ai/venv/bin/python \
       docker-compose/python/ai/goldendict_convert.py laravel/goldendict/DICT.mdx
   # -> laravel/goldendict/DICT.mdx.jsonl with {"word", "alts", "html"} records
   ```

2. **Preview dry** — parse a capped slice and report what would be
   written (stat rows only; nothing is written, not even auto-created
   lookups). The bilingual link tally is diffed against the database, so
   re-previewing an already-linked dictionary reports `translations_linked`
   zeros and repeats the `unmatched candidates` count; would-be links stay
   an in-run estimate (deduplicated within the run only):

   ```bash
   docker exec ext_app_laravel sh -c 'cd /var/www && php artisan goldendict:import goldendict/DICT.mdx.jsonl --lang=xx --max-entries=500 --dry-run'
   ```

3. **Smoke-run against the testing DB** — the same capped run with real
   writes, so the full write path (lookup auto-creation, upserts, links)
   is exercised on throwaway `ext_app_test` data first:

   ```bash
   docker exec ext_app_laravel sh -c 'cd /var/www && DB_CONNECTION=testing php artisan migrate:fresh --seed'
   docker exec ext_app_laravel sh -c 'cd /var/www && DB_CONNECTION=testing php artisan goldendict:import goldendict/DICT.mdx.jsonl --lang=xx --max-entries=500'
   ```

4. **Run the real import detached**, redirecting output — dictionary-sized
   JSONLs are long-running, and heartbeats every 100k entries (counts,
   RSS, elapsed) land in `storage/logs/laravel.log` so progress stays
   observable:

   ```bash
   docker exec -d ext_app_laravel sh -c 'cd /var/www && php artisan goldendict:import goldendict/DICT.mdx.jsonl --lang=xx >> storage/logs/goldendict-import.log 2>&1'
   ```

   `--lang` accepts any registry code, missing lookups auto-create like
   the Wiktionary import. Extraction is a generic heuristic (plain-text
   block segments → definitions, `[bracketed]`/`/slashed/` IPA →
   transcriptions under the auto-created `ipa` type, POS abbreviations →
   word-class slugs, `alts` → forms, multi-word headwords → `phrase`
   unless they are junk: a multi-word headword with sentence punctuation
   or more than 3 tokens is rejected, ADR 0078). For ru/be/uk sources the
   stored words and forms are **plain letters** — the combining stress
   acutes are stripped at parse time, so stressed variants collapse into
   the plain rows. For a **bilingual** dictionary add `--target-lang=yy`:
   translation candidates (the comma/semicolon-delimited article segments
   that survive the noise filters) **link to existing target-language
   words** — resolved by `l_word` (same class preferred, multi-word
   prefers `phrase`), never creating target rows — plus canonical
   `word_translations` links and `words.translations` JSON staging for
   every candidate (staged strings wait in the JSON for a re-run once the
   target word exists; `--no-link` skips these phases). For dictionaries
   whose articles have structure the heuristics mangle, add
   `--profile=<slug>` (below). Re-runs upsert and text-dedupe — raw
   article HTML never enters the database, so a better extractor just
   re-imports the JSONL.

   Optional **host-side JSONL pre-filter** (a plain jsonl text pass, no
   container round-trip needed): dictionaries ship non-word records you
   can drop before importing rather than prune from the DB afterwards —
   Mostitsky eXtro's `[1]`-style citation stubs, `**`/`***` info cards
   and `_`-prefixed navigation pages (73 records) were deleted, and DSL
   syntax quotes stripped from headwords (`"ангелы"` → `ангелы`, 5
   records, also fixing their `l_word`). Keep it conservative and
   eyeball the drop list first: bare-number headwords there are real
   entries (`1947` = Roswell year) and stay. The `.dsl`/`.mdx` source of
   truth is untouched — reconvert regenerates an unfiltered JSONL.

5. Verify in the Filament admin (`/admin`, group "Words") like the
   Wiktionary flow above.

### Clean rebuild after import-rule changes (ADR 0078)

When the import rules change in a way old data violates (stressed display
twins, translation-created bare stubs, junk `phrase` rows), the fix is a
wipe + re-import, not an in-place patch. Import order matters — base
dictionaries before the bilingual runs, or the first bilingual run links
almost nothing:

1. `wiktionary:import-raw kaikki/raw-wiktextract-data.jsonl.gz
   --langs=en,ru --fresh --force` — wipes all en+ru dictionary data (FK
   cascades take the satellites, translation links and user familiarity
   rows with them — accepted for a dev dataset) and imports kaikki words,
   definitions, forms, transcriptions, then links translations.
2. Restore the auxiliaries: `cmudict:import`,
   `words:import-frequency` / the frequency-lists import per their
   `--help` (ranks survive nothing — the wipe resets them).
3. The bilingual GoldenDict runs (dry-run, then real, detached):
   `goldendict:import … --lang=ru --target-lang=en
   --profile=mostitsky-ru-en` and `goldendict:import … --lang=en
   --target-lang=ru --profile=oxford-en-ru`. Their candidates now link
   against the kaikki rows; the unmatched remainder stays staged in
   `words.translations` and links on a later re-run if the base import
   grows the target language.
4. Re-run the entity linking pass (`crossword:link` per the `--fresh`
   note above) — the wipe reset `entity_words.word_id`.
5. Verify: no U+0301 in stored ru `words.word`/`forms.form`, no
   translation-created bare ru stubs, `phrase` rows only under dictionary
   headwords.

### Extraction profiles (--profile=<slug>)

A profile is a declarative XPath selector map in
`laravel/config/goldendict.php` that tunes the generic heuristics for ONE
dictionary — no code changes per dictionary. To author one:

1. Convert a slice of the dictionary and smoke-run **without** the profile
   (`--dry-run --max-entries=500`) to get the baseline stat row.
2. Copy a profile in `config/goldendict.php` — `oxford-en-ru` is the
   working real-world example (Oxford Russian Dictionary En-Ru: IPA from
   `phoneticText` divs/spans via their direct text nodes with a
   `normalize-space(.) != ","` predicate dropping the separator node,
   Russian equivalents from `targetLangTranslation` spans excluding
   `exampleGroup` blocks, numbered sense units from `sense-1`/`sense-2`
   divs, word classes from `partOfSpeech` divs, `exampleGroup` blocks
   stripped), `mostitsky-ru-en` the DSL one (see the tip below) —
   or the `example-classes` template, rename
   the slug, and retarget the six selector keys at the dictionary's
   article structure:
   * `transcriptions` — each matched node's text becomes a transcription
     (enclosing `[brackets]`/`/slashes/` trimmed; **not** gated on the
     IPA-marker heuristic — the profile author vouches for these nodes,
     so non-IPA transcription systems work).
   * `translations` — the matched nodes' texts replace the heuristic
     block segments as the source of translation candidates (the noise
     filters still apply: comma/semicolon split, grammar tags and junk
     dropped). Bilingual mode only.
   * `definitions` — the matched nodes' texts are the definition
     segments instead of the heuristic nearest-block grouping.
   * `forms` — each matched node's text splits on commas/semicolons/
     slashes into individual forms ("am, is, are / was") that **merge**
     with the entry's `alts` in the forms table (never replace them).
   * `pos` — the matched nodes' texts are word-class candidates: the
     first text the POS dictionary maps ("transitive verb" → `verb`)
     overrides the heuristic pos hints; an unmappable text is a miss.
   * `strip` — the matched nodes are removed from the article DOM
     before any extraction (subtraction, not extraction: they vanish
     from every other kind's input) and never count as a match/miss.
   Each value is an XPath 1.0 string or a list of them (queried in
   order). The `contains(concat(" ", normalize-space(@class), " "), "…")`
   idiom matches a class as a whole token.
3. Smoke-run **with** `--profile=<slug> --dry-run` and compare the stat
   rows — the demo is the same JSONL visibly extracting differently.
4. Iterate: a selector that matches nothing in an article falls back to
   the heuristic for that kind and article — and so does a selector whose
   matched nodes hold only whitespace text (transcriptions also trim
   bracket/slash delimiters; a matched-but-empty
   kind is a miss, never a silently emptied extraction) — an entry is
   never dropped. The one exception is **translations when the profile
   configures a translations selector**: there the author vouches for
   where the equivalents live, so a miss yields no candidates for that
   article instead of mining the raw block text (which manufactured junk
   target words out of example scaffolding). Every article where NO
   selector effectively matched is
   counted in the
   "Profile no-match articles" stat row and sampled (capped) into one
   `Goldendict profile '…': no selector matched in N articles` notice in
   `storage/logs/laravel.log`, so you can see what you are missing.
5. Known-slug safety: an unknown slug fails the command with the known
   list; unknown keys or syntactically broken XPath fail up front before
   anything is written.

When the dictionary's markup gives no class to hook onto, a
**content-language test** can still separate the kinds: pyglossary
renders the Mostitsky DSL `[trn]` equivalents as plain
`padding-left:1em` paragraphs — indistinguishable by shape from the
`[com]` commentary paragraphs — but they are the only such paragraphs
whose text carries no Cyrillic, so `mostitsky-ru-en` selects them with
`translate()` stripping the Cyrillic alphabet and comparing:
`//p[@style="padding-left:1em;margin:0"][normalize-space(translate(normalize-space(.), "абвгде…", "")) = normalize-space(.)]`.
Configuring that selector also vouches translations (cross-reference
articles yield no candidates instead of mined Russian commentary —
translation candidates went 1154 → 209 on the first 500 entries). The
same dictionary shows when NOT to strip: its bold `<b>` carries real
content in other articles, so the headword echo stays in the
definitions, and `span.sec` (► source links, «см. тж» blocks, GoldenDict
artifacts) takes the noise. The `[p]` tags there are subject domains
(`уфол.`, `астрон.`), not word classes — no `pos` selector.

Format quirks to keep in mind (from the converter): DSL parenthesized
title variants concatenate into the headword (`dog(n.)` → `dogn.`), DSL
curly-brace homonym markers surface as a bold `<b>…</b>` prefix, and
StarDict sets with `sametypesequence=m` yield **plain-text** bodies in
`html` — selectors must not assume strict HTML.

### Prod deploy (python image rebuild)

The converter's `pyglossary` dependency is pinned in
`docker-compose/python/requirements.txt`, and the prod python image bakes
that file — dev is unaffected (the bind-mounted venv was pip-installed
directly). After this feature lands on master, the prod machine's next
deploy therefore needs the routine python image rebuild **before**
`./deploy.sh` will run: `requirements.txt` is one of the five stamped
container-definition files, so the stamp guard refuses (by design) until
the image is rebuilt and re-stamped:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml build python
docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile cloudflare up -d
./deploy.sh --stamp
./deploy.sh   # or re-run the Deploy workflow
```

(The generic flow is
[Production Deployment](/playbooks/production-deployment.md) → "When
images DO change".)

# Notes

* Parser: `App\Classes\WiktionaryParser` (reads the kaikki.org JSONL format
  directly — the former separate `KaikkiParser` was folded into it; reads
  `.jsonl` and `.jsonl.gz`). Extractor:
  `App\Classes\RawWiktextractExtractor`. Read the commands for the exact
  signatures before running large files.
* Dumps are large; imports are long-running — prefer running via
  `docker exec -d` or in a separate shell, and watch the log heartbeats.
* Never start test-suite runs while an import writes to the same database.
