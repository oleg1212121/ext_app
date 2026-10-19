---
type: Playbook
title: Importing Dictionary Data
description: How to import Kaikki/Wiktionary dumps and GoldenDict-format dictionaries into the unified language-keyed dictionary tables, link translations, and repair the already-stored rows in place (words:normalize, wiktionary:demote-form-of).
tags: [dictionary, import, wiktionary, kaikki, goldendict, normalize]
status: stable
stale_after: 2027-01-19
generated: { by: agent:zcode, at: 2026-10-19T15:30:00+03:00 }
sources:
  - id: import-cmd
    resource: laravel/app/Console/Commands/ImportWiktionaryCommand.php
    title: wiktionary:import command
  - id: import-raw-cmd
    resource: laravel/app/Console/Commands/ImportRawWiktextractCommand.php
    title: wiktionary:import-raw command
  - id: link-cmd
    resource: laravel/app/Console/Commands/LinkTranslationsCommand.php
    title: dictionary:link-translations command
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
  - id: definition-language
    resource: laravel/app/Support/DefinitionLanguage.php
    title: Majority-script definition guard (definitions import only in the word language's own script)
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

* **Raw dump (per edition)** — `raw-wiktextract-data.jsonl.gz`, a whole
  Wiktionary *edition* with every language it covers interleaved. Each
  edition is its own kaikki.org download:
  * English edition (en.wiktionary, glosses in English):
    [kaikki.org/dictionary/rawdata.html](https://kaikki.org/dictionary/rawdata.html)
    (~2.7GB compressed, tens of millions of lines).
  * Russian edition (ru.wiktionary, glosses in Russian):
    [kaikki.org/ruwiktionary/rawdata.html](https://kaikki.org/ruwiktionary/rawdata.html)
    (~300MB compressed; the 2026-10-01 dump is 2,729,136 lines — ~1M
    hard-redirects the parser skips, ~489k `lang_code=ru` entries).

  Use `wiktionary:import-raw` (below). Each language's dictionary
  sources from its own edition, and editions **merge** on overlap —
  ADR
  [0080](../../docs/adr/0080-per-edition-wiktionary-sourcing-merge-on-overlap.md).
  (kaikki's pre-split "Russian" files are NOT the ru edition — also
  en.wiktionary, just pre-split, and equally lemma-poor.)
* **Per-language download** — kaikki.org's pre-split
  `kaikki.org-dictionary-<Language>.jsonl` files. Use
  `wiktionary:import` directly (bottom of this page).

# Steps — raw dump (wiktionary:import-raw)

1. Download the edition dump onto the host and put it under `laravel/`
   (e.g. `laravel/kaikki/raw-wiktextract-data.jsonl.gz` for the en
   edition, `laravel/kaikki/ru-raw-wiktextract-data.jsonl.gz` for the
   ru edition — URLs above) so the container sees it. No decompression:
   the parser reads `.jsonl.gz` transparently.
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
   # English edition — the historical full import, both languages, symmetric staging:
   docker exec -d ext_app_laravel sh -c 'cd /var/www && php artisan wiktionary:import-raw kaikki/raw-wiktextract-data.jsonl.gz --langs=en,ru >> storage/logs/import-raw.log 2>&1'
   # Russian edition — single language; --target-langs is then REQUIRED (gotcha below):
   docker exec -d ext_app_laravel sh -c 'cd /var/www && php artisan wiktionary:import-raw kaikki/ru-raw-wiktextract-data.jsonl.gz --langs=ru --target-langs=en >> storage/logs/import-ru-edition.log 2>&1'
   ```

   **`--target-langs` gotcha**: with a single-language `--langs=ru` the
   flag is not optional — the default staging targets are "every other
   imported language", so `--langs=ru` alone leaves the set empty and
   the parser refuses to start ("At least one target language is
   required"). Name at least one other registry code
   (`--target-langs=en` for the ru edition run).

   Phases: extract (writes `kaikki/<base>.<code>.jsonl` next to the
   dump, overwriting) → import each `--langs` language (symmetric
   translation staging by default) → link translations. Heartbeats
   every 100k lines (lines, kept counts, RSS, elapsed) land in
   `storage/logs/laravel.log`, so progress is observable even detached.

   **Merge, not replace** (ADR 0080): without `--fresh` a second
   edition's import into an existing dictionary is additive — word rows
   upsert, definitions/etymologies insert-new-only text-deduped — so
   the 2026-10-18 ru-edition run gave the pre-existing en-sourced ru
   rows Russian glosses beside their English ones (собака: same row, 14
   → 22 definitions) without touching the en side. Afterwards run the
   repair chain (`words:normalize --dry` first; `words:import-frequency
   ru-rnc` re-clamps ru ranks — the link pass already ran inside the
   import).
5. Useful flags: `--skip-extract` reuses existing extract files (e.g. rerun
   a failed import phase without a second decompression pass);
   `--extract-only` stops after the extract files; `--no-link` skips the
   linking pass; `--target-langs=ru` restricts staging to one direction —
   and is **required** when `--langs` names a single language (gotcha
   above); `--batch-size=500` tunes DB flush size.
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

   Missing per-language lookups are **auto-created** (the dump `pos` first
   canonicalizes through the shared `App\Support\PosAliases` alias table —
   "adj" imports as "adjective" — then an unseen slug → word class, an
   unseen sound type → transcription type, slug as placeholder
   title) — nothing is skipped, and a brand-new language needs no seeders;
   curate the placeholder titles in `/admin` afterwards. Every imported
   word stores itself as its lemma form (`App\Support\LemmaForms`; a
   ё-form also carries its е-folded alias row — ёлка AND елка, both keyed
   `елка`), and `romanization`-tagged forms and stressed ru/be/uk
   headwords never store — nor do marks in the staged translation
   candidates for ru/be/uk targets (they strip at staging time); headwords
   and staged candidates trim at storage, en and ru alike. A definition
   stores only when it is written in the word language's own script —
   the `DefinitionLanguage` majority-script guard (en Latin, ru/be/uk
   Cyrillic; strict majority keeps, tie drops, letterless passes,
   unmapped languages unguarded; etymologies unguarded) — and every
   dropped gloss counts in the summary table's "Definitions skipped
   (foreign language)" row. Import-time only: already-stored definitions
   are never touched (re-runs of an older dump re-insert nothing the
   guard now drops — the merge stays insert-new-only).
4. Link words across languages through the staged translations:

   ```bash
   docker exec ext_app_laravel php artisan dictionary:link-translations
   ```

   The command links **every language pair** among languages that have
   imported words, writing one canonical `word_translations` row per pair
   (`word_a_id < word_b_id`, ADR 0020). Candidates resolve through the
   shared `App\Support\TranslationTargets` resolver — same class as the
   source word first, `phrase` for multi-word candidates, else any class
   (lowest id) — and the Cyrillic stress marks strip at pairing time
   (e.g. `приве́т` → `привет`, the shared `App\Support\StressMarks`;
   rows staged marked before the staging-time strip prune by their exact
   stored string).
   **Prune-on-link**: every candidate it converts leaves the word's
   `words.translations` JSON (an emptied JSON stores null), so re-runs are
   idempotent and report fewer staged candidates; unmatched candidates
   stay staged for a later pass once their target words exist, and are
   reported as a per-direction count plus a capped sample in the summary
   table (never per-candidate lines — the staged volume is in the
   hundreds of thousands). Run it after populating all languages; a
   re-import refreshes `l_word` only, so the staged JSON survives
   unrelated imports and this pass is what finally shrinks it.
5. Verify in the Filament admin (`/admin`, group "Words"): the `Word`
   resource with relation managers for definitions, forms, translations,
   etymologies, examples, transcriptions, pronunciations; the **Word
   class** and **Transcription type** resources show (and let you edit)
   the auto-created lookups.
6. Link what the command could not match by hand: on a word's edit page,
   the **Translations** tab offers **Attach translation** (pick an existing
   word in another language) and **Create word & link** (create the missing
   target word and link it in one step). Links work from either word.

# Cleaning imported relay rows (wiktionary:demote-form-of)

Dumps imported before the form-of relay demotion (the ADR 0079 import
rule) left relay word rows in the data — at the 2026-10-18 cleanup, ru
363,446 and en 500,776 of them were deleted (~379k of 444k ru and ~974k
of 1.5M en words had relay-style glosses; the dump signal, not the gloss
pattern, is the cleanup's ground truth). The cleanup streams the same
kaikki dump for one language, rebuilds the exact
relay→base verdict through the shared `App\Classes\Wiktionary\FormOfClassifier`
plus the pure `App\Classes\Wiktionary\RelayDemotionMap` unions, and applies
it set-based in chunks — no re-import, no wipe: it deletes by explicit ids
from the dump map. On the current dev DB a re-run matches nothing; this
section applies when importing dumps that predate the rule or rebuilding
from scratch.

1. **Dry first** — read the counts before anything writes:

   ```bash
   docker exec ext_app_laravel php artisan wiktionary:demote-form-of --lang=ru --dry
   ```

   Prints every phase count without writing: relay surfaces found (minus
   surfaces that also carry a real dump entry, minus conflicting-base and
   cycle participants), relay word rows matched vs kept (base missing from
   the DB), would-be link re-points / self-pairs, entity_words, staged
   JSON merges, forms attached, re-ranked bases, deletions, and
   romanization junk forms. The `--dry` link counts are pre-database
   approximations (the real run dedupes onto existing pairs at insert
   time); everything else is exact.

2. **Real run, detached** (the dump-streaming pass dominates — expect tens
   of minutes at ru/en dump size; the progress bar lands in the redirected
   log so detached runs stay observable):

   ```bash
   docker exec -d ext_app_laravel sh -c 'cd /var/www && php artisan wiktionary:demote-form-of --lang=ru >> storage/logs/demote-ru.log 2>&1'
   ```

   Flags: `--file=` overrides the default dump path
   (`kaikki/kaikki.org-dictionary-<Language name>.jsonl` under the app
   root — any dump works, e.g. the ru edition extract
   `kaikki/ru-raw-wiktextract-data.ru.jsonl`: the noted cleanup for the
   14,899 relay-shaped en-era ru leftovers whose surface the ru dump
   carries only as another word's form, ADR
   [0080](../../docs/adr/0080-per-edition-wiktionary-sourcing-merge-on-overlap.md));
   `--chunk=N` (default 25000) sizes the set-based apply pass —
   the in-memory map holds every relay surface of the language (~1M en
   pairs fit comfortably; chunking bounds the DB statements, not the map).
   The command raises its own `memory_limit` to 2G (`ImportRawWiktextractCommand`
   precedent) — the relay map OOMs at the 128M CLI default mid-scan
   (verified 2026-10-18: the first dev-DB run died at ~105k dump lines
   before the bump).
   Per chunk, in FK-safe order: translation links (both arms) re-point
   onto the base — canonical pairs, insert-or-ignore dedupes onto
   pre-existing pairs, self-pairs (relay→own base, two relays of one base)
   dropped, relays demoting in a LATER chunk resolved through the full map
   so their pairs survive both deletions; `entity_words.word_id` re-points
   (the token's `l_word` stays the surface the entity text carries); the
   relay's staged `words.translations` JSON union-merges into the base;
   the relay surface attaches as a form of the base (upsert on
   `(form, word_id)` — a base that already claims the surface keeps one
   row); the relay's frequency rank folds into the base as **LEAST**
   (ranks: lower = more common — never raises a rank; the unranked
   sentinel 1,100,000 never demotes a ranked base, an unranked base
   inherits a ranked relay's rank — the user-approved fold, ADR 0070's
   convention); then the relay rows delete (definitions and relay-glosses
   die with the row — pure pointers; children cascade fast through the
   word_id-leading indexes). Finally, for the stress-mark languages
   (ru/be/uk) the romanization-tagged junk forms (Latin transliterations
   on Cyrillic words) collected from the same stream delete by exact
   (entry `l_word`, form `l_word`) pairs — dump-derived, no heuristic.

3. **What stays**: a surface with any real dump entry stays a word;
   bases missing from the database keep their relays as words — counted
   and sampled in the summary; relay rows that resolve nothing stay put.
   **Re-run safe**: a demoted relay no longer exists as a word row, so a
   second run matches nothing (the missing-base sample keeps reporting the
   kept relays each run). The `WordFamily` read-layer relay-gloss defense
   stays — harmless once the relays are gone. Chain the runs before
   `dictionary:link-translations` in a fresh pipeline: demote first, link
   second, so staged JSON the demote moved onto bases links in the same
   pass.

# Repairing stored data in place (words:normalize)

The importers enforce the purity rules for new rows; the damage already
stored from before those rules is repaired in place by one idempotent
command — no re-import, no wipe: every phase finds its rows by explicit
mapping (strip diff, unknown-class twins, alias class rows, missing lemma
forms) and a second run matches nothing. The dev DB ran it 2026-10-18
(what the counts landed on): 11 stressed display words merged + 1 stripped
in place, 2,954 unknown-class twins merged (ru 1,536 / en 1,418, mostly
cmudict transcriptions), 7,514 alias duplicates merged (the
`adj`/`adjective` fold) + 216,440 rows reclassed, and 1,018,396 lemma
forms backfilled (en 1,507,795 / ru 29,171 had none — the long phase). After that run every phase reports
zeros; a pre-rule database needs the baselines re-counted first (a `--dry`
gives them all).

1. **Dry first** — read every phase count before anything writes:

   ```bash
   docker exec ext_app_laravel php artisan words:normalize --dry
   ```

   Prints all phase rows without writing: stressed words merged into plain
   twins / stripped in place, stressed forms collapsed / stripped /
   deduped, unknown twins merged, alias-class words merged vs reclassed and
   alias classes deleted, lemma forms backfilled, plus the merge-machinery
   children (definitions/etymologies, forms, transcriptions incl. the
   ˈ-IPA gate drops, examples, pronunciations, entity_words, tagged_words,
   user_word(+event), fetch ledger, both-arm link re-points with self-pair
   and dedupe counts, staged-JSON merges, LEAST rank folds, absorbed rows
   deleted). The child-move counts are pre-database approximations where
   insert-time dedupe applies; phase-3 reclass counts include rows a real
   run would merge. Nothing else is approximate.

2. **Real run, detached** (the lemma backfill dominates — tens of minutes
   at en scale; the progress bar lands in the redirected log):

   ```bash
   docker exec -d ext_app_laravel sh -c 'cd /var/www && php artisan words:normalize >> storage/logs/words-normalize.log 2>&1'
   ```

   Phases, in order: (1) ru/be/uk display `words.word`/`forms.form` lose
   their combining stress marks — a stressed word merges into an existing
   plain twin (same language + class + stripped display, lowest id
   survives and absorbs the children), otherwise it strips in place;
   stressed forms group per (word, stripped form) — a group whose plain
   twin already exists collapses, otherwise the lowest form id survives
   and the other variants dedupe (two stressed variants can strip to one
   plain string). (2) `unknown`-class twins merge into the lowest-id
   non-unknown same-`l_word` row; when the survivor already carries a
   ˈ-marked IPA transcription the twin's transcriptions die with the merge
   (the cmudict convention — Kaikki wins where it has stress data).
   (3) Alias word classes fold into their canonical class per language
   (PosAliases semantics, resolved by class-row slug): display twins merge,
   twinless leftovers reclass in place, orphan alias class rows delete
   last. (4) Every word gains itself as its lemma form where missing
   (insert-new-only on the `(form, word_id)` unique, chunked).

   `--chunk=N` (default 20000) sizes the scan passes and the lemma
   backfill; the word merges are set-based through a temp mapping table —
   one statement per child table. Never run two normalize runs at once.
   The command raises its own `memory_limit` to 2G (same
   `ImportRawWiktextractCommand` precedent as the demote command) — the
   alias-fold group map and the merge maps hold real-data-sized row sets
   and OOM at the 128M CLI default (verified 2026-10-18 in the phase-3
   alias fold on the dev DB).

3. **Chain it in the repair pipeline** (the 2026-10-18 dev-DB order, all
   `--dry` first) — `wiktionary:demote-form-of` per language, then
   `words:normalize`, then `dictionary:link-translations` (so staged JSON
   the repairs moved onto survivors links in the same pass), then
   `words:import-frequency-lists` (band ranks land on bases through the
   lemma-form join — 82,833 of the run's 160,753 matches) and
   `crossword:link` (the repairs re-pointed `entity_words` onto bases;
   `--retry-unmatched` re-attempts stale unmatchable stamps). Re-run safe:
   every phase reports zeros on a second run. Case-variant same-class
   duplicates are deliberately NOT merged (March/march can be genuinely
   different words); resolve genuine ones by hand.

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
twins — including the kaikki ru/be/uk headwords since the 2026-10-18
carve-out reversal, `adj`/`adjective` alias twins, romanization-tagged
forms, missing lemma forms, translation-created bare stubs, junk `phrase`
rows), the fix is a wipe + re-import, not an in-place patch — unless the
dedicated repair commands cover the residual (`words:normalize` and
`wiktionary:demote-form-of`, above; the dictionary-word-purity tickets).
Import order matters — base
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
5. Verify: no U+0301 in stored ru `words.word`/`forms.form` (headwords
   included — the kaikki path strips them now too), no Latin
   romanization-tagged forms on ru words, every imported word carries
   itself among its forms (lemma form), no `adj` word class beside
   `adjective`, no translation-created bare ru stubs, `phrase` rows only
   under dictionary headwords.

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
