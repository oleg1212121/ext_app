---
type: Playbook
title: Importing Dictionary Data
description: How to import Kaikki/Wiktionary dumps and GoldenDict-format dictionaries into the unified language-keyed dictionary tables and link translations.
tags: [dictionary, import, wiktionary, kaikki, goldendict]
status: stable
stale_after: 2026-12-16
generated: { by: agent:zcode, at: 2026-10-14T16:29:38+03:00 }
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
MDict `.mdx`, ABBYY Lingvo `.dsl` (UTF-8 or UTF-16LE) and StarDict sets
(an `.ifo` path or a directory containing exactly one set) — see the usage
note in `docker-compose/python/ai/goldendict_convert.py`. Two stages:

1. **Convert** on the host through the bind-mounted python venv (stage 1,
   writes the JSONL next to the source; encrypted/regcode MDX is rejected
   with a clear error):

   ```bash
   docker-compose/python/ai/venv/bin/python \
       docker-compose/python/ai/goldendict_convert.py DICT.mdx
   # -> DICT.mdx.jsonl with {"word", "alts", "html"} records
   ```

2. **Smoke-run the import dry** against the testing DB, then import
   (stage 2; `--lang` accepts any registry code, missing lookups
   auto-create like the Wiktionary import):

   ```bash
   docker exec ext_app_laravel sh -c 'cd /var/www && DB_CONNECTION=testing php artisan migrate:fresh --seed'
   docker exec ext_app_laravel sh -c 'cd /var/www && DB_CONNECTION=testing php artisan goldendict:import DICT.mdx.jsonl --lang=xx --max-entries=500 --dry-run'
   docker exec ext_app_laravel php artisan goldendict:import DICT.mdx.jsonl --lang=xx
   ```

   Extraction is a generic heuristic (plain-text block segments →
   definitions, `[bracketed]`/`/slashed/` IPA → transcriptions under the
   auto-created `ipa` type, POS abbreviations → word-class slugs, `alts` →
   forms, multi-word headwords → `phrase`). For a **bilingual**
   dictionary add `--target-lang=yy`: translation candidates (the
   comma/semicolon-delimited article segments that survive the noise
   filters) become target-language words — the source class slug mapped
   into the target language, multi-word candidates → `phrase` — plus
   canonical `word_translations` links and `words.translations` JSON
   staging, all written directly (no separate link pass; `--no-link`
   skips these phases). For dictionaries whose articles have structure
   the heuristics mangle, add `--profile=<slug>` (below). Re-runs upsert
   and text-dedupe — raw article HTML never enters the database, so a
   better extractor just re-imports the JSONL.

### Extraction profiles (--profile=<slug>)

A profile is a declarative XPath selector map in
`laravel/config/goldendict.php` that tunes the generic heuristics for ONE
dictionary — no code changes per dictionary. To author one:

1. Convert a slice of the dictionary and smoke-run **without** the profile
   (`--dry-run --max-entries=500`) to get the baseline stat row.
2. Copy the `example-classes` profile in `config/goldendict.php`, rename
   the slug, and retarget the three selector keys at the dictionary's
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
   Each value is an XPath 1.0 string or a list of them (queried in
   order). The `contains(concat(" ", normalize-space(@class), " "), "…")`
   idiom matches a class as a whole token.
3. Smoke-run **with** `--profile=<slug> --dry-run` and compare the stat
   rows — the demo is the same JSONL visibly extracting differently.
4. Iterate: a selector that matches nothing in an article falls back to
   the heuristic for that kind and article — an entry is never dropped —
   and every article where NO selector matched is counted in the
   "Profile no-match articles" stat row and sampled (capped) into one
   `Goldendict profile '…': no selector matched in N articles` notice in
   `storage/logs/laravel.log`, so you can see what you are missing.
5. Known-slug safety: an unknown slug fails the command with the known
   list; unknown keys or syntactically broken XPath fail up front before
   anything is written.

Format quirks to keep in mind (from the converter): DSL parenthesized
title variants concatenate into the headword (`dog(n.)` → `dogn.`), DSL
curly-brace homonym markers surface as a bold `<b>…</b>` prefix, and
StarDict sets with `sametypesequence=m` yield **plain-text** bodies in
`html` — selectors must not assume strict HTML.

3. Verify in the Filament admin like the Wiktionary flow above. freemdict
   dictionaries are frequently commercial rips — fine for personal
   learning use, do not redistribute the extracted data.

# Notes

* Parser: `App\Classes\WiktionaryParser` (reads the kaikki.org JSONL format
  directly — the former separate `KaikkiParser` was folded into it; reads
  `.jsonl` and `.jsonl.gz`). Extractor:
  `App\Classes\RawWiktextractExtractor`. Read the commands for the exact
  signatures before running large files.
* Dumps are large; imports are long-running — prefer running via
  `docker exec -d` or in a separate shell, and watch the log heartbeats.
* Never start test-suite runs while an import writes to the same database.
