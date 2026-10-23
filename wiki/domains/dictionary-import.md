---
type: Pipeline
title: Dictionary Import
description: Parsing Kaikki/Wiktionary dumps and converted GoldenDict dictionaries into the unified language-keyed dictionary tables and linking translations across every language pair (both import commands chain the link pass by default); each language's dictionary sources from its own Wiktionary edition dump and editions merge on overlap — existing rows keep their definitions and gain the new edition's (ADR 0080); both importers store plain (stress-free) display words and stage plain translation candidates for the stress-mark target languages, trimmed at storage; share one pos alias table, one lemma-form rule (ё-forms also emit an е-folded alias row), one ё-aware l_word key (trim → lowercase → combining-mark strip → zero-width/format-character strip → ё→е fold; display text never folds and keeps ё), one majority-script definition guard (`DefinitionLanguage`: a definition imports only in the word language's own script — en Latin, ru/be/uk Cyrillic — kept on a strict majority of its letters, dropped on a tie, letterless strings pass, unmapped languages unguarded; import-time only, etymologies never guarded), one usage-label mapping (kaikki `senses[].tags` intersect the closed `UsageLabels` set onto `definitions.labels` — null = unlabelled, and the re-import itself refreshes labels on case-insensitively same-text rows, the backfill being the re-import; plus the word's own headword mark — the `{{tlb}}`/`term-label` head-template args and the entry-level `tags` through the same closed set onto `words.labels`, the mark Wiktionary prints under the part-of-speech heading, kept out of `senses[]` by kaikki, the word upsert's update list carrying the column so the re-import backfills it), and one word-uniqueness triple (l_word, language_id, word_class_id) — cross-source spellings of one word (ёлка/елка, March/march) collapse into ONE row per class, the first source's display standing and staying hand-editable, existing duplicates merged onto the fullest survivor (ё-display first) before the unique builds (ADR 0081); kaikki form-of relay entries demote to forms of their base (shared parser-agnostic FormOfClassifier) instead of word rows, GoldenDict imports keep the phrase class to dictionary headwords and link translations without creating target words (ADR 0078, addendum 2026-10-18); multi-word headwords are shape-curated so junk verb rows never reach the multiword-verb lexicon (ADR 0059); the remaining word creators (cmudict, entity adoption, Filament manual create) attach to existing l_word rows instead of minting unknown-class twins and put new words through the same plain-display/lookup-key/lemma-form conventions and stamp the **Word source** column at insert (first importer wins, re-runs fill nulls only — ADR 0084; Filament manual create is the one creator that leaves it null), and the entity tokenizers (PHP/JS/python, parity-bound) stamp entity_words through the same lookup key; the already-stored residual repairs in place through the idempotent words:normalize phases (stress-strip merges, l_word recompute with same-key duplicate merge onto the fullest survivor — ё-display first — under either schema, before migrate builds the unique, unknown-twin merges, alias-class fold, entity stamp key recompute, lemma-form backfill).
tags: [dictionary, import, wiktionary, kaikki, goldendict, multi-word-verbs, form-of-relays]
status: stable
stale_after: 2027-01-19
generated: { by: agent:zcode, at: 2026-10-23T21:50:00+03:00 }
sources:
  - id: word-source-adr
    resource: docs/adr/0084-word-source-provenance.md
    title: ADR 0084 — words.source insert-stamped, first importer wins, re-runs fill nulls only
  - id: word-source-migration
    resource: laravel/database/migrations/2026_10_20_000003_add_source_to_words_table.php
    title: words.source — the nullable Word source column (popup-provenance ticket 03)
  - id: usage-labels
    resource: laravel/app/Support/UsageLabels.php
    title: UsageLabels — the closed usage-label set (kaikki tags and AI labels → definitions.labels)
  - id: adr-0081
    resource: docs/adr/0081-word-l-word-contract-uniqueness-on-l-word.md
    title: ADR 0081 — the word/l_word contract, uniqueness on (l_word, language, class)
  - id: adr-0078
    resource: docs/adr/0078-goldendict-plain-words-link-only-translations.md
    title: ADR 0078 — plain words, link-only translations
  - id: adr-0079
    resource: docs/adr/0079-form-of-relays-demote-to-forms-stage-link-prune.md
    title: ADR 0079 — relays demote to forms, lemma-as-form, stage → link → prune
  - id: adr-0080
    resource: docs/adr/0080-per-edition-wiktionary-sourcing-merge-on-overlap.md
    title: ADR 0080 — per-edition sourcing, editions merge on overlap
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
    title: dictionary:link-translations
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
    title: shared ё-aware l_word normalization (trim → lowercase → mark strip → zero-width/format strip → ё→е fold)
  - id: pos-aliases
    resource: laravel/app/Support/PosAliases.php
    title: shared pos alias table (dictionary abbreviations + kaikki slugs → canonical class slugs)
  - id: lemma-forms
    resource: laravel/app/Support/LemmaForms.php
    title: shared lemma-form rule and form-row hygiene (cap/strip/empty-guard/dedupe + ё-form е-alias rows)
  - id: definition-language
    resource: laravel/app/Support/DefinitionLanguage.php
    title: shared majority-script definition guard (en Latin, ru/be/uk Cyrillic; strict majority keeps, tie drops, letterless passes, unmapped languages unguarded)
  - id: translation-targets
    resource: laravel/app/Support/TranslationTargets.php
    title: shared link-only target-word resolver (all translation writers + repair commands)
  - id: staged-translations
    resource: laravel/app/Support/StagedTranslations.php
    title: shared staged-translations JSON semantics (union-merge in, prune-on-link out)
  - id: form-of-classifier
    resource: laravel/app/Classes/Wiktionary/FormOfClassifier.php
    title: parser-agnostic kaikki form-of relay verdict (entry-shape in → relay + base out)
  - id: relay-demotion-map
    resource: laravel/app/Classes/Wiktionary/RelayDemotionMap.php
    title: relay→base map unions (also-real subtraction, base conflicts, chain closure, cycle drop)
  - id: demote-cmd
    resource: laravel/app/Console/Commands/DemoteFormOfCommand.php
    title: wiktionary:demote-form-of (dump-driven relay cleanup, --dry, chunked set-based phases)
  - id: normalize-cmd
    resource: laravel/app/Console/Commands/WordsNormalizeCommand.php
    title: words:normalize (idempotent in-place repair phases, --dry, temp-map set-based merges)
  - id: cmudict-cmd
    resource: laravel/app/Console/Commands/ImportCmudictCommand.php
    title: dictionary:import-cmudict (attach-to-existing, plain + lemma form on create)
  - id: adoption
    resource: laravel/app/Classes/EntityWordAdoption.php
    title: EntityWordAdoption (adopts existing l_word rows; new tokens store plain + lemma form)
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

**Per-edition sourcing** (ADR
[0080](../../docs/adr/0080-per-edition-wiktionary-sourcing-merge-on-overlap.md),
2026-10-18): each language's dictionary sources from that language's own
Wiktionary **edition** dump — English words from the en-Wiktionary dump
([kaikki.org/dictionary/rawdata.html](https://kaikki.org/dictionary/rawdata.html)),
Russian words from the ru-Wiktionary edition
([kaikki.org/ruwiktionary/rawdata.html](https://kaikki.org/ruwiktionary/rawdata.html);
the en-Wiktionary "Russian" files, monolithic or pre-split, are a
different, lemma-poor source). Editions **merge on overlap, never
replace**: word rows upsert, definitions/etymologies insert-new-only —
the pre-existing en-sourced ru rows kept their English definitions and
gained the ru edition's Russian glosses, forms, IPA and etymologies
(собака: same row, 14 → 22 definitions, English verbatim + 8 Russian
added); en was untouched. The ru edition needed two parser field-compat
fixes (etymology `etymology_texts` plural array; `lang_code`-only
translation objects — both above), and a single-language import must
pass `--target-langs` (runbook has the commands). First real run
(2026-10-18, dev DB): extract 489,522 ru lines of 2,729,136; import
471,292 words, 6,963 relays demoted, 248 relay bases created, 6
placeholder word classes auto-created (abbrev/interjection/root/
onomatopeia/gerund/circumfix — titles want `/admin` curation); links
109,689 staged→linked (EN→RU 29,709 / RU→EN 79,980), raw
`word_translations` 111,556 → 186,353; `words:normalize` merged 1,167
unknown twins; frequency re-clamp ranked 18,324 new rows; residual
17,890 definition-less ru rows (the ru edition's own senseless
entries). ru ended at **500,430** — above the 460–470k plan because
"en-wiki ru words ⊆ ru-wiki" is false: 29,037 pre-existing en-era rows
the ru stream never touched (absent headwords; 5,742 different-class
pairs like Лета name-vs-noun; 14,899 relay-shaped rows whose surface
the ru dump demotes — the noted `wiktionary:demote-form-of
--file=kaikki/ru-raw-wiktextract-data.ru.jsonl` cleanup candidate). en
ended at 1,013,927 exactly.

# Components

* `App\Classes\WiktionaryParser` — parses kaikki.org JSONL dumps into the
  unified tables (the former separate `KaikkiParser` was folded into it).
  Reads plain `.jsonl` and, transparently, gzip `.jsonl.gz` via the
  `compress.zlib://` wrapper. Staging targets can be a single target code or
  a list (`new WiktionaryParser($lang, ['ru', 'de'])`) — see
  [ADR 0029](../../docs/adr/0029-import-raw-dump-via-per-language-extraction.md).
  For ru/be/uk the stored display **words and forms** drop their combining
  stress acutes — the raw dump carries stressed variants ("до́ма" for
  "дом") in both headword and form fields, and the ADR 0078
  display-headword carve-out is reversed (2026-10-18 addendum): no stress
  marks in stored keys or display words on any writer, definition/example
  prose keeps its marks, and a headword that strips to the empty string
  (a marks-only artifact) stores nothing. **Headwords and staged
  translation candidates trim at storage** (2026-10-19): only checking
  emptiness let padded dump strings ("␣ёлка␣") store verbatim and fragment
  lookups, so the headword, the relay base (a proxy headword's display)
  and every staged candidate trim before the store — en and ru alike. **Definitions import only in the word language's own script** (2026-10-19, word-import-contract): the definition flush runs every gloss through the shared `App\Support\DefinitionLanguage` majority-script guard before the insert — the user's "definitions in the word's language" rule (en-en, ru-ru) enforced at import time, so the en-Wiktionary edition's English glosses on ru words (and any future mirror case) store nothing. A definition survives when the language's own script holds a **strict majority** of its letters; an exact tie drops; a letterless string (digits/symbols only) has no script to contradict and keeps; a language absent from the guard's map (`en` Latin; `ru`/`be`/`uk` Cyrillic — `de`, `fr`, … unmapped) is **unguarded** and passes everything. Each dropped gloss counts in the import stat `definitions_skipped_foreign` (the summary table's "Definitions skipped (foreign language)"). Enforcement is **import-time only**: the guard rewrites no stored definition text, and the guard never sees etymologies (they cite source-language material in its own script by nature). **Sense tags label their definition** (2026-10-20, popup-ai-enrich ticket 09): a sense's `tags` array — previously discarded wholesale — now maps through `App\Support\UsageLabels::fromKaikkiTags` onto the imported definition's `definitions.labels` jsonb: the closed set (`obsolete`, `archaic`, `dated`, `colloquial`, `rare`, `dialectal`) survives in the dump's own order, duplicates collapse, and the grammar/gender noise (`masculine`, `singular`, `form-of`, …) drops with every unknown slug. Same gloss text from two senses dedupes on the text with the FIRST occurrence's labels standing. **Update-on-reimport** (2026-10-23, popup-usage-labels ticket 02, superseding the 2026-10-20 no-backfill stance): the flush also refreshes labels on a case-insensitively same-text stored row (the `word_id|lower(text)` merge key) to the incoming value — null included, so a source that stopped tagging a sense unlabels it again, and a repeat import converges to zero further updates — while the stored TEXT is never rewritten (case-only matches update in place), rows the ask-AI enrichment wrote (`source='ai'`) keep their labels whatever the dump says, and etymologies stay insert-new-only. Every changed row counts in the summary table's "Definitions labels updated" row, and there is no row-rewrite migration — the backfill IS the re-import (demotion wakes per-word as labelled data accumulates). The GoldenDict path still stores none — its headwords' usage markers were flattened away during HTML conversion, unrecoverable (documented, no code). **The word-level mark** (2026-10-23, popup-usage-labels follow-up): the label Wiktionary prints under the part-of-speech heading — "decimate (obsolete)" on the noun — is a headword-level `{{tlb|en|obsolete}}` template that kaikki keeps out of `senses[]`; the parser unions the `tlb` (alias `term-label`) head-template's numbered args (arg 1 is the language code) and the entry-level `tags` array (in the wiktextract schema, absent from this edition's dump) through the same `UsageLabels::fromKaikkiTags` filter — grammar words in the args ("transitive, chiefly archaic") drop with the unknown slugs — onto the record's `labels`, adjacent same-key lines union first-seen-first, and the word upsert stores it on `words.labels` jsonb (raw JSON string, null when nothing remains) with the column in its UPDATE list: the re-import is this mark's backfill too, and a source that stops marking the headword unmarks the row. The mark is deliberately the word's OWN label, never an aggregate of the senses' — an entry whose every sense happens to be labelled stays unmarked (the popup's section header dims and chips from the mark, not from the senses; [Interactive words](/domains/interactive-words.md)). **Staged translation candidates strip per target language** (2026-10-18 code review): a candidate whose
  `code` is a stress-mark language stores plain — unmatched candidates sit
  in the staged `words.translations` JSON verbatim until a link pass
  converts them, so the strip happens at staging time (a marks-only
  candidate stages nothing), while a non-stress-mark target's candidate
  keeps its diacritics; legacy rows staged marked before this strip
  remain prunable through the link pass's pairing-time strip. The staged
  candidate's target language reads its object's `code` **falling back to
  `lang_code`** (2026-10-18): en-edition translation objects carry both
  fields with equal values, while the ru-Wiktionary edition's carry only
  `lang_code` — without the fallback that edition would stage zero
  candidates. **Etymology reads both editions' field shapes** (2026-10-18):
  the singular `etymology_text` string (all the en edition emits) wins when
  present, else the plural `etymology_texts` **array** of text blocks (all
  the ru edition emits) joins its non-empty parts with newlines (an all-empty
  array stores nothing). Raw `pos`
  slugs canonicalize
  through the shared `App\Support\PosAliases` table before any word class
  is minted ("adj" joins "adjective" — one taxonomy, aliased lines of one
  word merge into a single row); `forms[]` entries tagged `romanization`
  skip the import (Latin transliterations are junk on Cyrillic words); and
  every imported word stores itself as its lemma form through the shared
  `App\Support\LemmaForms` row builder (column cap, stress strip,
  empty-after-strip guard, `(form, word_id)` dedupe, the word's own
  headword first — the forms table alone resolves lookups). Every
  lemma/form containing ё additionally emits its **е-folded alias row**
  (2026-10-19): ёлка carries the form rows ёлка AND елка, both keyed
  `елка` — texts and dictionaries that drop ё still resolve — while a
  source already listing both spellings dedupes through the same seen-map
  (two rows, not three). **Form-of
  relays demote to forms, not words** (2026-10-18): a kaikki entry whose
  EVERY sense carries the sense-level `form_of` list (+ the `form-of` tag)
  is an inflected variant standing alone ("четвереньках — prepositional of
  четвере́ньки") and imports as a **form of its base word** — no word row,
  no definitions/transcriptions/etymology/staged translations. The verdict
  lives in the parser-agnostic shared helper
  `App\Classes\Wiktionary\FormOfClassifier` (entry-shape in → relay +
  base out; mixed entries — some relay senses + some real glosses — stay
  words; an entry without senses stays a word; a base that collapses into
  the entry's own lookup key would demote the entry onto itself, so it
  stays a word; a trivially present entry-level `form_of` is honored). The
  base = `form_of[0].word`, stress-stripped for ru/be/uk, resolved by
  `l_word` with the shared class preference (same-class row, else lowest
  id — `App\Support\TranslationTargets`); a base the dump never lists as a
  lemma is **created** as a proxy dictionary headword (the relay's pos
  class, no staged translations, carrying itself as its lemma form), and
  the relay headword attaches to it through the same `LemmaForms`
  conventions. Merged same-key lines follow union-of-senses semantics: a
  relay line merged with a real line is a mixed entry (word); two relay
  lines demote together only onto an agreeing base. Import stats count the
  conversions (`relays_demoted`, `relay_bases_created`). Pre-existing
  relay word rows in the data are cleaned up by the dump-driven
  `wiktionary:demote-form-of` command (runbook in
  [Importing Dictionary Data](/playbooks/import-dictionary-data.md)): it
  streams the same dump, builds the exact relay→base map through the shared
  classifier plus the pure `App\Classes\Wiktionary\RelayDemotionMap`
  unions — a surface with any real dump entry stays a word, relay lines
  disagreeing on the base stay a word, chains close onto the terminal base
  (X→Y→Z demotes X and Y onto Z), cycles demote nobody — and applies it
  set-based per chunk: links (both arms) and `entity_words` re-point onto
  the base (canonical pairs deduped, self-pairs dropped, cross-chunk relay
  pairs resolved through the full map), the relay's staged translations
  JSON union-merges into the base, the relay surface attaches as a form
  (upsert on `(form, word_id)` — a base that already claims it keeps one
  row), the relay's frequency rank folds into the base as **LEAST** (ranks:
  lower = more common — never raises a rank, the unranked sentinel
  1,100,000 never demotes a ranked base; user-approved deviation from the
  ticket's "max" wording), the relay rows delete (children cascade), and
  romanization-tagged junk forms collected from the same stream delete by
  exact (entry `l_word`, form `l_word`) pairs for the stress-mark
  languages. Bases missing from the database keep their relays as words —
  counted, sampled (ru 1,060 / en 646 in the first run). Re-run safe:
  demoted relays no longer exist as word rows. The first real dev-DB run
  (2026-10-18, dump-driven, both languages): ru deleted 363,446 relay rows
  + 69,035 romanization junk forms (ru words 444,149 → 80,703), en deleted
  500,776 (en 1,523,426 → 1,022,650) — every destructive count matched its
  `--dry` exactly; the `--dry` child-move counts are documented
  approximations. The `WordFamily` read-layer relay-gloss defense stays
  (harmless after demotion). See
  [ADR 0078](../../docs/adr/0078-goldendict-plain-words-link-only-translations.md)
  and [ADR 0079](../../docs/adr/0079-form-of-relays-demote-to-forms-stage-link-prune.md).
* `App\Classes\RawWiktextractExtractor` — streams a **Raw dump** (a
  monolithic per-edition kaikki `raw-wiktextract-data` file, every
  Wiktionary language the edition covers interleaved) once and writes
  one **Language extract** per wanted code: raw
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
* `php artisan dictionary:link-translations` — the single staged-link
  pass: links words across languages through the staged `words.translations`
  JSON into `word_translations` rows, covering **every language pair**
  among languages that have imported words (one canonical row per pair,
  `word_a_id < word_b_id`). Candidates resolve through the shared
  `App\Support\TranslationTargets` resolver (same class as the source word
  → `phrase` for multi-word → any class, lowest id — the wanted-class map
  itself is the shared `TranslationTargets::wantedClasses` decision) with
  stress-mark stripping at pairing time (`App\Support\StressMarks`;
  legacy kaikki rows staged marked before the staging-time strip still
  arrive marked and prune by their exact stored string); every candidate
  it converts is **pruned** from the JSON (emptied JSON → null), so
  re-runs are idempotent and shrink the staging; unmatched candidates
  stay staged and are reported as a per-direction count plus a capped
  sample in the summary table. The first real dev-DB run (2026-10-18)
  converted 126,202 staged candidates into links and pruned the staging
  from 91,552 to 52,472 rows — while raw `word_translations` went
  112,585 → 111,556, because the repair commands' dedupe collapsed 5,927
  redundant pairs as the pass added ~4,898 genuinely new (growth is
  measured at the candidate→link level). `wiktionary:import-raw` and
  `goldendict:import` run it automatically at the end (each unless
  `--no-link`; the GoldenDict chaining also skips on `--dry-run` and when
  fewer than two languages hold words).
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
  (ADR 0059) — but a multi-word headword that carries sentence punctuation
  (`.,;:!?`) or more than 3 tokens is article junk the converter picked up
  (a comma-split name list like "Кулешова, Роза Алексеевна", a clause like
  "жировые складки на талии"): it is **rejected** (counted in the
  `headwords_rejected` stat), not imported (ADR 0078). Raw HTML never
  enters the database — the JSONL stays on disk and a better extractor can
  re-import it anytime. Flags: `--dry-run`
  (parse and report, write nothing — not even auto-created lookups),
  `--max-entries` (smoke runs), `--batch-size`, `--profile` (below). New
  words get the `FREQUENCY_UNRANKED` sentinel; re-imports refresh `l_word`
  only, so curated frequency ranks and staged translations survive.
  **Stress marks store nowhere** (ADR 0078): for the
  `App\Support\StressMarks` languages (ru/be/uk) the stored display
  `word`/`form` and every staged translation candidate are stripped of
  combining acutes at parse time, so a stressed dictionary variant
  collapses into the same row as the plain word (the kaikki importer
  strips its display headwords and inflected forms the same way — the ADR
  0078 display-headword carve-out is reversed); profile-selected forms
  strip like the `alts`, and a headword or form that strips to the empty
  string
  (a marks-only converter artifact) stores nothing — the headword counts
  in `headwords_rejected`, a form is skipped; definition prose keeps its
  marks. **Definition segments import only in the word language's own
  script** (2026-10-19, word-import-contract): the segment→definition path
  runs each segment through the shared `App\Support\DefinitionLanguage`
  majority-script guard before the cap and dedupe — the rule that keeps
  the bilingual GoldenDict dictionaries (Oxford en-ru, Mostitsky ru-en)
  from storing the other language's article prose as definitions. Own-script
  strict majority of letters keeps, an exact tie drops, a letterless
  segment (digits/symbols only) passes, an unmapped source language
  (anything outside en/ru/be/uk) is unguarded; each dropped segment counts
  in `definitions_skipped_foreign` (the summary table's "Definitions
  skipped (foreign language)"). The guard sits on the segment path, so a
  `--dry-run` tally reports the same record the real flush stores.
  Import-time only — stored definitions are never touched. The stats are new-only
  where writes are: `translations_linked` counts genuinely new pairs
  (diffed against the DB), so an idempotent re-run reports zeros, while
  `translation_candidates` counts every candidate the articles yield in
  real runs exactly like the dry-run tally does.
* `goldendict:import --profile=<slug>` — **extraction profiles**: a
  declarative XPath selector map per dictionary in
  `config/goldendict.php` (`transcriptions`/`translations`/`definitions`/`forms`/`pos`/`strip`
  keys; a class matches as a whole token via the
  `contains(concat(" ", normalize-space(@class), " "), "…")` idiom)
  layered over the generic heuristics, so structured dictionaries extract
  cleanly without code changes. Per selector kind and per article, a
  selector that matched nothing — or whose matched nodes hold only
  whitespace text after normalization (transcriptions additionally trim
  bracket/slash delimiters, so a bracket-only node is a miss too) — falls
  back to the
  heuristic; an extraction kind is never silently emptied and an entry is
  never dropped — **except translations when the profile configures a
  translations selector**: there the author vouches for where the
  equivalents live, so a miss means the article has no headword
  translations and no candidates are mined from the raw block text (the
  raw-text fallback was what turned stripped-example scaffolding into
  junk target words); without a configured selector the heuristic
  segments stay the source as before. Profile-selected transcriptions
  skip the IPA-marker gate
  (the profile author vouches for the nodes), profile-selected
  translation cells still pass the noise filters. The `pos` kind feeds the
  word class: its matched texts run through `posSlugFor` (the shared
  `App\Support\PosAliases` table — the one alias dictionary both importers
  read, same 24-char guard so spelled-out labels
  like "transitive verb" map) and the first mappable text overrides the
  heuristic pos hints for the article (still below the multi-word →
  `phrase` precedence), an unmappable text being a miss for the kind. The
  `strip` kind is subtraction, not extraction: its matched nodes are
  removed from the article DOM before any extraction — they vanish from
  the heuristic segments, IPA, pos hints, textContent and every other
  selector's matches — and a strip match never counts as a profile match
  (or miss). The `forms` kind (ADR 0078) treats its matched node texts as
  inflected-form lists: each node text splits on commas/semicolons/slashes
  into individual forms, stress-stripped for the ru/be/uk sources exactly
  like the entry's `alts` (an empty-after-strip piece is dropped), that
  **merge** with the `alts` (never replace them) in the forms table. Articles where NO
  selector matched are counted ("Profile no-match articles" stat row) and
  sampled (capped at 5) into one log notice so profile authors see what
  they are missing; an unknown slug fails with the known list, unknown
  keys or broken XPath fail up front. Tune profiles incrementally against
  real dictionaries (how-to: [Importing Dictionary Data](
  /playbooks/import-dictionary-data.md)) — no schema migrations, the
  JSONL just re-imports. The first real profile is `oxford-en-ru` (the
  Oxford Russian Dictionary En-Ru MDX): direct text nodes of
  `phoneticText` divs/spans for the BrE/AmE IPA (the sound-play glyphs
  live in `<a>` children, and a `normalize-space(.) != ","` predicate
  drops the separator node), `targetLangTranslation` spans for the
  Russian equivalents (excluding those inside `exampleGroup` blocks —
  the phrase-book example translations, defense in depth beside the
  `strip` selector that removes those blocks wholesale), `sense-1`/`sense-2`
  divs as the numbered sense units, `partOfSpeech` divs as the word
  class, and `exampleGroup` blocks stripped from the article before any
  extraction (their English example text glued to the Russian translation
  was the sentence junk that seeded the candidate guards below). The
  second is `mostitsky-ru-en` (Mostitsky eXtro Ru-En DSL): pyglossary
  renders the DSL `[trn]` equivalents as plain `padding-left:1em`
  paragraphs, so the selector hooks the one property that still
  distinguishes them from the Russian `[com]` commentary — the absence
  of Cyrillic, via a `translate()`-strips-the-alphabet equality test on
  the normalized text (the vouching-then applies: cross-reference
  articles yield no candidates instead of mined commentary); `span.sec`
  (► source links, «см. тж», GoldenDict artifacts) is stripped, the bold
  headword echo deliberately stays (that dictionary's `<b>` carries real
  content elsewhere), and its `[p]` tags are subject domains, not word
  classes — no `pos` selector.
* `goldendict:import --target-lang=xx` — **bilingual mode, link-only**
  (ADR 0078). Translation candidates come out of each article
  (comma/semicolon-delimited segments after
  stripping transcription runs, grammar tokens — POS abbreviations,
  gender/number tags — and the leading headword; each piece is end-trimmed
  through a UTF-8-safe regex character class, not `trim()`: the quote-mark
  trim set shares bytes with Cyrillic letters — "л" ends in the same 0xBB
  as "»", "р" the same 0x80 as "…" — so a byte-wise trim would cut
  characters in half and the batch upsert would die on invalid UTF-8;
  each piece must also survive
  the `usableCandidates` rule: no `[.,;:]`, ≤256 chars, no mixed
  Latin+Cyrillic (a piece carrying both scripts is a glued example
  sentence like "cars zoomed past маши́ны проноси́лись ми́мо"), at most
  4 whitespace-separated tokens (clause-sized pieces are sentences;
  4-token phrases stay), and not the
  article's own headword compared through the `WordLookupKey`
  normalization, so "Katze, cat" never links cat to itself; when either
  side of the pair is a ru/be/uk stress-mark language the segment loses
  its stress marks first, so candidates stage and match as plain letters
  and the leading headword echo — stressed ("до́м n. house") or not —
  strips cleanly). The importer **never creates target-language rows**: the
  target language's word list belongs to its own imports (kaikki,
  frequency lists, cmudict). Every candidate resolves **by `l_word`**
  against the target language's existing rows (a candidate whose
  normalized key the target language carries under ANY class links that
  row: "маши́ны" — stripped to "машины" — resolves to an existing
  "машины"; the row with the candidate's expected class wins — a
  single-word candidate prefers the source word's class in the target
  language, a multi-word one the `phrase` class, ADR 0059 as a linking
  preference — else the lowest id). Unresolved candidates stay **staged
  only**: the raw strings land in `words.translations` JSON (flat deduped
  list, the Wiktionary shape — merged on re-import, never clobbered) and
  are counted in `translation_targets_unmatched`, waiting for a re-run
  once the target word exists; resolved pairs write canonical
  `word_translations` links (`word_a_id < word_b_id`, ADR 0020) via
  batched upserts, `translations_linked` counting only genuinely new
  pairs. `--no-link` skips the link and staging phases **and** the
  trailing link pass (pure monolingual behavior; `--dry-run` skips the
  pass too); the target language must be a registry code other than
  `--lang`. Since 2026-10-19 a default run chains
  `dictionary:link-translations` at the end (skipped when fewer than two
  languages hold words), so a bilingual run converts its own staged
  candidates — and any earlier backlog — in the same invocation; inline
  linking itself never prunes. An effective import order is still base
  dictionaries first (kaikki, frequency, cmudict), then the bilingual
  GoldenDict runs — a later base import's candidates wait staged for the
  next pass (the next bilingual run, `wiktionary:import-raw`, or a manual
  `dictionary:link-translations`).

# GoldenDict vs Wiktionary imports

Both importers write the same unified tables with the same conventions
(batched `Word::upsert` on `(l_word, language_id, word_class_id)` —
kaikki refreshing the key and the staged JSON on conflict, GoldenDict the
key only; the in-batch dedupe and the post-upsert id lookups key the same
triple, since Postgres refuses an ON CONFLICT statement that would touch
one row twice — lookup
auto-creation, text-dedupe definitions, heartbeats) and share the `l_word`
normalization through `App\Support\WordLookupKey` — since 2026-10-19
**trim → lowercase → combining-mark strip → zero-width/format-character
strip (`\p{Cf}`: ZWSP, ZWJ, BOM, soft hyphen) → ё→е fold** (ёлка and елка
share the key `елка`; the combining marks strip unconditionally, en
included, via `App\Support\StressMarks`) — while the stored display words
and forms never fold and keep the source ё (the StressMarks stress-strip
rule only), and the `App\Support\PosAliases`
pos alias table (raw abbreviations and kaikki slugs canonicalize before
any word class is minted) and the `App\Support\LemmaForms` lemma-form rule
(every imported word carries itself as a form; ё-forms carry their
е-folded alias too) and — since 2026-10-19 — the
`App\Support\DefinitionLanguage` majority-script definition guard
(a definition imports only when the word language's own script holds a
strict majority of its letters; en-en, ru-ru; tie drops, letterless
passes, unmapped languages unguarded, etymologies never guarded — the
kaikki flush and the GoldenDict segment path both count their drops in
`definitions_skipped_foreign`). Both store plain display
words and forms for the ru/be/uk stress-mark languages — the ADR 0078
kaikki display-headword carve-out is reversed (2026-10-18 addendum); only
definition/example prose keeps its marks. The Wiktionary side reads kaikki
JSONL with structured fields
(`pos`, `senses`, `sounds`, `translations`); the GoldenDict side extracts
everything heuristically from article HTML and tunes per dictionary with
`--profile` extraction profiles (above). Translation linking
differs: the Wiktionary path **stores** translations during import and a
separate `dictionary:link-translations` pass links them against
already-existing words — pruning what it linked; the GoldenDict bilingual
path stages the raw
candidates and links them directly against the target language's existing
words (`--no-link` to opt out) — it never creates target rows, and the
command's trailing `dictionary:link-translations` pass (2026-10-19)
prunes what was linked. Both
write the same staged-JSON contract (`App\Support\StagedTranslations`:
exact stored strings, union-merge in, prune-on-link out) and resolve
through the same `App\Support\TranslationTargets` semantics (wanted-class
map included), as does the popup auto-fetch job — and both stage their
candidates plain for the stress-mark languages (GoldenDict at segment
strip, kaikki at candidate extraction).

# Creator hygiene (no unknown-class twins)

**Word source** (`words.source`, ADR
[0084](../../docs/adr/0084-word-source-provenance.md)): the nullable
string(16) recording WHICH creator minted a word row — the closed set
`kaikki | golddict | cmudict | entity | ai` (`Word::SOURCES`), stamped in
the INSERT payload by every creating writer (the importers' upsert row
arrays; the adoption and ask-AI mints' `firstOrCreate` values), and never
part of an update: the upserts' update lists omit it and the cmudict
conflict update pins `['word']` explicitly, so the first importer wins
when imports merge into the same row. There is no backfill — instead each
creating writer's per-batch write runs a fill-if-null claim
(`Word::fillSourceIfNull` for the importers: one UPDATE scoped to the
batch's `(l_word, word_class_id)` pairs in the language, still-null rows
only; the single-row mints claim the row their `firstOrCreate` found —
the race net). Re-running an import badges the pre-column rows it
touches; rows nothing touches again stay null, as do hand-created admin
words (the Filament form carries no source field — provenance is
machine-written; the Words table shows it as a column with a dash for
null and filters on it). A resolve-hit on the adopt paths is a read, not
a claim. The popup's provenance badge reads this column (popup-provenance
ticket 04).

Word uniqueness is `(l_word, language_id, word_class_id)` (2026-10-19,
ADR
[0081](../../docs/adr/0081-word-l-word-contract-uniqueness-on-l-word.md) —
before, it keyed on the display word): cross-source spellings of one word
(до́м/дом, ёлка/елка, March/march) are ONE row per class — the first
source's display spelling stands, a later source's import attaches to the
stored row and refreshes only the key and the staged candidates, and a
second row under the same key is impossible (the pre-move duplicates were
merged onto the fullest survivor — ё-display first — by normalize phase 2
before the index built; the dev DB switched over 2026-10-19). A
same-`l_word` row under a
DIFFERENT class is still a second row — an `unknown`-class twin of a
properly classed word (2,954 of them in the 2026-10-18 dev data, minted by
the cmudict import running before kaikki and by entity adoption). Every
creator resolves against the language's existing `l_word`s and only mints
a row when nothing matches:

* `dictionary:import-cmudict` looks each batch headword up by `l_word`
  first: the transcription attaches to the row(s) already there (every
  row without a ˈ-marked IPA gains it — the entity link may point at any
  class row of a homonym), and only an `l_word` no row carries creates a
  word row — through the shared conventions: plain display word
  (`StressMarks` strip on the shared path), `WordLookupKey` key and the
  `LemmaForms` lemma form (a cmudict-created word carries itself as its
  form).
* `EntityWordAdoption` (the crossword refresh chain, one batched
  candidates query per 500-token chunk) attaches a stamped token to the
  EXISTING same-`l_word` row of the language when one exists — the first
  non-unknown-class row in id order, else the lowest id, never a new
  `unknown` twin. Only a genuinely new token creates a row: class
  `unknown`, a plain display word (the combining stress marks leave the
  raw surface token for ru/be/uk; casing stays) that carries itself as its
  lemma form — and the mint is a `firstOrCreate` on the unique triple, so
  a row that raced in (or one the same normalized key reaches under a
  stale pre-fold entity stamp — legacy data only: the tokenizer keys
  entity stamps through `WordLookupKey` too, ё-fold included, so a ёлка
  token stamps `елка` and the ordinary link pass hits the е-keyed row
  directly) is adopted, not duplicated. A token that is
  nothing but combining marks stays stamped instead of storing a junk row.
* Filament's manual creators join the same contract. The Word page's
  "Create word & link" (Translations relation manager) strips the display
  word for ru/be/uk (a stressed input reuses the existing plain row),
  checks the typed word by its `WordLookupKey` key — the case/ё variant of
  an existing row is reused, not minted —, adds the lemma form on create,
  and its word search matches the normalized key so a stressed query still
  finds the plain row; the Forms relation manager strips the display form
  for the stress-mark languages and autofills a blank `l_word` through the
  same key, and the Word create page now autofills a blank `l_word` the
  same way (a typed key stays the author's input).

# Data repair (words:normalize)

The importers enforce the purity rules for NEW rows (above); the residual
damage in already-stored rows is repaired in place by
`php artisan words:normalize {--dry} {--chunk=20000}` — one idempotent,
re-runnable command, six phases, every phase counted in a summary table,
runbook in [Importing Dictionary Data](/playbooks/import-dictionary-data.md)
(run `--dry` first):

1. **Stress marks out of the display text** (spec rule 1): ru/be/uk
   `words.word` and `forms.form` lose their combining marks. A stressed
   word merges into an existing plain twin (same language + class +
   stripped display; lowest id survives and absorbs the children through
   the shared merge machinery below), without a twin it strips in place.
   Stressed forms group per (word, stripped form): a group whose plain twin
   already exists collapses onto it, otherwise the lowest form id survives
   the strip and the other variants in the group dedupe away — two stressed
   variants can strip to the same plain string (the earlier one-off repair's
   collision gotcha). en diacritics ("café") are legitimate spelling and
   untouched — the strip applies to the `App\Support\StressMarks` languages
   only. A display that is nothing but combining marks is counted and left
   alone (never deleted without a mandate). The twin lookup is
   display-exact, so a case variant (До́м strips to `Дом`, not `дом`)
   strips without merging — that residue is exactly what phase 2's
   duplicate merge absorbs.
2. **l_word keys recompute, duplicates merge**: every `words.l_word` and
   `forms.l_word` recomputes to the current `WordLookupKey` formula —
   chunked, updating only rows whose value actually changes (stale
   old-formula keys like ёлка, en diacritic keys café → `cafe`, NULL keys
   backfilled; a display that computes to the empty key stays unkeyed).
   The words pass then merges duplicate `(l_word, language_id,
   word_class_id)` rows — the residue pre-migrate databases hold
   (cross-source ёлка/елка spellings, case twins), which the words unique
   refuses to build over: the survivor preference is a **display
   containing ё first** (the fullest spelling survives — the user edits
   the display later), then **most definitions**, then the **lowest id**.
   Detection runs on the effective (post-recompute) keys — a stale-key row
   folding onto an existing key is a duplicate pair the stored keys alone
   cannot show — and the key updates apply only after the merge, so an
   update never collides with the unique it is meant to satisfy. The
   merged children, link arms, ledger rows and staged candidates move
   through the shared machinery below. The phase works under the OLD
   schema too — it must not need the unique index to exist: zero duplicate
   groups afterwards is the gate that lets `migrate` build it (the
   dev-DB normalize → migrate sequencing, 2026-10-19), and on an already
   migrated database both steps are no-ops.
3. **Unknown-class twins merge** into the properly-classed same-`l_word`
   row (lowest-id non-unknown survivor; the twins cmudict and entity
   adoption minted before the dictionary-word-purity creator-attach rules
   — that spec's ticket 08). The cmudict
   convention holds in the merge: a survivor already carrying a ˈ-marked
   IPA transcription is final — the twin's transcriptions die with it
   instead of stacking on top (Kaikki wins where it has stress data).
   Running after the recompute, its l_word join sees the folded keys.
4. **Alias word classes fold into their canonical class** per language
   (`App\Support\PosAliases` semantics resolved by class-row slug — the
   dev data's `adj` beside `adjective`): a word whose l_word exists in the
   canonical class merges into the lowest-id canonical row (case/ё variant
   twins fold too — one lookup key IS one word under the words unique, and
   a display-exact join would leave them twinless for a colliding
   reclass), the twinless leftovers reclass in place (NULL-l_word rows
   group by display instead — NULLs never conflict with the unique), and
   the orphan alias class rows delete last.
5. **Entity stamp keys recompute**: the entity tokenizer's
   `entity_words.l_word` stamps recompute from the token (the old
   tokenizer never folded ё). Stamps are unique per `(entity_id, l_word)`,
   so a recomputed key can only collide with another stamp of the same
   entity — two pre-fold spellings of one word: the counts fold into the
   already-correct stamp (or the lowest id), adopting a linked word when
   the survivor has none, and the loser deletes.
6. **Lemma-form backfill**: every word carries itself (its stripped
   display, the `LemmaForms` conventions) as a form where missing
   (~1.5M rows in dev) — insert-new-only through the `(form, word_id)`
   unique, chunked by word-id ranges with a progress bar.

Word merges share one set-based machinery: merge pairs land in a temp
mapping table, then each child move is a single SQL statement per table —
`definitions`/`etymologies`/`entity_words` update in place; unique-keyed
children (`forms`, `transcriptions`, `examples`, `pronunciations`,
`tagged_words`, `user_word`, `user_word_event`,
`word_translation_fetches`) move through insert-select
ON CONFLICT DO NOTHING + delete (collapsing onto the survivor's existing
rows and deduping within the map); `word_translations` re-point through
BOTH arms (canonical pairs, self-pairs dropped, insert-or-ignore dedupe);
the staged `words.translations` JSON union-merges onto the survivor
(`App\Support\StagedTranslations`); the frequency rank folds in as LEAST
(ranks: lower is more common — never raises a rank, and the 0 "no rank"
column default on cmudict/adoption rows never folds onto a ranked
survivor; the ticket-10 fold with a zero guard those rows need). Absorbed
word rows delete last (children cascade through the word_id-leading
indexes). `--dry` prints every phase count and writes nothing — the
child-move counts are pre-database approximations where insert-time dedupe
applies. Same-class same-`l_word` duplicates can no longer be CREATED
anywhere — the words unique forbids the pair — and the migration refuses a
database that still holds pre-existing same-key duplicates: existing twins
of pre-migrate databases are repaired by the normalize l_word recompute
and duplicate merge (phase 2) before `migrate` runs — the dev-DB runbook
sequences normalize → gate (zero duplicate groups) → migrate.

The first real dev-DB run (2026-10-18, in the pipeline order demote →
normalize → link-translations): 11 stressed display words merged + 1
stripped in place, 2,451 unknown-class twins merged, 7,514 alias
duplicates merged, 216,440 rows reclassed in place, 12 orphan alias class
rows deleted, 1,018,396 lemma forms backfilled, 9,976 absorbed rows
deleted — every destructive count matched its `--dry` exactly. All gates
passed after the pipeline (zero combining marks in ru words+forms, zero
unknown twins, one adjective class per language, zero words missing their
lemma form). See [ADR 0079](
../../docs/adr/0079-form-of-relays-demote-to-forms-stage-link-prune.md).

The six-phase pipeline's second real run was the word-import-contract
**dev-DB switchover** (2026-10-19): normalize FIRST — baseline `--dry`
(102s) reported 19,986 same-key rows to merge and 18,277 word + 131,220
form keys to recompute, the real run (184s) merged exactly those 19,986
duplicate rows plus 14 unknown-class twins phase 3 exposed post-merge
(the old phases' documented dry approximations; 20,000 absorbed rows
deleted in total) — then the gate (second `--dry` all-zero, SQL
duplicate-group count 0), then `migrate` built the
`(l_word, language_id, word_class_id)` unique in 1s, and the
post-migrate `--dry` was a full no-op. en 1,013,927 → 999,884 (−14,043),
ru 500,430 → 494,473 (−5,957) — the drop is exactly the absorbed rows;
ёлка/елка collapsed into one ё-display row carrying both form rows, and
собака kept all 24 definitions across its case-twin merge. The
combining-marks gate is per-language — 0 in ru `words.word`/`forms.form`
(1,494,357 + 5,568,861 rows scanned); en legitimately keeps its 334
mark-bearing rows, where the marks are spelling (ADR 0078 addendum).
Runbook and full numbers:
[Importing Dictionary Data](/playbooks/import-dictionary-data.md) and
[ADR 0081](
../../docs/adr/0081-word-l-word-contract-uniqueness-on-l-word.md).

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
data because of them: a dump `pos` first canonicalizes through the shared
`App\Support\PosAliases` alias table (dictionary abbreviations, Cyrillic
labels and kaikki slugs → canonical class slugs — "adj" never mints a
parallel class beside "adjective"), and a pos with no matching **word
class** after that (or a sound type with no matching **transcription
type**) is auto-created with the slug as a placeholder `title`, and the
word imports under it (the former
`words_skipped_pos` skip is gone). A fresh language therefore needs no
seeders — `INSERT` the language row, run the import, then curate the
placeholder titles in `/admin`. Import stats report the created lookups
(`lookups_created`).

# Multi-word headword hygiene (ADR 0059)

The import stores multi-word headwords under whatever pos the dump claims,
so junk lands under the verb class ("do it", "could have", "be there")
beside idioms ("kick the bucket") — and the translation-fetch job used to
store multi-word provider candidates under the provider pos, creating more
of the same (it even auto-created the `phrase` class after the
lookup-auto-creation convention). Since ADR 0059 the multi-word-verb lexicon
is gated by `App\Classes\MultiwordVerbShape` (2–4 tokens, every token after
the verb a closed-class particle/preposition), so polluted rows can no
longer produce false matches regardless, and since the link-only rework
(ADR 0078, 2026-10-18) the fetch job creates nothing at all: a multi-word
candidate merely PREFERS the target's existing `phrase` class when matching
(a single-word one the candidate/source pos class), links the row it finds,
and stages when the target language carries no such `l_word`.
`php artisan words:reclass-multiword {--dry-run}` reclasses the remaining
historical **English** junk verb rows to `phrase` (rows whose lookup key
already exists in the phrase class are skipped — the words table is unique
per (l_word, language, class), so a case-variant display hides nothing;
resolve those by hand). Other languages are
untouched — Russian multi-word verbs ("выдавать себя за") are legitimate
verbs the English shape has no opinion about. ADR 0078 draws the
GoldenDict boundary: `phrase` there is a headword-sourced class only (with
a junk guard), and translation candidates determine no word class at all.

# Where it surfaces

* Filament admin `/admin` (group "Words"): the per-language `WordResource`
  with relation managers for definitions, forms, translations, etymologies,
  examples, transcriptions, pronunciations, plus **Word class** and
  **Transcription type** resources for CRUD over the lookup tables. The
  **Translations** tab is the manual linking surface (attach / create word
  & link / delete, different languages only).

# Operating it

Step-by-step: [Importing Dictionary Data](/playbooks/import-dictionary-data.md).
