---
type: Pipeline
title: Dictionary Import
description: Parsing Kaikki/Wiktionary dumps and converted GoldenDict dictionaries into the unified language-keyed dictionary tables and linking translations across every language pair; both importers store plain (stress-free) display words, share one pos alias table and one lemma-form rule, kaikki form-of relay entries demote to forms of their base (shared parser-agnostic FormOfClassifier) instead of word rows, GoldenDict imports keep the phrase class to dictionary headwords and link translations without creating target words (ADR 0078, addendum 2026-10-18); multi-word headwords are shape-curated so junk verb rows never reach the multiword-verb lexicon (ADR 0059); the remaining word creators (cmudict, entity adoption, Filament manual create) attach to existing l_word rows instead of minting unknown-class twins and put new words through the same plain-display/lookup-key/lemma-form conventions; the already-stored residual repairs in place through the idempotent words:normalize phases (stress-strip merges, unknown-twin merges, alias-class fold, lemma-form backfill).
tags: [dictionary, import, wiktionary, kaikki, goldendict, multi-word-verbs, form-of-relays]
status: stable
stale_after: 2027-01-18
generated: { by: agent:zcode, at: 2026-10-18T19:40:00+03:00 }
verified: { by: human:alex, at: 2026-10-18T19:40:00+03:00 }
sources:
  - id: adr-0078
    resource: docs/adr/0078-goldendict-plain-words-link-only-translations.md
    title: ADR 0078 — plain words, link-only translations
  - id: adr-0079
    resource: docs/adr/0079-form-of-relays-demote-to-forms-stage-link-prune.md
    title: ADR 0079 — relays demote to forms, lemma-as-form, stage → link → prune
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
    title: shared l_word normalization
  - id: pos-aliases
    resource: laravel/app/Support/PosAliases.php
    title: shared pos alias table (dictionary abbreviations + kaikki slugs → canonical class slugs)
  - id: lemma-forms
    resource: laravel/app/Support/LemmaForms.php
    title: shared lemma-form rule and form-row hygiene (cap/strip/empty-guard/dedupe)
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
  (a marks-only artifact) stores nothing. Raw `pos` slugs canonicalize
  through the shared `App\Support\PosAliases` table before any word class
  is minted ("adj" joins "adjective" — one taxonomy, aliased lines of one
  word merge into a single row); `forms[]` entries tagged `romanization`
  skip the import (Latin transliterations are junk on Cyrillic words); and
  every imported word stores itself as its lemma form through the shared
  `App\Support\LemmaForms` row builder (column cap, stress strip,
  empty-after-strip guard, `(form, word_id)` dedupe, the word's own
  headword first — the forms table alone resolves lookups). **Form-of
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
* `php artisan dictionary:link-translations` — the single staged-link
  pass: links words across languages through the staged `words.translations`
  JSON into `word_translations` rows, covering **every language pair**
  among languages that have imported words (one canonical row per pair,
  `word_a_id < word_b_id`). Candidates resolve through the shared
  `App\Support\TranslationTargets` resolver (same class as the source word
  → `phrase` for multi-word → any class, lowest id) with stress-mark
  stripping at pairing time (`App\Support\StressMarks`); every candidate
  it converts is **pruned** from the JSON (emptied JSON → null), so
  re-runs are idempotent and shrink the staging; unmatched candidates
  stay staged and are reported as a per-direction count plus a capped
  sample in the summary table. The first real dev-DB run (2026-10-18)
  converted 126,202 staged candidates into links and pruned the staging
  from 91,552 to 52,472 rows — while raw `word_translations` went
  112,585 → 111,556, because the repair commands' dedupe collapsed 5,927
  redundant pairs as the pass added ~4,898 genuinely new (growth is
  measured at the candidate→link level). `wiktionary:import-raw` runs it
  automatically at the end (unless `--no-link`).
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
  marks. The stats are new-only
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
  pairs. `--no-link` skips the link and staging phases (pure monolingual
  behavior); the target language must be a registry code other than
  `--lang`. Because the target dictionary no longer grows, an effective
  import order is base dictionaries first (kaikki, frequency, cmudict),
  then the bilingual GoldenDict runs — or re-run them after the base
  import to link the staged candidates.

# GoldenDict vs Wiktionary imports

Both importers write the same unified tables with the same conventions
(batched `Word::upsert` on `(word, language_id, word_class_id)`, lookup
auto-creation, text-dedupe definitions, heartbeats) and share the `l_word`
normalization through `App\Support\WordLookupKey` (lowercase + combining-
mark strip via `App\Support\StressMarks`), the `App\Support\PosAliases`
pos alias table (raw abbreviations and kaikki slugs canonicalize before
any word class is minted) and the `App\Support\LemmaForms` lemma-form rule
(every imported word carries itself as a form). Both store plain display
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
words (`--no-link` to opt out) — it never creates target rows. Both
write the same staged-JSON contract (`App\Support\StagedTranslations`:
exact stored strings, union-merge in, prune-on-link out) and resolve
through the same `App\Support\TranslationTargets` semantics, as does the
popup auto-fetch job.

# Creator hygiene (no unknown-class twins)

Word uniqueness is `(word, language_id, word_class_id)`, so a same-`l_word`
row under a different class is a second row — an `unknown`-class twin of a
properly classed word (2,954 of them in the 2026-10-18 dev data, minted by
the cmudict import running before kaikki and by entity adoption). Every
remaining creator resolves against the language's existing `l_word`s and
only mints a row when nothing matches:

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
  lemma form. A token that is nothing but combining marks stays stamped
  instead of storing a junk row.
* Filament's manual creators join the same contract. The Word page's
  "Create word & link" (Translations relation manager) strips the display
  word for ru/be/uk (a stressed input reuses the existing plain row),
  normalizes `l_word` through `WordLookupKey` instead of a bare
  `mb_strtolower`, adds the lemma form on create, and its word search
  matches the normalized key so a stressed query still finds the plain
  row; the Forms relation manager strips the display form for the
  stress-mark languages and autofills a blank `l_word` through the same
  key.

# Data repair (words:normalize)

The importers enforce the purity rules for NEW rows (above); the residual
damage in already-stored rows is repaired in place by
`php artisan words:normalize {--dry} {--chunk=20000}` — one idempotent,
re-runnable command, four phases, every phase counted in a summary table,
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
   alone (never deleted without a mandate).
2. **Unknown-class twins merge** into the properly-classed same-`l_word`
   row (lowest-id non-unknown survivor; the twins cmudict and entity
   adoption minted before the ticket-08 attach rules). The cmudict
   convention holds in the merge: a survivor already carrying a ˈ-marked
   IPA transcription is final — the twin's transcriptions die with it
   instead of stacking on top (Kaikki wins where it has stress data).
3. **Alias word classes fold into their canonical class** per language
   (`App\Support\PosAliases` semantics resolved by class-row slug — the
   dev data's `adj` beside `adjective`): a word whose exact display twin
   exists in the canonical class merges into it, the twinless leftovers
   reclass in place (case variants never merge — capitalized entries can
   be genuinely different words), and the orphan alias class rows delete
   last.
4. **Lemma-form backfill**: every word carries itself (its stripped
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
applies. Case-variant same-class duplicates are deliberately NOT merged
anywhere: March/march can be genuinely different words.

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
historical **English** junk verb rows to `phrase` (rows whose headword
already exists in the phrase class are skipped — the words table is unique
per (word, language, class); resolve those by hand). Other languages are
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
