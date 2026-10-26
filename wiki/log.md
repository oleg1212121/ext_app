# Directory Update Log

Newest first; one `## YYYY-MM-DD (title)` entry per change that touches the
bundle, added at the top. Entries older than the current quarter roll off into
`archive-<quarters>.md` (see [archive-2026-q2-q3.md](archive-2026-q2-q3.md))
at each quarter boundary — link there, not to a dated entry here, when
pointing readers at pre-quarter history.

## 2026-10-26 (WordTestService reworked to CEFR band sampling/scoring — concept intentionally lagging until ticket 05)

Second slice of the cefr-word-test feature: the 20-bucket quantile sampler
in `WordTestService` is gone. Sampling now draws 60 headwords per test from
the CEFR tag groups (`a1`…`c2`, one distinct-on-`l_word` query per band, no
frequency filter — tag membership defines the pool), redistributed evenly
over the tested bands (10/12/15/20/30/60 per band); a stored level skips
the bands below it (`sample(language, currentLevel)`), their floor anchor
becomes the score base, and a spelling tagged in two levels is claimed at
most once, in the lower band. Scoring is a band-credit sum:
`base + Σ band_width × known/drawn` clamped to 20 000, with the band widths
derived from the new hard-coded `CEFR_ANCHORS` (1000/3000/6000/10000/15000/
20000). `mark()`/`resolveSample()` and the cache-token architecture are
unchanged; controller wiring (the column feeding `currentLevel`, raise-only
persistence, the `groups` prop's consumer) is ticket 03. **Deliberate doc
lag:** `domains/word-test.md` still describes the bucket sampler — this
ticket only baselined its drift warning (pointing at the spec's ticket 05
docs pass) instead of rewriting the concept mid-feature; ticket 05 owns the
real refresh (plus ADR 0090 and the CONTEXT.md Word Test context).

## 2026-10-26 (`users.vocabulary_level` — the persisted placement level, schema only)

First slice of the cefr-word-test feature (CEFR-anchored adaptive placement
test): a new `users.vocabulary_level` column (`unsignedSmallInteger`,
default 0, comment naming the 0–20000 frequency-rank scale and its
raise-only write rule — ADR 0090 arrives with the feature's docs pass) and
`vocabulary_level` in `User::$fillable`. No consumer yet — tickets 02/03
wire the scoring and the raise-only submit (`max(old, new)`). Behavior is
pinned by `tests/Feature/VocabularyLevelColumnTest.php` (default, persist,
mass assignment). Wiki upkeep: `reference/models.md` regenerated (the
users row now lists the attribute); `domains/access-control.md`
`generated.at` bumped after review — the fillable addition doesn't touch
that concept's authorization content, it only re-minted source-mtime
drift. Five PRE-EXISTING unacknowledged drift warnings (deploy scripts,
`CefrWordTagSeeder` post-review, the baseline file itself — all minted by
cefr-word-tags commits after that feature's docs pass) are now baselined
with notes pointing at this feature's ticket 05 docs pass, which must
refresh or prune them; `WikiTest` was red on them before this change.

## 2026-10-26 (CEFR word lists become six seeded tags on the words tag pool)

The six CEFR level lists (a1–c2) joined the words tag pool (ADR 0076) as
seeded, seeder-owned tags, and the knowledge base caught up:

- **`CefrWordTagSeeder`** (new, `laravel/database/seeders/`): owns the six
  CEFR tags (slugs `a1`…`c2`, names `CEFR A1`…`CEFR C2`). Its embedded
  `LISTS` arrays (7,226 entries after normalization) are the lists'
  permanent source of truth — the `public/texts/{a1..c2}.txt` origin files
  stay on disk but are inert (never read at runtime). Every run
  full-replaces each tag's attachment set (updateOrCreate the tag → delete
  all its `tagged_words` rows → re-attach): matching is English-only on
  `l_word` and attaches every part-of-speech row of a spelling; unmatched
  entries are skipped and reported (typo surfacing). Manual Filament
  attachments to the six owned slugs revert on the next run. Wired into
  `DatabaseSeeder` + `deploy.sh` + `deploy-native.sh`.
- **`database/dictionary.md`**: the `tags`/`tagged_words` row and the
  Tag-pools note record the seeding (the pool no longer "ships empty"), the
  full-replace ownership rule, and the ruling that CEFR levels are tags,
  deliberately NOT `words.labels` (that column stays the closed
  `UsageLabels` register set); the seeder joined the concept's sources.
- **Dev-DB run (2026-10-26)**: the seeder ran twice against the dev DB —
  14,601 word rows attached across the six levels (a1 1,634, a2 2,964,
  b1 2,591, b2 2,930, c1 3,371, c2 1,111); 37 list entries were unmatched
  (skipped + reported — the report is user-facing typo surfacing, verbatim
  in the feature ticket; list corrections are the user's call); the second
  run re-attached the identical set (idempotent).
- Collateral drift stamps: `architecture/docker-services.md` +
  `playbooks/production-deployment.md` (deploy.sh/deploy-native.sh changed
  under them; the playbook's two seed-step lists gained
  `CefrWordTagSeeder`).

## 2026-10-25 (knowledge-base cleanup: log archive + quarterly policy, ADR renumber + index, concept restructures)

Documentation-only pass — no application behavior changed. Four parts:

- **Log archive.** `log.md` carried 263 entries back to 2026-07-26 in two
  competing orders (newest-first top block + a 36-entry oldest-at-bottom tail
  block). The 114 pre-October entries (3,615 lines, 41% of the file) moved to
  `archive-2026-q2-q3.md` and the remaining 149 October entries were rewritten
  strictly newest-first. `WikiBundle::isReserved` now reserves any `archive-*`
  basename (one-line change: no frontmatter needed, links still validated).
  The log header records the standing policy: entries older than the current
  quarter roll off at each quarter boundary. Pointers into archived content
  updated (`AGENTS.md` DB-wipe incident → archive 2026-08-03;
  `architecture/frontend.md` 2026-09-23 freeze; `domains/reader.md`
  pass-by-pass timeline).
- **ADR renumber + index.** The languages pair `0001-languages-standalone-registry`
  / `0002-languages-enabled-first-consumer` collided with the older alignment
  ADR 0001/0002 — every "ADR 0001/0002" reference was ambiguous. Renumbered to
  **0088/0089** (renumber notes inside both files; the four log.md
  languages-sense references repointed). New `docs/adr/README.md` indexes all
  89 ADRs by cluster with the one superseded relation (0005 ← 0016).
- **Concept restructures.** `interactive-words.md` and `dictionary-import.md`
  carried ~2,000-3,500-char run-on `description:` frontmatter sentences; both
  replaced with 3-sentence summaries (the body already carried every claim in
  dedicated sections — no content lost). `dictionary-import.md`'s
  "Data repair (words:normalize)" section duplicated the import playbook's
  runbook including both dated run reports; the domain now keeps the
  contract summary + links, the playbook stays the single runbook.
  `sentence-alignment.md`'s 933-line flat `# Stages` list converted to
  `##`-per-stage and `###`-per-amendment subheadings (16 dated Align
  amendments were masquerading as list items). `generated.at` bumped on all
  four concepts.
- **Minor.** `index.md` + `architecture/index.md` drop the stale "Hybrid … +
  Livewire" phrasing (Livewire is dependency-only since the Livewire
  components were removed); `localization.md`'s empty `verified: {}` removed.

## 2026-10-24 (popup bug pass: per-part source chips, source-data migration run, silent Anki probes)

User follow-up on the morning's popup-refinements branch — three bugs:

- **Source chips, one bordered label per part.** The user asked for the
  resource titles as LABELS, not running text: the section-label
  provenance chip dropped the " · " join — `sourceBadge` is now an array
  of parts rendered as one small hairline-bordered chip each ("DECIMATED
  — verb [wiktionary] [ai]"; same dedup, same verbatim importer names,
  same hide-when-unknown). sourceBadge suite rewritten for the
  array derivation and the chip styling.
- **'kaikki' → 'wiktionary' actually applied to the dev DB.** The
  morning's data migration `2026_10_24_000001` was committed but pending
  — `php artisan migrate` ran it (1,412,073 rows flipped in 20s); the
  popup had kept reading the old stored value. No code change.
- **Probing goes silent until the reader engages.** With Anki off the
  user still got one console error per cold session (the browser logs
  every refused fetch — the 30s memo made it rare, not gone).
  `lib/ankiConnect.js`'s `probeReachable` now keeps a page-session
  SUCCESS ledger: the on-open probe runs gated (`{onlyIfKnown: true}`)
  and a cold session's opens answer null from the ledger WITHOUT a
  request — zero refused fetches. The way back in is the reader's own
  engagement: hovering the icon (`onAnkiProbe`) or clicking it
  (`ankiIconClick` — probe, then send) probes UNGATED, lighting a
  running Anki amber in place and opening the ledger gate; a failed
  engaged probe or a network-kind send failure closes it again
  (`unmarkAnkiReachable`). The icon's grey is COLOR only now — the
  disable-on-unreachable rule is reverted (a disabled grey icon could
  never re-discover Anki); disabled keeps only no-handler/sending.
  Fourth tooltip state `word.anki_unknown` (new ui-string, seeded) names
  the endpoint and offers the click-as-check; known-unreachable keeps
  the `word.anki_unreachable` guidance. ankiConnect behavioral suite
  gains the ledger/gate/in-flight-share pins; ankiReachable suite
  rewritten; toolbar disable pin updated.

## 2026-10-24 (popup refinements: section-label source chip, kaikki→wiktionary, Anki grey-when-unreachable + quiet probes, native-side explain)

User-reported popup UX pass — six changes, one branch:

- **The source badge moved off the header.** The provenance badge no
  longer sits beside the clicked word (the header row is the headword
  alone); the same derivation (`source` + `ai_asked`, " · "-joined,
  "ai · ai" deduped) now renders as a muted chip at the very END of every
  section label — "DECIMATED — verb wiktionary · ai" — the form's data
  origin where it reads as data, not as a caption on the word. Class
  hook renamed `word-popup-source` → `word-popup-section-source`.
- **'kaikki' → 'wiktionary' (data rename).** The stored value the popup
  and the Filament Words table render verbatim now names the content
  source, not the dump service: `Word::SOURCE_WIKTIONARY = 'wiktionary'`
  (constant renamed with the value — every writer reads the constant),
  data migration `2026_10_24_000001` flips the existing rows, ADR 0084
  amended. Pipeline vocabulary (dump paths, `fromKaikkiTags`, CLI
  descriptions) deliberately stays "kaikki".
- **Anki icon grey-when-unreachable, properly.** The on-open duplicate
  check only runs once the version probe answered reachable (with Anki
  off, a popup open fires no `findNotes` at all); the icon is DISABLED
  (not merely dimmed) while unreachable/pending/no-settings, tooltip
  reading `word.anki_unreachable`; the probe goes through
  `probeReachable` — a 30s per-endpoint failure memo in
  `lib/ankiConnect.js`, so a session with Anki down costs at most one
  failed request per 30s instead of per popup open; and the client sends
  no `Content-Type` header — a string body is text/plain, a CORS-simple
  request, so every call skips the OPTIONS preflight (the user's 4
  console errors per open = 2 fetches × preflight+POST; now ≤1 per 30s).
  `ankiConnectVersion` stays unmemoized for the profile tab's Test
  button.
- **Explain toggle + robot on native-language popups.** The RU/EN popup
  difference was the server gate, not two components:
  `wordMapsFor`'s `explainable` now answers per-side existence (the
  not-native rule governs `highlightable` only), so both of a bilingual
  text's popups carry the Explanation toggle + robot. ADR 0060 amended.

Docs: ADR 0084 + ADR 0060 amendments; `interactive-words.md` (chip
passage, class inventory, probe/disable/30s-memo, explain eligibility),
`reader.md`, `dictionary.md` + `dictionary-import.md` (renamed closed
set), `anki-export.md`, both touched playbooks — `generated.at` bumped
on all seven. Tests: vitest source pins rewritten (sourceBadge →
section-label chip, ankiReachable → probeReachable + disabled button +
gated detection, toolbar disabled expression) + behavioral
`probeReachable` memo suite; Pest `WordSourceProvenanceTest` (renamed
value + closed set), `ReaderPageTest`/`SimulatorTextEndpointTest`
(explainable both-sides pins).

## 2026-10-23 (popup-usage-labels follow-up 2: the marked section dims whole — hover restores it — and the mark now demotes)

User follow-up on the word-level mark pass: the 50% dim landed on the
"WORD — pos" header name only, but the whole obsolete pos/section should
dim; it should regain opacity on hover; and a mark-demoted entry should
sort last (a low completeness score), which the previous pass explicitly
did not do — `isDemoted` kept judging only the definitions, so a marked
entry with unlabelled senses still ranked live.

Landed, three moves:

- **Display** — `WordPopup.jsx`: the dim branch moved from the header
  `<h3>` to the `<section>` element itself
  (`word-popup-section-dimmed opacity-50 transition-opacity
  hover:opacity-100` from `dimsEntry(entry)`) so every block inside dims,
  the header keeps its base 60% (no more opacity swap), and hover
  restores full opacity. A dimming *sense* inside an already-dimmed
  section skips its own dim (`!dimsEntry(entry) && dimsDefinition(...)`):
  stacked it would read 0.25 statically and hover would restore only the
  line, not the section. Sense-level dim gains the same hover restore for
  live sections. `lib/usageLabelDisplay.js` + the Popups sample fixture:
  comment-only catch-up.
- **Ordering** — `WordCompleteness::isDemoted` ORs the word-level
  `UsageLabels::demoting($word->labels)` test ahead of the
  every-definitions test: the mark demotes directly, definitions or not,
  and `definitionPoints` follows through the shared test, so a marked
  entry sinks below every live one AND scores 0 definition points. The
  recalc SQL mirrors the branch (`jsonb_exists_any(w.labels, …)`) so the
  sweep stays agreement-bound; the mark rides `words.updated_at`, no new
  staleness machinery. `DEMOTING` itself unchanged — rare/dialectal still
  dim on display and still rank live.
- **Tests + docs** — vitest pins rewritten (section-element extraction:
  dim hook + hover on the section, header pinned to always-60; the
  no-stacking guard; sense hover), Pest gains the mark-demotes cases
  (isDemoted with/without definitions, rare-marked stays live with full
  points, marked scores 0, the sweep mirror, the endpoint ordering test
  where a rich marked entry sinks below a sparser live one). ADR 0083
  gained a second 2026-10-23 amendment (superseding the "demotion is
  untouched" stance of the first follow-up); interactive-words +
  database/dictionary re-stamped.

## 2026-10-23 (popup-usage-labels follow-up: the section header dims from the word's own mark — words.labels, not a senses aggregate)

User report: in the decimated popup the "DECIMATE — noun" header rendered
undimmed and unlabelled although every sense under it carries obsolete —
the header treatment should apply to obsolete parts of speech, sourced the
way Wiktionary itself marks them. Verified in the dump first: for
decimate's noun kaikki leaves the entry-level `tags` null — the mark the
page prints under `===Noun===` is the headword-level `{{tlb|en|obsolete}}`
template, preserved in `head_templates` while wiktextract propagates it
onto every sense (a 200k-line sample: `tlb` is the only headword-label
template of consequence there, plus 11 `term-label` aliases; multi-label
args like `("2": "obsolete", "3": "rare")` and grammar noise
("transitive, chiefly archaic") both occur). So the mark is imported from
the source's own headword label — deliberately NOT derived from the
definitions, per the user's correction of the originally planned
client-side aggregation: an entry whose every sense happens to be labelled
keeps an unmarked header.

Landed: `words.labels` jsonb nullable (migration `2026_10_23_000001`,
Word fillable + array cast); `WiktionaryParser::parseLine` unions the
`tlb`/`term-label` head-template numbered args (arg 1 = language) and the
entry-level `tags` through the same `UsageLabels::fromKaikkiTags` filter
onto the record's `labels`, `mergeRecord` unions first-seen-first, and the
word upsert stores it with `labels` in its UPDATE list — the re-import is
this mark's backfill, a source that stops marking unmarks the row;
`WordPopupPayload::entry` ships it as `entries[].labels` (null → `[]`);
the popup header dims to 50% (`word-popup-section-dimmed`, replacing its
base 60% outright — two opacity utilities would race in the stylesheet
order) and prefixes the mark's chips after the label text
(`word-popup-section-label-chip`, same tier styling as a sense's), driven
by `dimsEntry`/`entryUsageChips` in `lib/usageLabelDisplay.js`; the
Popups-tab sample fixture gained a marked "MELT — noun" section.
Completeness/demotion is untouched — `WordCompleteness::isDemoted` keeps
judging the definitions (words.labels only ever marks the display).
Tests: 4 unit + 1 feature parser cases (tlb parse, alias + entry-tags
union, closed-set noise drop, storage + re-import unmark), the endpoint
`entries.N.labels` shape, 7 lib behavioral cases + 5 header source pins;
vitest 304 green, targeted Pest 96 green, pint clean. Wiki:
interactive-words (payload shape + header treatment + hooks),
dictionary-import (the word-level mark paragraph), database/dictionary
(words.labels row + migration source), playbook + profile + anki-export
stamps bumped; wiki:validate conformant.

Data run (the backfill, no wipe): both editions re-imported
`--skip-extract --no-link` over the live rows — en 1,492,835 lines /
940,637 words upserted (~16 min), ru 489,522 / 470,816 (~10 min),
`definitions_labels_updated` 0 in both (converged, same as the ticket-04
runs). Coverage: en 875 words carry the word-level mark (headword-level
`{{tlb}}` marks are rare on Wiktionary — most labelling sits on senses),
ru 0 (its edition marks per sense). Spot check: `decimate` noun row
2973498 stores `["obsolete"]` beside its three tithing senses while the
verb row stays null — the popup's noun header now dims with the chip, the
verb header untouched.

## 2026-10-23 (popup fix: form-only surfaces resolve through the forms table — "smiled" no longer answers the missing card)

User report: clicking 'smiled' in the reader answered "This word isn't in
the dictionary yet" although the surface is visible in the database. Three
stacked causes: (1) the import's relay demotion (ADR 0079) stores a pure
form-of entry like 'smiled' only as a `forms` row on the base 'smile' — no
`words` row (verified: 0 rows, `forms` → word 2957551); (2)
`WordAiEnrichmentService::resolveSurface` — the engine behind the popup's
on-open `POST /words/lookup` re-resolution — queried `words.l_word` only,
never `forms`, so a form-only surface answered `word_id: null`; (3) the
fast path that WOULD have resolved it (the per-entity `entity_words` map,
whose linker has an exact-then-forms resolution) had been TRUNCATEd by the
same morning's data run and could not self-rebuild — `crossword:refresh`
only picks entities with a stale index or unlinked rows, and the surviving
`entities.words_indexed_at` plus the empty `entity_words` satisfied neither.

Fix: `resolveSurface` gained a forms pass — exact `(language_id, l_word)`
first, then `forms.l_word → base word` (EntityWordLinker::link's order, an
exact headword always beating a forms row; the prefer-classed rule
unchanged). Intentional documented consequence: `resolveOrAdopt` (the
adopt-on-ask) now adopts a form-only surface onto its BASE lemma instead of
minting a placeholder twin of a demoted relay. Three new endpoint tests
pin the fallback, exact-precedence, and prefer-classed-over-unknown in the
forms pass (WordLookupEndpointTest, 26 passed with the adopt/provenance
suites; TIA 1480 green; pint clean).

Dev-DB recovery (the second half of the report — every token opened in
missing mode on the wiped map): `entities.words_indexed_at` nulled on all
6 entities, `crossword:refresh --limit=10` dispatched, the default queue
drained with a one-shot `queue:work --stop-when-empty` run (the 4
pending `low`-lane `EnrichEntitySentences` jobs untouched). Result:
`entity_words` rebuilt to 21,585 rows, all linked (0 unlinked); 'smiled'
maps to word 2957551 in the entity-19 map; `resolveSurface('smiled', en)`
returns 'smile'; `crossword:refresh` reports nothing left to do.

## 2026-10-23 (popup-usage-labels ticket 04: the data run — fast wipe + full kaikki re-import populates real label coverage)

The backfill behind ticket 02's update-on-reimport semantics, executed per
the user's 2026-10-23 directive (test machine, collateral loss acceptable,
speed preferred): a single-statement non-transactional `TRUNCATE` of `words`
plus all twelve tables referencing it (`definitions`, `forms`,
`transcriptions`, `etymologies`, `examples`, `pronunciations`,
`word_translations`, `tagged_words`, `user_word_event` (familiarity events,
226 rows), `word_translation_fetches`, `word_ai_fetches`, `entity_words`,
`user_word`) — instant, where the old chunked `--fresh` path crawled —
keeping the `tags`, `word_classes` and `transcription_types` taxonomy. The
referring-tables list came from `pg_constraint` (the DB is the authority;
the migrations-grep guess had `word_familiarity` wrong — the table is
`user_word_event`). Pre-wipe state for the record: 1,494,360 words
(10 `source='kaikki'` — the sample slice; the rest pre-dated the source
column), 2,055,696 definitions (10 labelled), 184,315 links, 0 AI rows
after the wipe.

Then both editions re-imported via the documented `wiktionary:import-raw`
with `--skip-extract` (extracts on disk; one wrinkle — the en-edition dump
had been renamed `en-raw-wiktextract-data.jsonl.gz` while the extracts keep
the old base, fixed with a symlink so `extractPathFor` pairing resolves).
Run 1 (en edition, `--langs=en,ru`): 1,492,835 + 442,594 lines → 1,003,469
words, 92,954 foreign-prose definitions skipped by the majority-script
guard, link pass 77,448 en→ru. Run 2 (ru edition, `--langs=ru
--target-langs=en` — the flag is required for single-language runs):
489,522 lines → 470,816 words, 6,935 relays demoted, 40 foreign-skipped;
its link pass linked 77,188 ru→en plus 20,451 more en→ru (run 2's new ru
words resolved staged candidates run 1 had left). Directed linkings
(175,087) collapse to **147,708 canonical pairs** (`word_translations`
unique per pair).

Final dev-DB state: **en=932,146 / ru=481,440 words; 95,494 labelled
definitions** (obsolete 39,993, archaic 19,207, rare 18,080, dated 11,387,
colloquial 7,309, dialectal 5,031); `definitions_labels_updated=0` in both
runs is correct — empty tables mean every definition was a fresh insert
carrying labels natively (the update path was proven earlier on the sample
slice: 10 updates, repeat run 0). Spot check: `бытность`'s "жизнь,
существование" definition carries `["obsolete"]` — the popup's payload
ships it (`{text, labels}`) and the chip rendering is suite-pinned; the
Filament Labels column reads the same rows. No orphaned parallel-worker
test databases.

## 2026-10-23 (popup-usage-labels: labels become visible — popup chips in two display tiers, Anki plain-text prefix, update-on-reimport backfill)

The usage labels stored since 2026-10-20 (popup-ai-enrich ticket 09)
become visible, and the no-backfill stance is superseded (user decision
2026-10-23). Tickets 01–03 are the code (commits `4a57d48`, `fe4a28b`,
`9ff4dc0`); this entry is the docs pass (ticket 05) — the full-kaikki
re-import itself is ticket 04 and logs its own line.

- **Popup payload** (`domains/interactive-words.md`):
  `WordPopupPayload::entry` ships per-entry definitions as
  `{text, labels}` objects (a null column lands as the empty list, so
  the client never special-cases the unlabelled shape); both popup
  endpoints build from the one assembler, so the shapes cannot drift.
- **Chips in two display tiers** (`domains/interactive-words.md`): each
  labelled sense renders one chip per label through the six
  `word.label_*` ui-strings (en the plain words; ru/be the dictionary
  abbreviations устар., арх., не совр., разг., редк., диал.), tiered by
  the new `resources/js/lib/usageLabelDisplay.js` — any dimming-tier
  label (obsolete/archaic/rare/dialectal) renders vermilion chips plus
  the sense line at 50% opacity, the register tier (dated/colloquial) a
  muted `opacity-60` chip that never dims. The display split
  deliberately differs from the completeness `DEMOTING` subset
  (obsolete/archaic) — scoring and the recalc SQL are untouched. New
  class hooks `word-popup-definition-label` / `-definition-dimmed`
  registered in the concept's class-hook registry.
- **Anki plain-text prefix** (`domains/anki-export.md`,
  `domains/profile.md`): definition lines in both note fields carry
  `(устар., редк.) текст` — composed once in the shared definitions
  section so the fields cannot drift, both display tiers identical,
  unlabelled lines byte-identical; the wording object gained an eighth
  `label` key resolved through the same `word.label_*` strings the
  popup's chips use.
- **Update-on-reimport** (`domains/dictionary-import.md`,
  `database/dictionary.md`, `playbooks/import-dictionary-data.md`): the
  definitions flush refreshes labels on case-insensitively same-text
  rows to the incoming value (null included; stored TEXT never
  rewritten; `source='ai'` rows excluded; etymologies stay
  insert-new-only), every changed row counted in the new "Definitions
  labels updated" import-summary row. Supersedes ADR 0083 decision 2's
  no-backfill — the backfill is the re-import itself, never a
  row-rewrite migration (ADR 0083 amended in place; GoldenDict markers
  stay unparsed — follow-up candidate).
- **Docs pass**: GLOSSARY.md's "Usage labels" entry drops its
  "existing rows keep null" clause and records the popup + Anki
  surfacing; the six touched concepts re-stamped; `wiki:sync` verified
  a no-op (no route/model/command changes in this spec).

## 2026-10-23 (retro-fixes-2 review fix pass — the gate cannot bite its own tail anymore)

Fix pass on the two-axis review of tickets 01–04: one blocker plus five
nits, all in the ticket-01 mechanism (the unacknowledged-warning gate)
except the last.

- **No-op prune no longer rewrites the baseline (blocker)**: `--prune`
  used to rewrite `wiki/validate-baseline.json` unconditionally, so every
  no-op prune mtime-bumped the file — which the running-tests playbook
  lists as a source — minting a fresh unacknowledged drift warning and
  reding the gate + WikiTest until the playbook's `generated.at` was
  bumped again. Prune now compares the encoded keep-set against the
  current file contents and writes only on a real difference
  ("nothing to drop … file untouched"); a fixture test pins content and
  mtime untouched, verified live on the real bundle.
- **Prune refuses under the CI mtime skip**: with
  `WIKI_SKIP_MTIME_CHECKS=1` every mtime baseline entry reads as stale,
  so a CI-side prune would have rewritten the baseline to empty. Prune
  now refuses the same way it refuses a malformed file.
- **Baseline entries require owner+date**: validation required only a
  non-empty `warning`; an acknowledgement without its owner+date defeats
  the mechanism's point. Entries missing non-empty `warning`, `owner` or
  `date` now land in the baseline-errors path (loud, nothing
  acknowledged).
- **Validator cleanup**: the never-used `baselinePathOverride`
  constructor parameter is gone; `staleBaselineEntries()` and
  `prunedBaselineEntries()` no longer re-filter the full entry list on
  every call — `loadBaseline()` partitions entries once into
  matching/stale buckets and derives both views plus the
  acknowledged/unacknowledged warning sets from the partition.
- **coding-conventions.md refreshed** (the gate's own preferred
  remediation for its AGENTS.md drift): the Testing section now mirrors
  the per-spec test rhythm (per-ticket `test:tia` + targeted files, full
  suite once per spec before review dispatch, fix pass = `test:tia` +
  touched files) and the wiki gate (zero unacknowledged warnings,
  owner+dated acknowledgements, `--prune`); `generated.at` bumped and
  the cleared drift entry pruned (baseline 29 → 28). running-tests.md's
  gate bullet updated for the new prune semantics and its `generated.at`
  bumped with it (the baseline file is one of its sources).

Code: `laravel/app/Classes/WikiValidator.php`,
`laravel/app/Console/Commands/WikiValidate.php`,
`laravel/tests/Feature/WikiTest.php` (3 new fixture tests — 15 passed /
58 assertions in the file; `test:tia` 1471 passed, pint clean). No
routes/models/commands changed beyond docblocks, so wiki:sync needed no
content regeneration; `wiki:validate` conformant (28 acknowledged, zero
unacknowledged) and a repeat `--prune` leaves the baseline byte-identical.

## 2026-10-23 (agent workflow steering — cheap reads, no worktrees, plan-mode docs, full suite once per spec)

Ticket 04 of the retro-fixes-2 spec. Four rules the retro found being
rediscovered or violated per run now live in the homes agents read at
the moment they would violate them.

- **Economy (no whole-file re-reads)**: implementers read the pointed
  ranges with offset/limit — never a >500-line file whole when the notes
  name the range; researchers quote the exact current hunk (≈5–10 lines,
  verbatim, at its file:line) for every edit site — pointers locate,
  hunks save a whole-file read.
- **No worktrees**: the container bind-mounts `./laravel` of the main
  checkout only and test runs share `ext_app_test`, so a worktree's
  `laravel/` cannot run container tests — ticket work happens
  sequentially in the main checkout; implement-spec's per-ticket
  worktree default does not apply in this repo.
- **Plan-mode doc writes**: under plan mode docs are read-only —
  grill-with-docs → domain-modeling's write-as-you-go ADR/GLOSSARY
  updates are collected as plan items and written immediately after
  plan approval.
- **Test rhythm**: per-ticket verification is `composer run test:tia`
  (plus targeted `php artisan test` on the files being worked), never
  the full suite per ticket; the full `composer run test` runs once per
  spec, after all tickets resolve and before review dispatch; a later
  fix pass verifies with `test:tia` plus the touched test files; CI on
  push remains the final full gate.

Homes: AGENTS.md (testing paragraph, Database warning block, wiki
maintenance rules) plus the `.zcode/agents/implementer.md` and
`.zcode/agents/researcher.md` definitions (gitignored, edited on disk,
not part of the commit). Skills under `.agents/skills/` stay pristine by
decision — AGENTS.md is the override layer. No routes/models/commands
changed, so wiki:sync was not needed; the three concepts listing
AGENTS.md as a frontmatter source already carry acknowledged drift
entries in `wiki/validate-baseline.json` (stable message identity), so
the baseline is untouched.

## 2026-10-23 (one glossary, two declared homes — GLOSSARY.md and CONTEXT.md point at each other)

Ticket 03 of the retro-fixes-2 spec. One session placed glossary terms in
three homes (CONTEXT.md, GLOSSARY.md, and term entries inside wiki domain
concepts) with zero cross-references between the root files — each
implementer had to guess where a new term goes.

- **The declared split**: CONTEXT.md is the authoritative per-context
  domain glossary (`docs/agents/domain.md` already declared it so); its new
  preamble says so and points flow terms to GLOSSARY.md. GLOSSARY.md owns
  terms of art for user-facing flows (today: Anki export, Interactive
  words); its header note states the split and points domain terms back to
  CONTEXT.md.
- **Echo, don't re-define**: wiki domain concepts may echo a term but link
  to its owning root file instead of re-defining it — the rule lives in
  GLOSSARY.md's header note.
- Declaration only — no entries moved. The three placements that motivated
  the ticket stay where they are (CONTEXT.md's Staged translation,
  GLOSSARY.md's AI word enrichment, the dictionary-import concept's Word
  source term); the declaration governs future placements.

## 2026-10-23 (source pins evaluate derived logic — the lift-and-evaluate convention)

Ticket 02 of the retro-fixes-2 spec. The "ai · ai" badge bug passed its
own vitest source pin: the pin string-matched the buggy expression
verbatim, so red-green was impossible for that logic error and only the
review pass caught it. The cure already shipped with the fix
(`cab5280`'s source-badge suite); this ticket states the rule in every
home an agent or reviewer reads.

- **The split**: a pin over DERIVED logic lifts the real expression out
  of the component source and evaluates it against payload states;
  verbatim-contains pins are allowed only for wiring/structure
  (pass-throughs, class hooks, presence/absence).
- **Playbook** (`playbooks/running-tests.md`): the blessed-pin bullet no
  longer claims "exactly one" Components-side pin — the split's two
  exemplars are blessed by name (`WordPopup.ankiSend.test.js`,
  contains-only; `WordPopup.sourceBadge.test.js`, lift-and-evaluate) with
  the incident as rationale; `generated.at` bumped.
- **ADR 0066**: amendment section splits the "pinned verbatim"
  consequence along the same line, citing the incident and both
  exemplars; `lib/` imports stay behavioral.
- **Reviewer agent**: the test-hygiene clause now flags verbatim-contains
  pins over derived logic and requires the lift-and-evaluate form.
- `architecture/frontend.md`'s vitest section drops its own "pure `lib/`
  modules only" staleness and states the split; `generated.at` bumped
  (the ADR is one of its listed sources — the new gate flagged the drift).
- No test code changed — the exemplar suite already ships.

## 2026-10-23 (wiki:validate fails on unacknowledged warnings — owner+dated baseline)

Ticket 01 of the retro-fixes-2 spec. A mechanism, not another burn-down:
the 2026-10-14 retro burned the wiki warnings 88 → 0 and they regrew to
32 within nine days of ordinary feature work.

- **The gate**: `wiki:validate` now exits 1 when any warning is not
  acknowledged in the checked-in baseline `wiki/validate-baseline.json` —
  JSON `entries` of `{warning, owner, date, note}` matched by exact
  message equality. A missing baseline behaves as empty; a malformed
  baseline is reported as an error, never silently ignored. Acknowledged
  warnings summarize in one line naming the baseline; `--prune` drops
  entries that no longer match any current warning so the file cannot
  rot. Remediation printed on failure: bump the listing concept's
  `generated.at`, or acknowledge in the baseline with owner+date.
- **Baseline**: shipped populated with the 32 source-mtime-drift warnings
  live on 2026-10-23 (owner `human:alex`), generated from the live
  validator output so messages match byte-for-byte. CI stays trivially
  green — `WIKI_SKIP_MTIME_CHECKS=1` mutes the drift check there — and
  the gate still fails on any non-mtime warning (unknown status, stale
  content, missing source, reserved-file frontmatter).
- **Wiring**: `App\Classes\WikiValidator` gained `acknowledgedWarnings()`,
  `unacknowledgedWarnings()`, `staleBaselineEntries()`,
  `baselineEntries()`, `baselineErrors()`, `baselinePath()` and
  `encodeBaseline()`; `warnings()`/`errors()` behavior is unchanged.
  `WikiValidate` prints the gate and carries `--prune`. `WikiTest` now
  enforces zero unacknowledged warnings on the real bundle and covers the
  gate mechanics (fresh drift, acknowledgement, stale entries, malformed
  baseline, prune, mtime-skip behavior) against a temp-dir fixture bundle.

## 2026-10-20 (popup-provenance review fix: the source badge dedups the adopt-on-ask word's "ai")

Review-pass fix on ticket 04's badge (one commit, three accepted findings).

- **The dedup**: an adopt-on-ask word carries `words.source = 'ai'`
  (ADR 0084) AND `ai_asked` true from the same ask, so the badge
  derivation rendered "ai · ai". The parts now dedupe (Set) and plain
  "ai" renders; the vitest source-badge suite replaced its
  verbatim-expression pin (which pinned the buggy expression) with the
  four real payload states, evaluated against the derivation lifted from
  the component source; the ruling is one line on ADR 0082's
  word-level-badge amendment. `domains/interactive-words.md` badge
  paragraph, `database/dictionary.md`'s `words.source` cell + GLOSSARY
  "AI word enrichment" updated, `generated.at` bumped on both concepts.
- **Naming**: the fill-if-null mechanism is "backdoor" everywhere
  (ADR 0084 + the migration's term) — `WordAiEnrichmentService` and one
  `WordSourceProvenanceTest` comment said "backstop"; mechanical swap.
- **Tests**: `WordSourceProvenanceTest` uses the `Word::SOURCE_*`
  constants instead of scattered literals; only the closed-set pin keeps
  its literals (it guards the vocabulary itself).

## 2026-10-20 (popup-provenance: the Save-to-Anki icon reflects Anki reachability — on-open version probe, amber)

Ticket 05 of the popup-provenance spec (amber token landed with ticket 01,
the color-token toolbar helper too). The Anki icon carried no reachability
information: a user with Anki off clicked send to find out, and a user who
started Anki mid-session had no hint the popup could now reach it.

- **Probe** (`Components/WordPopup.jsx`, outer `WordPopup`): on every open
  with settings present and the payload loaded, a cancelled-guarded effect
  beside the on-open duplicate check calls the profile tab's existing cheap
  `ankiConnectVersion` (`lib/ankiConnect.js` — no new client code), keyed
  `[ankiSettings, data]` exactly like the detection effect; a resolve marks
  Anki reachable, a rejection marks unreachable, and the effect clears the
  flag first so the icon sits grey while the round-trip is in flight and
  each open answers for itself (starting Anki mid-session is picked up on
  the next popup). No settings → no probe. Reachability stays a
  browser-side fact — no backend flag.
- **Icon**: the Save-to-Anki button's color token is now
  `toolbarButtonClass(ankiReachable ? 'amber' : 'neutral')` — the amber
  token (ticket 01) while AnkiConnect answers, the neutral grey while the
  probe is pending, unreachable, or without settings. The send refreshes
  the answer without a re-probe: any completed send (added / updated /
  foreign-model duplicate — Anki answered every action) flips amber behind
  the send's `dataRef` guard; a network-kind failure flips grey; an
  anki-kind failure and a note-type mismatch keep amber. The probe lives in
  the outer component only — `PopupContent` reads a false-defaulting
  `ankiReachable` prop, so the profile's Popups-tab preview stays grey and
  inert (no network), same as today.
- **Tests**: new vitest source-pin suite
  (`WordPopup.ankiReachable.test.js`) pins the probe wiring (existing-lib
  reuse, the settings+data gate, the shared key and cancelled guard,
  resolve/reject mapping, the false default) and every icon state
  (pending/unreachable/no-settings → neutral, reachable → amber,
  completed-send → amber without a re-probe, network-kind failure → grey,
  anki-kind/mismatch → amber) plus the preview's inertness; the toolbar
  suite's color-token pin updated to the conditional form. Full vitest
  green (24 files / 270 tests); no PHP touched.
- **Docs**: `domains/interactive-words.md` (the Anki-send section gains the
  "Reachable amber" bullet, the description + `-anki-send` class-hook line
  mention the conditional token, the preview bullet states the
  never-probes guarantee, `generated.at` bumped).

## 2026-10-20 (popup-provenance: the popup header shows a word's source + ai badge)

Ticket 04 of the popup-provenance spec (words.source landed with ticket 03).
Import provenance and ask-AI enrichment were DB-only: the popup rendered no
marker anywhere, and a user could not tell a kaikki import from an
AI-enriched adopt or a pre-provenance row.

- **Payload** (`WordPopupPayload::fromFamily`, the ONE assembler both popup
  endpoints share): the top level gained `source` — the bound headword's
  `words.source` verbatim (null on pre-provenance rows) — beside the
  existing `ai_asked`; both endpoints stay shape-identical by construction.
- **Popup** (`Components/WordPopup.jsx`): a small muted plain-text badge in
  the header's baseline row, next to the headword (`word-popup-source`
  hook, opacity-muted so one style carries light+dark): the importer name
  verbatim plus " · ai" when `ai_asked`, "ai" alone when the word was
  enriched but no importer ever stamped it, nothing at all when neither is
  known. The missing-word card's header carries no badge (no word behind
  it).
- **ADR 0082 amendment**: "the popup renders no visual marker" is
  superseded at word level only — per-row content stays unmarked, per-row
  `source` columns unchanged.
- **Tests**: Pest pins `source` set and null on the show endpoint and null
  on both sides of the ask response (the existing array-keys shape pin
  already forces the ask and show payloads identical); a new vitest
  source-pin suite (`WordPopup.sourceBadge.test.js`) pins the badge's
  presence next to the title, its join derivation, the
  hide-when-neither gate, the muted/small styling hooks, and the missing
  card's badge-less header.
- **Docs**: `domains/interactive-words.md` (header-badge paragraph, the
  `word-popup-source` class hook, the route-table payload shape,
  `generated.at` bumped); GLOSSARY.md's "AI word enrichment" entry amended
  — the badge supersedes the old no-marker wording at word level, and the
  stale succeeded-lock clause was corrected to the re-ask reality in
  passing.

## 2026-10-20 (popup-provenance: the popup surfaces a word's staged translations as a language-less amber raw line)

Ticket 02 of the popup-provenance spec (amber token came with ticket 01,
words.source with ticket 03). The staged `words.translations` JSON — the
link pass's working data — was invisible to end users: a word whose
candidates are all still unlinked read as "no translations yet" even while
carrying a dozen raw glosses.

- **Payload** (`WordPopupPayload::entry`, the ONE assembler both popup
  endpoints share): every entry now carries `staged_translations` beside
  `translations` — the word's staged strings verbatim, language-less, in
  stored order, deduped case-insensitively against the linked line's
  display words (a staged restatement of a linked word drops) and within
  itself (a case twin keeps its first occurrence), capped at the linked
  line's 100 (`TRANSLATION_LIMIT`), `[]` when nothing is staged.
- **Popup** (`Components/WordPopup.jsx`): a second collapsible line
  directly under the linked green translations line — tiny muted
  `word.raw_translations` label ("raw:", en/ru/be "raw:/сырые:/сырыя:"),
  the strings amber (`--color-amber`/`--color-amber-night`), its own cap
  of 8 and its own "+N more…" expander (`word-popup-raw-translations`
  /`-raw-translations-more` class hooks). Hidden entirely when the entry
  stages nothing; the calm placeholder shows only when linked AND staged
  are both empty. `CollapsibleLine`'s empty-items contract tightened:
  without an `empty` fallback an empty line renders nothing (previously an
  empty `<p>` shell). **Popup-only by decision**: `lib/ankiTemplate.js`
  untouched — the Anki note fields and card Body keep rendering linked
  translations only, and a vitest pin guards the exclusion.
- **Tests**: Pest pins the payload on both endpoints (dedupe vs linked
  display words, always-carried empty shape, the 100 cap, and the ask-AI
  response carrying the staged strings identically to the refetched show
  payload), the seeder test pins the new ui-string in all three interface
  languages; a new vitest source-pin suite
  (`WordPopup.translations.test.js`) pins the raw line's rendering,
  absence, placeholder logic, amber token, and the Anki exclusion.
- **ADR 0085** records the decision set (surfaced language-less as-is,
  dedupe + cap rules, popup-only); `domains/interactive-words.md`
  (payload shape, popover paragraph, class hooks, sources),
  `database/dictionary.md` (the `translations` column, sources) and
  CONTEXT.md's **Staged translation** glossary term (no longer "never
  shown to end users") updated. Payload/UI changes add no route, model or
  artisan command, so `wiki:sync` had nothing to regenerate.

## 2026-10-20 (popup-provenance: words.source import provenance, stamped at insert, admin column + filter)

Ticket 03 of the popup-provenance spec (the popup badge that reads this
column lands with ticket 04). A word row could be minted by five writers
with nothing on the row saying which — admin triage and the coming badge
had no answer.

- **`words.source`** (migration `2026_10_20_000003`, nullable string(16)
  over the closed set `kaikki | golddict | cmudict | entity | ai`,
  `Word::SOURCES`): stamped in the INSERT payload by every creating
  writer — the kaikki and GoldenDict upsert row arrays (their update
  lists unchanged, so `source` never rides an update), the cmudict upsert
  (conflict update pinned to `['word']` explicitly — it was previously
  derived from the row keys and would have absorbed the stamp), entity
  adoption's and the ask-AI mint's `firstOrCreate` values. First importer
  wins: when imports merge into the same `(l_word, language,
  word_class_id)` row (ADR 0081), the creator never flips.
- **The fill-if-null backdoor** (no one-time backfill): each importer's
  per-batch write runs `Word::fillSourceIfNull` after its upsert — one
  UPDATE claiming only still-null rows of the batch's
  `(l_word, word_class_id)` pairs in the language, so re-running an
  import badges the pre-column rows it touches while a row another
  creator owns keeps its value. The single-row mints claim the row their
  `firstOrCreate` found (the documented race nets); a resolve hit on the
  adopt paths is a read, never a claim. Rows nothing touches again stay
  null — pre-column rows and Filament hand-creates included (the Word
  form gains NO source field: provenance is machine-written).
- **Filament Words admin**: a Source badge column (dash for null,
  searchable/sortable) and a Source select filter over `Word::SOURCES`.
- **Tests**: `tests/Feature/WordSourceProvenanceTest.php` — the
  fill-if-null helper contract (pair- and language-scoped, never
  overwriting), insert stamps + re-run claims for kaikki (via
  `WiktionaryParser`) and golddict (via `goldendict:import`), the cmudict
  stamp, the entity mint/claim/adopt-onto matrix, the adopt-on-ask mint
  stamp and the read-only resolve hit, and the admin column/filter.
  Existing importer/adoption/enrich suites untouched and green.
- **ADR 0084** records the decision set (closed set + insert stamp, first
  importer wins, the backdoor, admin reads only);
  `database/dictionary.md` (words row + sources),
  `domains/dictionary-import.md` (the **Word source** glossary term in
  Creator hygiene, sources, description) and
  `domains/interactive-words.md` (the adopt-on-ask route row) updated;
  `wiki:sync` re-stamped the migration references. Note: the dev DB
  (`ext_app`) gets the column on the next manual `php artisan migrate` —
  the test DB via the suite only.

## 2026-10-20 (popup-ai-enrich re-ask: the succeeded lock lifted, replace-on-reask, the re-ask icon)

User report: the Ask-AI icon answered "AI enrichment has already been
completed for this word." on click — the ADR 0082 forever lock turned the
icon into a dead end after the first ask, and the payload's `ai_asked`
greying could even go stale enough to surface the 409 verbatim. Superseded
by decision (user): a bad AI answer should be retryable on demand.

- **Ledger de-locked** (`ReadingAiController::ledgerBlock`): the
  succeeded-row 409 branch is gone — the endpoint's only remaining enrich
  409 is the fresh-pending "in progress" gate (the 15-minute stale-pending
  takeover and failed-stays-retryable rulings keep their original shape).
  A re-ask runs the full flow on the SAME `word_ai_fetches` row
  (attempts++, back to pending, finalize succeeded again); the cost guard
  is the route's 20/min throttle. The word-show/`GET /words/{word}`
  semantics change: `WordAiFetch::succeededFor` (the payload's
  `ai_asked`) now reads **`succeeded_at`** instead of the current status,
  so a FAILED re-ask keeps the first success's timestamp and the toolbar
  keeps the re-ask variant.
- **Replace-on-reask** (`WordAiEnrichmentService::applyParsed`): the
  merge transaction deletes the word's `source = 'ai'` rows
  (definitions/transcriptions/examples/etymologies) before the fresh
  reply merges — the old additive-merge argument ("a re-ask would only
  add differently-worded variants") is why replace wins; imported rows
  (null source) are never touched and AI-linked translations stay
  (no per-row AI attribution to select on).
- **The re-ask icon** (`WordPopup.jsx` + `icons.jsx`): the payload's
  `ai_asked` no longer disables the button — it flips the glyph to
  `sparklesRefresh` (the shared sparkles scaled inside the shared
  chasing-arrows ring; `Icon` now accepts multi-path descriptors with
  `transform`/`non-scaling-stroke` so the small glyph keeps stroke
  weight) with the "Ask AI again (already enriched)" tooltip
  (`word.ask_ai_again` replaces `word.ask_ai_already` in the ui-strings;
  dev DB reseeded). The missing card's icon is unchanged (no
  `ai_asked` state there).
- **ADR 0082** amended 2026-10-20 (the forever lock superseded — the
  recourse-is-admin-editing consequence withdrawn);
  `domains/interactive-words.md` ask-AI section, toolbar description,
  409-sync note and Routes table rewritten, `generated.at` bumped.
- Tests: the "rejects an already-asked word" endpoint test replaced by
  the re-ask pair (succeeded row + AI/imported definitions → replace
  pinned row-by-row; failed re-ask keeps `ai_asked` true through
  `GET /words/{id}`), the adopt suite's "second ask stays 409-locked"
  now pins the in-place re-enrich (still one word, attempts 2), vitest
  toolbar/lookup pins updated to the re-ask contract.

## 2026-10-20 (popup-ai-enrich review fix pass — 4 accepted findings on 07–10)

- **Lazy-vs-stored demotion alignment** (ADR 0083 decision 4 extended,
  `database/dictionary.md` Word completeness note,
  `domains/interactive-words.md` popup section): the demotion test and the
  definition points now judge the word's **stored** definition set on BOTH
  paths — `WordFamily::resolve`'s relay-gloss display filter swaps the
  loaded relation only AFTER `WordCompleteness::recalcFamily` and the
  live-before-demoted sort have run (it used to run first, so a
  partially-relay entry whose every surviving sense is obsolete/archaic
  demoted lazily while the `words:recalc-completeness` SQL — relay glosses
  are unlabelled, live rows — kept it up, and `words.completeness` flapped
  depending on which ran last). The lazy staleness window now sees the
  stored children too, matching the sweep's all-definitions threshold.
  Pinned by twin tests asserting the same score (3) for the same rows:
  `WordEndpointTest` (lazy recalc through the popup open) +
  `WordsRecalcCompletenessCommandTest` (SQL).
- **`WordPopup` fetch effect early-return** clears
  loading/error/data before returning for an id-less (missing) token — the
  missing-card branch's `data == null` conjunct no longer relies on the
  popup unmounting between clicks (vitest source pin added).
- **`AiWordEnrichEndpointTest`**: the labelled `{text, labels}` merge test
  gained the house-required `Http::fake()` its sibling guards already had.
- **`database/dictionary.md` frontmatter**: the `sources` list's
  mixed 3-space/2-space indent runs normalized to 2-space (strict
  symfony/yaml chokes on the mix).

## 2026-10-20 (popup-ai-enrich 10: completeness v2 — 0–9, live entries before demoted)

The stored completeness score re-weights so it encodes importance as well
as richness, and the popup now sorts live entries ahead of obsolete-only
ones (ADR 0083, with ticket 09's labels).

- **New matrix** (`database/dictionary.md` words row + Word completeness
  note): 0–9 — linked translations 3 (was 2); definitions 2 on a major
  POS (noun/verb/adjective/adj/adverb/adv, both adjective/adverb
  spellings) and 1 on any other POS; **0 when every definition carries a
  demoting label** (`UsageLabels::DEMOTING` — no definitions is live,
  never demoted); transcriptions/examples/etymologies/frequency 1 each.
  `WordCompleteness::MAX` = 9; `isDemoted` is the one demotion test,
  shared by the score and `WordFamily`'s sort so they cannot drift.
- **Popup ordering** (`domains/interactive-words.md`): the stable sort
  becomes [live entry before demoted entry, then completeness desc] over
  the assembled order — linked-first/class-priority/frequency remain the
  tiebreaks; a demoted entry sinks below every live one even at a higher
  score.
- **SQL mirror** (`words:recalc-completeness`): scanSql joins
  `word_classes` for the major-POS case and mirrors the all-demotion rule
  with `jsonb_exists_any(labels, array['obsolete','archaic'])` (the `?|`
  operator's function form — PDO's `?`-placeholder parser would eat a
  literal `?|`; NULL labels = live); both slug lists are read from
  `UsageLabels::DEMOTING` / `WordCompleteness::MAJOR_POS` at build time.
  Command description re-pinned; `words.completeness` column comment
  re-pinned by comment-only migration `2026_10_20_000002`.
- **Dev sweep** (full, no --stale-only — the weights changed for
  everyone): 2026-10-20, 1,494,359 words scanned, 1,225,100 rewritten in
  4m11s; a following `--stale-only` pass rewrote 0 (idempotency holds).
  Histogram after the sweep: 0 → 1,200; 1 → 108,576; 2 → 352,777;
  3 → 436,535; 4 → 385,085; 5 → 68,972; 6 → 19,639; 7 → 48,429;
  8 → 73,143; 9 → 3. Zero labelled definitions in dev so far (no
  backfill), so the 0 bucket is no-content words — demotion wakes as
  labelled data accumulates.

## 2026-10-20 (popup-ai-enrich 09: usage labels on definitions — obsolete/archaic/rare stored going forward, no backfill)

Definitions can now carry usage/register labels, so unimportant senses
can be demoted (ticket 10). User decision 2026-10-20: fix the code so
future imports and AI asks label correctly; the existing ~1.5M rows keep
null — no backfill.

- **New column** (`database/dictionary.md` definitions row): nullable
  `definitions.labels` jsonb (migration `2026_10_20_000001`, column
  comment documents the closed set + "null = unlabelled"), cast on the
  `Definition` model. jsonb so ticket 10's recalc SQL can mirror the
  demotion check server-side.
- **Single source of truth** `App\Support\UsageLabels`: the closed set
  (`obsolete`, `archaic`, `dated`, `colloquial`, `rare`, `dialectal`),
  the `[obsolete, archaic]` DEMOTING subset, `fromKaikkiTags` (intersect
  — input order kept, duplicates collapsed, grammar/gender noise and
  unknown slugs dropped) and `demoting()`.
- **Kaikki import** (`domains/dictionary-import.md`): `senses[].tags` —
  previously discarded — land as labels on imported definitions (both
  the `raw_glosses` and `glosses` paths); same-text senses dedupe on the
  text with the first occurrence's labels standing. GoldenDict stores
  none — its headwords' usage markers were flattened during HTML
  conversion, unrecoverable (documented, deliberately no code).
- **Ask-AI enrichment** (`domains/interactive-words.md`): the reply's
  definitions entries accept a plain string OR a `{text, labels}` object;
  `EnrichmentReply` parses the section to `['text' => …, 'labels' => […]]`
  pairs (a plain string is an unlabelled pair; unknown slugs dropped;
  dedupe/truncate keep the first labels; other sections stay plain
  strings and the popup payload keeps rendering strings). The
  `DefinitionLanguage` guard still reads the text alone — a dropped
  definition drops its labels with it — and the merge writes non-empty
  labels on `source='ai'` rows (empty stores as null).
- **Prompt**: `ENRICHMENT_FALLBACK` names the object form and the closed
  set ("label only when clearly so; otherwise a plain string"); the
  seeder picks the constant up for fresh seeds and the dev-seeded
  `word.enrichment` row was refreshed by hand (`firstOrCreate` never
  rewrites) and verified. Ticket 10 owns the scoring change; nothing
  here touches completeness.

## 2026-10-20 (popup-ai-enrich 08: monolingual AI enrichment — definitions and etymologies in the word's own language)

The ask-AI prompt no longer orders definitions in the user's native
language (the "Russian definitions for English words" report — the prompt
was working as written):

- **The prompt is monolingual** (`domains/interactive-words.md` prompt
  bullet, `domains/ai-providers.md` consumers bullet): the
  `word.enrichment` fallback (`PromptTemplates::ENRICHMENT_FALLBACK`)
  now orders definitions, etymology notes and examples in `:language`
  (the word's own language), transcriptions IPA — only the translations
  stay in `:native`. Strict-JSON contract, five keys, plain strings,
  caps and the empty-array escape unchanged. The template is seeded
  into `prompt_templates` (`PromptTemplateSeeder` `firstOrCreate`, like
  `word.explanation`) so the row is admin-visible/editable; dev
  reseeded. The Filament resource's placeholder hint lists `:language`.
- **The import guard holds for AI** (`domains/interactive-words.md`
  merge bullet, `database/dictionary.md` definitions row):
  `WordAiEnrichmentService::applyParsed` filters AI definitions through
  `DefinitionLanguage::keeps()` before insert — a disobedient model's
  foreign-script definitions drop (en→Latin, ru/be/uk→Cyrillic
  majority); an all-dropped reply still finalizes SUCCEEDED (only
  unparseable replies are 502); etymologies stay unguarded, the
  import's parity.
- **ADR 0082** gained a dated amendment (2026-10-20) recording both
  changes; history not rewritten.

## 2026-10-20 (popup-ai-enrich 07: missing-word re-resolution — the asked-once word stays found)

The adopt-on-ask persistence gap closed (ticket 07, the follow-up spec's
item 6; diagnosis verified against the dev DB 2026-10-20: the minted words
persist with `source='ai'` rows and succeeded ledger entries, but nothing
links them into the page's `entity_words` map — `entities:enrich` is
scheduled and dev runs no scheduler):

- **`POST /words/lookup`** (`WordController::lookup`, auth+approved,
  throttle 60/min, beside `GET /words/{word}`; new `WordLookupRequest`
  mirroring the adoption-mode validation rules — surface max 256 with the
  empty-key rejection, `exists:languages,code`): takes the RAW clicked
  surface + the clicked column's language code, answers
  `{data: {word_id: int|null}}` — the id alone, the payload shape stays
  owned by `GET /words/{word}`.
- **`WordAiEnrichmentService::resolveSurface`**: the read-only half of
  `resolveOrAdopt` extracted (which now calls it and only mints on null —
  exact behavior kept): `WordLookupKey` normalize, query
  `(language_id, l_word)`, classed row beats the unknown placeholder with
  the unknown class id resolved READ-ONLY (a language without the
  placeholder row falls back to the first candidate — a lookup can never
  create a word-class or word row).
- **`WordPopup.jsx`**: a missing-mode popup with a language descriptor
  POSTs the lookup once on open (`postLookup` module helper, CSRF-signed);
  a hit stores `lookedUpId`, which feeds the detail-fetch effect
  (`fetchId = wordId ?? lookedUpId`) — the dictionary view loads like a
  mapped token's and `ai_asked` greys the icon; a miss (or a failed
  lookup) leaves ticket 03's card untouched, the ask icon only disabled
  while the resolve is in flight (`lookupBusy`); the lookup effect guards
  stale answers with the fetch effect's `cancelled` pattern. A 409 from
  the ask now syncs the popup: the known path refetches
  `/words/{id}?surface=` and swaps it in under the `staleAsk` guard, the
  missing card re-runs its lookup (the adopted id is unknown client-side).
  Render branch grew the `lookedUpId == null` conjunct.
- Tests: new `tests/Feature/WordLookupEndpointTest.php` (7 tests —
  classed-over-unknown, the minted-word resolution (the report's exact
  shape with the succeeded ledger), ё/stress variants, null miss,
  no-unknown-class-created read path, validation, guests 401 +
  unapproved-redirect); vitest source pins updated in
  `WordText.missing.test.js` + `WordPopup.toolbar.test.js` and added in
  `WordPopup.lookup.test.js` (242 pass total); enrich suites re-run green
  (23); full `test:tia` green, pint clean.
- Wiki: this entry; `interactive-words.md` (missing-card "almost nothing
  runs on open", new re-resolution section, routes row, description);
  `generated.at` bumped. `wiki:sync` re-stamped references for the new
  route.

## 2026-10-19 (popup-ai-enrich review fix pass — 8 accepted findings, one commit)

Code-review fixes on the merged ask-AI surface (no schema change, no
route change; behavior text below supersedes the ticket-04/05 entries
where they overlap):

- **Pending is a grace, not a lock** (`domains/interactive-words.md`,
  ledger-lock paragraph): a `word_ai_fetches` row pending longer than 15
  minutes (`ReadingAiController::PENDING_STALE_AFTER_MINUTES`; provider
  cURL timeouts cap at 120s) is stale — the retry takes it over
  (attempts++, `asked_at` refreshed) instead of the old permanent
  in-progress 409 stranding the word after a crashed ask.
- **The enrich contract takes an optional `surface` on both modes**:
  the known-word ask carries the popup's clicked surface, normalized
  like the word-show endpoint's (`WordFamily::lookupKey`), so the
  response payload keeps `is_form`/`form_of` on the live swap (it used
  to rebuild from `l_word` and drop the "«went» — form of «go»" line).
  `AiWordEnrichRequest`'s modes are exclusive on `word_id` vs
  `language_code` only now.
- **A parsed reply that dedupes to nothing new finalizes SUCCEEDED**
  (200, `ai_asked` true) — the old 502 "no new dictionary content"
  left an already-rich word re-askable forever; only an unparseable
  reply stays a retryable 502.
- **The ask error state carries a Retry control** (both popup bodies
  via the shared `AskAiError` render — the four-state contract's error
  treatment; new ui-string `word.ask_ai_retry`, en/ru/be, reseeded).
  Smaller refactors: the ledger's provider/model split goes through
  `AIModelResolver::parseModelString`; `EnrichmentReply::stringList`
  keys its cap/limit/drop rule off the section name, not the limit
  value; `WordAiEnrichmentService::mergeAiRows` collapses the three
  identical exists→create loops (examples keep their firstOrCreate
  dedup — the DB constraint is a different mechanism); enrich tests use
  `assertForbidden`/`assertTooManyRequests` per the coding conventions.

## 2026-10-19 (popup-ai-enrich 06: verification + knowledge-base closure — the whole surface reviewed against the merged code)

Closure ticket of the `popup-ai-enrich` spec — docs + verification only,
no code changed. Every concept on the feature's surface read end to end
against the five merged commits (01 layout `b9263c3`, 02 completeness
`502fd35`, 03 all-tokens-clickable `d7175a4`, 04 ask-AI `f427db1`,
05 adopt-on-ask `63efac8`):

- **`domains/interactive-words.md`** (tickets 01–05 rewrote it in place):
  reviewed against the merged controllers/services/JS; the one stale
  claim found — the Context explanation's error shape, still describing
  the retired `{data: {data: {error}, code}}` envelope — corrected to
  the reading surfaces' one JSON envelope (top-level `error` + HTTP
  status, ADR 0069), matching `ReadingAiController::aiError`.
- **Tab-strip leftovers removed** (ticket 01's flagged wording):
  `domains/reader.md` ("AI Context explanation tab" → toggle),
  `domains/profile.md` (the preview's "Explanation tab" → the toggle's
  pane; the Popups-tab entry now notes the `explanation` checkbox label
  still literally reads "Explanation tab" — the ui-string was not
  renamed), `domains/bilinguals-simulator.md` (the Models-used popup's
  "the word popup's Explanation tab opens" → toolbar robot; the
  explanation bullet's "button on the popup's second tab" → the toolbar
  toggle; renderMarkdown's "Context explanation tab" → pane). The
  simulator's routes table gained the shared `/ai/word-enrich` row.
- **`domains/ai-providers.md`**: the no-client-model endpoint list now
  includes `/ai/word-enrich` (explanation model) beside
  `/ai/word-explain`; the ticket-05 consumer note already covered the
  enrichment flow.
- **`database/dictionary.md` + `database/schema-overview.md`**: reviewed,
  no changes needed — tickets 02/04/05 already wrote the completeness
  columns, the `word_ai_fetches` ledger, the `source` provenance columns
  and the `word.enrichment` template row.
- **GLOSSARY.md**: three new terms under a new "Interactive words"
  section — word completeness, AI word enrichment (ask AI), missing word
  (definitions only, no implementation detail).
- Staleness warnings: `wiki:validate` reports the same 32 warnings as
  before the feature (pre-existing on unrelated concepts — Anki tab
  sources, AGENTS.md, package.json, route-file churn on concepts that
  make no route claims: architecture/overview, entities,
  recommendations). Of the warnings touching this feature's files,
  everything with a real stale claim is fixed above; `domains/
  anki-export.md` (flagged on `WordPopup.jsx`) makes no popup-structure
  claims beyond the action toolbar and stays accurate.

`generated.at` bumped on the five touched concepts (interactive-words
re-stamped `verified` after the review); `wiki:sync` regenerated the
machine references (`web-routes.md` picks up `ai.word-enrich`,
`commands.md` `words:recalc-completeness`, `models.md` `WordAiFetch` —
all already present from the code tickets); `wiki:validate` conformant.
Verification battery on the integration branch: full `composer run test`,
`npm run test`, `pint --dirty` — numbers in the ticket file.

## 2026-10-19 (popup-ai-enrich 05: adopt-on-ask — the missing popup's path into the dictionary)

The missing-word card's Ask-AI icon went live (ticket 05 of the
`popup-ai-enrich` spec; spec decision 5):

- **`POST /ai/word-enrich` grew its second mode** (`AiWordEnrichRequest`):
  `{surface, language_code}` beside `{word_id}`, the two exclusive. The
  language must map to a `languages` row and a surface whose
  `WordLookupKey` key normalizes to nothing is rejected at validation; the
  key is derived server-side — the raw clicked surface is never trusted.
  `WordAiEnrichmentService::resolveOrAdopt` resolves it: a **hit adopts
  onto the existing row** (a properly classed row over the unknown
  placeholder, `EntityWordAdoption`'s rule — `Ёморок`/`ёморо́к` land on the
  `еморок` row, no duplicates), a **miss mints exactly one word** with the
  import-creator hygiene (plain display word, folded `l_word`, unknown
  class — ADR 0059 spirit, never a class from the translations — carrying
  itself as its lemma form through `LemmaForms`, ё е-alias included; the
  firstOrCreate on the unique triple is the race net). The model gate
  precedes the mint (no-model users mint nothing); then the identical
  ticket-04 enrichment flow runs on the resolved word.
- **Concurrent double-ask arbitration**: a pending `word_ai_fetches` row
  (the winner mid-flight) answers the loser a clean retryable-later 409
  ("An AI ask for this word is already in progress."); a truly
  simultaneous loser, whose ledger insert bounces off the unique word
  constraint, gets the same answer instead of a 500. Failed asks keep the
  minted word and a retryable failed ledger.
- **`WordPopup.jsx`**: `MissingPopupContent`'s ask icon is live — it posts
  `{surface, language_code}` from the popup's `language` prop (a column
  without a descriptor keeps it disabled) and, on 200, swaps the response
  payload in with the same setData move as ticket 04 — the missing card
  becomes the full dictionary view, its progress/Anki/explain handlers
  acting on the adopted `data.id` through `resolvedWordId`. Both ask paths
  share one `runEnrich` whose stale-origin guard (data mirror + word id /
  surface / column language) drops late responses for a popup that moved;
  no-model (400) / no-key (403) answers get the explain pane's
  go-to-settings link on the missing card. No new ui-strings.
- Tests: `tests/Feature/AiWordEnrichAdoptTest.php` (8 endpoint tests),
  ticket-04 suite + word-endpoint pins re-run green, vitest source pins
  updated in `WordPopup.toolbar.test.js` + `WordText.missing.test.js`
  (233 pass), full `test:tia` green (1407), pint green.
- **Concepts updated**: `interactive-words.md` (missing-card section's
  adopt-on-ask, the enrich contract/ledger-lock paragraphs, routes table),
  `ai-providers.md` (enrichment consumer names the adoption resolve);
  `generated.at` bumped on both.

## 2026-10-19 (popup-ai-enrich 04: ask-AI enrichment — sync, ledger-locked, merged inline)

The word popup's Ask-AI icon went live (ticket 04 of the `popup-ai-enrich`
spec; ADR 0082):

- **`POST /ai/word-enrich`** (`ReadingAiController::enrichWord`, throttle
  20/min, auth+approved, beside `/ai/word-explain`): takes `{word_id}`, asks
  the user's **explanation model** through the same BYO-key seam
  (`AIModelResolver::resolveExplanationModel` + `ask`; null model → 400
  guidance, no ledger row), and merges the reply inline. The response is the
  **word-show payload itself** — both endpoints now assemble through the new
  `App\Classes\WordPopupPayload` presenter (extracted from
  `WordController::show`, which also gained `ai_asked`) — so the popup swaps
  it in live; the word is touched on success, making ticket 02's stored
  completeness stale before the payload build recalculates it.
- **`word_ai_fetches` ledger** (migration `2026_10_19_000003`, unique per
  word): provider/model, status pending/succeeded/failed, attempts, error,
  asked_at, succeeded_at. A **succeeded row is the forever lock** (409 + the
  payload's `ai_asked` greying the icon); failed attempts stay retryable at
  any count (no-model requests record nothing).
- **Lenient parsing + capped merge**: new
  `App\Support\EnrichmentReply` (fences/prose stripped, shapes coerced,
  counts capped 12/8/4/4/4, lengths truncated to the column limits —
  translations dropped past 256 instead) and
  `App\Classes\WordAiEnrichmentService` (one transaction: content sections
  exact-text deduped, new rows `source = 'ai'`; translations link-only per
  ADR 0078 through the shared `TranslationTargets`/`StagedTranslations`
  toolkit — ZERO word rows). Prompt: admin-editable `word.enrichment`
  template with a code fallback (`PromptTemplates::enrichment`).
- **Per-row provenance**: nullable `source` columns on
  definitions/transcriptions/examples/etymologies (migration
  `2026_10_19_000004`; `'ai'` vs null imported; DB-only — the popup renders
  no marker). Content models' `$fillable` grew `source`.
- **Frontend** (`WordPopup.jsx`): the ask icon reads the payload's
  `ai_asked` (grey + "AI already asked" tooltip + no-op click), disables
  while the POST is in flight, and swaps `setData(json.data)` on success;
  failures land verbatim beneath the toolbar (`word-popup-ask-ai-error`).
  The ask callback guards both completion paths with the mirrored data ref
  and resets on word change. New ui-strings `word.ask_ai_already` /
  `word.ask_ai_sending` / `word.ask_ai_failed` (en/ru/be), re-seeded into
  the dev DB via `db:seed --class=UiStringSeeder`.
- **Schema**: both migrations are additive; plain `php artisan migrate` ran
  on the dev DB (`ext_app`) as expected.
- Tests: new `tests/Feature/AiWordEnrichEndpointTest.php` (13 tests —
  end-to-end enrichment with `AIModelResolver` mocked at the container per
  the explain endpoint's rule, lock/retry/403/400, dedup + provenance,
  link + stage + zero word rows, caps/truncation, template + fallback,
  validation, throttle) and `tests/Unit/EnrichmentReplyTest.php` (7 parser
  tests); `WordEndpointTest` pins `ai_asked`; vitest source pins updated in
  `WordPopup.toolbar.test.js` (live icon, label states, error line,
  request/swap/guards) and `WordPopup.ankiSend.test.js`'s send-source slice
  scoped to `sendToAnki` (the new ask callback carries its own dataRef
  guards). Full suite green (test:tia), vitest 229 passing, pint clean.
- Wiki: this entry; `interactive-words.md` (new Ask-AI enrichment section +
  routes table + sources), `ai-providers.md` (consumer + template),
  `database/dictionary.md` (+ `word_ai_fetches`, source columns),
  `database/schema-overview.md` (domains row + template key);
  `docs/adr/0082-ask-ai-enrichment-merge-inline-lock-on-success.md`.

## 2026-10-19 (popup-ai-enrich 03: unknown tokens clickable + missing-word popup)

Every tokenizer word token is now interactive (ticket 03 of the
`popup-ai-enrich` spec; JS-only plus one ui-string):

- **`WordText`**: the key-segment branch no longer gates on the word map —
  every key segment renders as a `role="button"` span. Mapped tokens keep
  tint + fetch + events unchanged; an unmapped token is a bare
  `.word-token` (hover-underline + pointer, NO familiarity tier) whose
  Ctrl+click / Ctrl+Enter opens the popup in **missing mode** with
  `wordId: null` and the RAW surface (`segment.text`, not the folded key)
  as the title. The branch returns before `recordWordEvents`, so no
  `/word-events` lookup can fire for a missing token. The old unclickable
  phrasal-hit span branch (`word-token phrasal-hit` tooltip-only span) is
  gone — the mark rides `markClass` onto the interactive span, so an
  unmapped phrasal-verb hit opens the missing card too.
- **`WordPopup`**: a null `wordId` renders the new exported
  `MissingPopupContent` — surface title (`word-popup-title`), honest
  `word.not_in_dictionary` note (`word-popup-missing`; "This word isn't in
  the dictionary yet." / ru / be, never any loading/fetching claim), and a
  toolbar holding only the disabled Ask-AI placeholder (ticket 05 wires
  adopt-on-ask there). No `GET /words/` (the fetch effect guards
  `wordId == null` before its fetch), no familiarity/frequency/form-of, no
  progress/Anki/explain. Escape / outside-click closing works as before;
  the profile's Popups-tab preview is untouched (it renders
  `PopupContent` directly).
- **Language plumbing**: `WordText`/`WordPopup` grew a `language` prop —
  the column's descriptor language (`{code, name}|null`) — passed by
  `ReaderRow` (`first.language`/`second.language`) and the simulator's
  `TextContent` (`props.first.language`/`props.second.language`). Unused
  in this ticket; ticket 05's adoption resolves the new word's language
  from it.
- **`database/seeders/ui-strings/words.php`**: `word.not_in_dictionary`
  (en/ru/be), re-seeded into the dev DB via `db:seed --class=UiStringSeeder`.
- Tests: new `WordText.missing.test.js` (15 source pins following the
  ADR 0066 pattern — all-tokens-interactive, no tint without an entry,
  missing payload + zero-network guards, missing-card contents, language
  plumbing on both surfaces, honest seeder copy); tokenizer parity tests
  untouched and green; vitest 223 passed, `test:tia` 1378 passed,
  `UiStringSeederTest` + `TokenizerParityTest` green, `pint --dirty` clean.
- **`domains/interactive-words.md`**: "every token interactive" + the
  missing-word card documented (new section, class list, highlighting
  rule, step 1/2 wording); `generated.at` bumped.

## 2026-10-19 (popup-ai-enrich 02: word completeness 0-7 — lazy recalc, popup ordering, backfill)

Stored word-completeness score (ticket 02 of the `popup-ai-enrich` spec;
backend only — the popup payload's entry order changes, ticket 01's layout
renders it unchanged):

- **`words.completeness`** (unsigned tinyint, nullable) +
  **`words.completeness_at`** added (migration
  `2026_10_19_000002`). The matrix (`WordCompleteness::score`): >= 1
  linked translation 2; definitions/transcriptions/examples/etymologies
  exist 1 each (existence, never row counts); a real frequency rank 1
  (0 < rank < the 1,100,000 sentinel — 0 is the never-ranked column
  default, the same window ADR 0041's LEAST fold guards).
- **Lazy staleness recalc**: `WordCompleteness::recalcFamily` runs inside
  `WordFamily::resolve` — a word whose `completeness_at` is missing or
  older than its own `updated_at` or the newest stamp of its content
  children (definitions/transcriptions/examples/etymologies/forms — all
  five carry created_at+updated_at — plus the `word_translations` pivot,
  which carries 2 of the 7 points) is recomputed and re-stamped; fresh
  words are never rewritten. The family's eager load grew the child
  timestamp columns so the threshold reads off the loaded relations; forms
  and the pivot aggregate with one query each per family.
- **Popup ordering**: the assembled entry list (linked word first, class
  priority, base-group frequency) now stable-sorts
  completeness-descending — the assembly order is the tiebreak, so a
  sparse linked entry can sink below a rich base entry while
  `data.id`/`data.word` still bind the linked word. ADR 0045/0047 family
  semantics untouched.
- **`words:recalc-completeness` {--stale-only} {--chunk=}`**: chunked
  set-based sweep of the same matrix in SQL (command test pins the mirror).
  Full run = authoritative re-score writing only changed values (re-run is
  a no-op); `--stale-only` rewrites only what the staleness predicate
  flags. Dev DB (ticket-02 run, 2026-10-19): 1,494,357 words scanned,
  1,494,357 rewritten (first backfill — every stamp was null), 3m50s;
  histogram: 0 -> 1,200 / 1 -> 377,307 / 2 -> 468,420 / 3 -> 422,612 /
  4 -> 97,459 / 5 -> 48,809 / 6 -> 78,550 (no 7s); a second --stale-only
  pass right after rewrote nothing.
- **`database/dictionary.md`**: `words` table row carries the two new
  columns; a completeness note (matrix, triggers, command, run numbers)
  added; sources gained the migration, `WordCompleteness` and the command.
- **`domains/interactive-words.md`**: step 3 (popup details per word
  family) documents the lazy recalc + stable completeness-descending sort
  (header binds the linked word independently of entry position); the
  `GET /words/{word}` route row names the ordering. `generated.at` bumped
  on both concepts.

## 2026-10-19 (popup-ai-enrich 01: word popup loses its tab strip — toolbar icons, header hairline, on-demand Explanation)

Popup layout rework (ticket 01 of the `popup-ai-enrich` spec; reader and
simulator render the shared component, so both surfaces change at once):

- **`domains/interactive-words.md`**: the popup's structure rewritten —
  no tab strip any more (the dictionary content always renders, a hairline
  closes the header block); the first-row toolbar now reads know / remove
  left, then pushed right Anki → the on-demand **Explanation toggle** → the
  **Ask-AI icon** (disabled placeholder until ticket 04 wires the
  enrichment) → the robot (Models-used) icon rightmost; the Context
  explanation section retitled and rewritten around the toggle (first
  click mounts the pane as the dictionary view's first row behind the POS
  hairline and fires the request; hide keeps the page-lifetime cache;
  `aria-pressed` + verdigris tint while shown; toggle + robot gated on the
  explain payload and the ADR 0046 `explanation` key); the stable-class
  list updated (`-tabs`/`-tab` gone; `-actions`, `-explain-toggle`,
  `-ask-ai`, `-explain-row` in). `generated.at` bumped.

Known stale spots left for ticket 06's closure sweep (outside this
ticket's concept scope): `domains/reader.md` ("explanation tab"),
`domains/profile.md` (Popups-tab copy), `domains/bilinguals-simulator.md`
("second tab", "explanation tab") still speak the tab wording.

## 2026-10-19 (code-review fixes — word-import-contract ticket 08)

The closing review pass over the whole `word-import-contract` branch
(diff `26f0fab..word-import-contract`, both axes: standards + spec).
Four fixes, two dispositions, no behavior change beyond a one-line
guard internal:

- **`resources/js/lib/wordTokenizer.mjs`**: the key-pipeline comment
  still cited the retired `WiktionaryParser::normalizeLookupKey` name
  (ticket 09 fixed the PHP docblock only) and narrowed the mark strip
  to "stress marks, U+0301" — reworded to the shipped truth
  (`WordLookupKey::normalize`, the strip covers all `\p{M}`).
- **`app/Support/DefinitionLanguage.php`**: `keeps()` read the
  `SCRIPTS` map directly instead of through `scriptFor()` — one
  lookup, not two (identical behavior).
- **`database/schema-overview.md`** (pre-existing staleness, in scope
  per the ticket): the Dictionary row still called `word_translations`
  a "directed" pivot — symmetric canonical pairs since ADR 0020 — and
  predated the words uniqueness move; now names the
  `(l_word, language_id, word_class_id)` unique (ADR 0081) and the
  symmetric pivot (ADR 0020). `generated.at` bumped.
- **Review dispositions (no change):** the `CreateWord`
  `mutateFormDataBeforeCreate` autofill (ticket 04) stays — strictly
  beyond the "leave the admin page alone" letter (a blank `l_word`
  stores NULL, which the NULLS DISTINCT index permits), but it is the
  FormsRelationManager precedent applied to the one case where the
  admin form would mint a permanently-unresolvable NULL-key row;
  fires only when blank, typed keys untouched. The
  `WordsNormalizeCommand` phase-3 rework (ticket 04) stays — the
  unknown-twin survivor resolution participates in satisfying the new
  unique, inside the ticket's "switch mechanically where the index
  would otherwise throw" clause.

## 2026-10-19 (knowledge-base closure — ADR 0081, the word-import-contract record — word-import-contract ticket 07)

The documentation record of the whole shipped contract (docs-only change,
no code). **New ADR 0081**
(`docs/adr/0081-word-l-word-contract-uniqueness-on-l-word.md`): the
word/l_word contract as six rules — plain display text (stress marks
stripped for ru/be/uk ONLY, ё always kept, spelling follows the import
source and stays hand-editable), the one l_word formula identical for
words and forms (trim → lowercase → `\p{M}` strip → `\p{Cf}` strip →
ё→е fold), the е-alias form rows, word uniqueness on
`(l_word, language_id, word_class_id)` with the merge-not-replace
attachment of ADR 0080 continued onto spellings and the
normalize → gate → migrate ordering for existing databases (survivor
preference ё-display > most definitions > lowest id), the
`DefinitionLanguage` majority-script definition guard (import-time only,
etymologies unguarded), the `dictionary:link-translations` chaining into
`goldendict:import`, and the three-way tokenizer parity with
`ALGORITHM_VERSION` deliberately unbumped. Alternatives considered
recorded (lowercased-display uniqueness, display-side ё folding,
migration-self-merge, stored-definition purge, dual tokenizer formulas).
Consequences carry the ticket-06 dev-DB switchover numbers: 19,986
duplicate rows merged (+14 unknown twins, 20,000 absorbed), 18,277 word +
131,147 form keys recomputed, the all-zero gate, the unique built in 1s,
en −14,043 / ru −5,957, ёлка one ё-display row with both form rows,
собака 24 definitions intact.

Closure fixes across the touched concepts (contradictions and stale
statements the per-ticket edits had left):

- `domains/dictionary-import.md`: the description frontmatter now
  reflects the whole shipped contract (uniqueness triple, tokenizer
  parity, link-pass chaining) and **no longer mis-cites the
  `DefinitionLanguage` guard to "ADR 0078, addendum 2026-10-18"** — the
  guard shipped 2026-10-19 under this contract (ADR 0081); the Creator
  hygiene uniqueness sentence cites ADR 0081 and records that the dev DB
  switched over; the Data repair section gained the 2026-10-19
  switchover run record with the per-language combining-marks gate
  wording (0 in ru; en legitimately keeps 334 mark-bearing rows where
  the marks are spelling); "the ticket-08 attach rules" disambiguated to
  the dictionary-word-purity spec's creator-attach ticket (this
  feature's ticket 08 is the code review).
- `database/dictionary.md`: description names the
  `(l_word, language_id, word_class_id)` unique; the l_word note cites
  ADR 0081 and records the switchover as COMPLETE (the recompute/merge
  phases are no-ops on current databases); sources gained ADR 0081 +
  `WordLookupKey`.
- `playbooks/import-dictionary-data.md`: the normalize section spells
  out the five-step switchover order for an existing database
  (baseline dry → real run → THE GATE (0 duplicate-merge dry row / SQL
  having-count) → `migrate` → post-migrate no-op dry, with the stop rule)
  and records the real 2026-10-19 run numbers (102s/184s/86s timings,
  dry-vs-real deltas and why, count moves, ёлка/собака spot gates, the
  per-language marks caveat); **the Clean-rebuild step's `cmudict:import`
  corrected to `dictionary:import-cmudict`** (the actual signature);
  description reflects the chaining + normalize-before-migrate order;
  sources gained ADR 0081, the uniqueness migration and both repair
  commands.

`generated.at` bumped on all three concepts; ADR 0081 added to their
sources. `wiki:sync` run (regenerated the reference stamps — no
route/model/command changes in this ticket); `wiki:validate` green.

## 2026-10-19 (entity tokenizer folds ё in parity with the dictionary key — word-import-contract ticket 09)

The last code gap ticket 04's audit flagged ("biggest hidden dependency"):
`WordTokenizer::lookupKey` still carried the pre-ticket-01 formula
(lowercase + mark strip only), so `EntityWordIndexer` stamped
`entity_words.l_word` with ё-unfolded keys — a ёлка token keyed `ёлка`
while the post-ticket-05 dictionary keys `елка`: the linker missed, and
`EntityWordAdoption`'s mint had to fall back to its `firstOrCreate`
hardening on every ё word. The tokenizer now delegates to
`App\Support\WordLookupKey::normalize` (trim → lowercase → mark strip →
zero-width/format-character strip → trim → ё→е fold), and the two parity
twins carry the same pipeline: `resources/js/lib/wordTokenizer.mjs` (new
`lookupKey()`, pinned by a new vitest suite `wordTokenizer.test.mjs` on
the JS side and the extended `TokenizerParityTest` samples — ё/Ё, ё+acute,
ZWSP/soft-hyphen splits — on the PHP↔node pair) and the python tokenizer
(`ai/enrichment/cyrillic_stress.py` gains the public `lookup_key`, which
replaces the `_normalize_yo(strip_marks(...))` Silero comparison; pinned
by two new pytest tests). Result-neutral for enrichment — token surfaces
cannot carry whitespace or `\p{Cf}` characters, so the python comparison
key only gained strips that cannot fire and `ALGORITHM_VERSION` stays 1
on both sides (no re-enrichment sweep owed). Entity pipeline covered
end-to-end by a new `EntityWordIndexerTest` case: a ёлка sentence stamps
`елка`, the linker stamps it unmatchable until the е-keyed row arrives,
and adoption attaches to it instead of minting. Concepts touched:
`domains/interactive-words.md`, `domains/crossword.md`,
`domains/sentence-enrichment.md`, `domains/dictionary-import.md`
(adoption bullet — stamps are born folded, the stale-stamp path is
legacy-only), `generated.at` bumped. No routes/models/commands added —
no `wiki:sync` needed.

## 2026-10-19 (dev-DB contract switchover run — word-import-contract ticket 06)

The operational switchover of the dev database `ext_app` to the
word-import-contract (no code changed): extended `words:normalize` FIRST,
gate on zero duplicate groups, then the uniqueness migration. Baseline
`--dry` (102s): 19,986 would-be duplicate-row merges, 18,277 word + 131,220
form key recomputes, all other phases 0. The real run (184s, detached to
`storage/logs/words-normalize.log`): merged the same 19,986 duplicate
`(l_word, language_id, word_class_id)` rows plus 14 unknown-class twins
that only the real (post-merge) row state exposed — 20,000 absorbed rows
deleted in total, exactly the dry's child-move predictions at the summary
level — recomputed 18,277 word keys and 131,147 form keys (73 fewer than
dry: absorbed rows' forms were already gone), moved 28,501
definitions/etymologies, 38,035 forms, 1,248 transcriptions (9,525 dropped
where the survivor holds stressed IPA), 185 entity_words, 9 user_word +
16 event rows, re-pointed 1,941 translation links with 1,992 collapsing
onto existing pairs, union-merged 260 staged-JSON rows, LEAST-folded 914
survivors. Word counts: en 1,013,927 → 999,884 (−14,043), ru 500,430 →
494,473 (−5,957) — the 20,000-row drop is exactly the merge count; forms
5,579,192 → 5,568,861; NULL-key words 0 after the backfill.

Gate before migrate: the second `--dry` reported all zeros and the SQL
duplicate-group count was 0. `php artisan migrate` built
`uk_words_l_word_language_class` in 1s (the display-word unique and the
superseded `idx_words_l_word_class` gone); post-migrate `--dry`: all
zeros. Spot gates: ёлка/елка are ONE row (display `ёлка` kept, key
`елка`, frequency 4753 LEAST-folded from the unranked 1,100,000 sentinel)
carrying BOTH form rows (ёлка + елка, both keyed `елка`); собака one row
with 24 definitions intact (22 + 2 moved from the absorbed `Собака`
case twin — "dog"/"hound" English glosses + 17 Cyrillic ones); staged-JSON
trim sample: 0 padded candidates of 7,931 strings across 5,000 staged
rows; combining marks: ru `words.word`/`forms.form` 0 (en legitimately
keeps 100 words + 234 forms — Latin-script linguistic entries like
Pe̍h-ōe-jī / Sḵwx̱wú7mesh / K̂äxk̂är where the marks are spelling; the
strip contract covers the stress-mark languages only). Ticket 07 turns
these numbers into the ADR 0081 / knowledge-base record.

## 2026-10-19 (words:normalize l_word recompute + duplicate merge — word-import-contract ticket 05)

`words:normalize` grew the one-time data path for existing databases: six
phases now — the four established ones stay, and phase 2 recomputes every
`words.l_word` and `forms.l_word` to the current `WordLookupKey` formula
(stale old-formula keys like ёлка, en diacritic keys, NULL backfills —
only rows whose value changes, chunked) then merges duplicate
`(l_word, language_id, word_class_id)` rows; phase 5 recomputes the entity
tokenizer's `entity_words.l_word` stamps from the token, folding
same-key stamp collisions (counts sum, linked word adopted). The merge
survivor preference is a display containing ё first (the fullest spelling
survives — the user edits the display later), then most definitions, then
the lowest id; detection runs on the effective post-recompute keys and the
key updates apply only after the merge, so no update collides with the
words unique. The phase runs green under the OLD schema — the unique index
need not exist — and leaving zero duplicate groups is the gate that lets
`migrate` build it (the dev-DB normalize → migrate sequencing; ticket 06).

- **Command (`app/Console/Commands/WordsNormalizeCommand.php`)**: phase
  order is now strip (1) → rekey+merge (2) → unknown twins (3) → alias
  fold (4) → entity stamps (5) → lemma backfill (6) — the rekey runs
  before the twin/alias phases so their l_word joins see the folded keys,
  and the duplicate merge runs after the stress strip so the case-twin
  residue it creates is absorbed in the same run. Summary-table rows
  renumbered to match; dry counts match the real run's exactly (a
  `absorbed_earlier` snapshot keeps phase 1's not-yet-applied dry merges
  out of the later phases' counts). In-process re-invocation no longer
  accumulates summary counts (the stats reset per run — the command
  instance is cached by the console kernel).
- **Merge machinery unchanged**: the duplicate merge reuses the shared
  set-based moves (children, both link arms, ledger, staged JSON union,
  LEAST rank fold with the zero guard) — `word_translations` land as
  canonical pairs without duplicates.
- **`domains/dictionary-import.md`**: Data repair section rewritten for
  the six-phase pipeline (survivor preference, effective-key detection,
  old-schema sequencing), description updated, `generated.at` bumped.
- **`playbooks/import-dictionary-data.md`**: normalize runbook rewritten
  (six phases, the dry-row list, the normalize → gate → migrate
  pre-migrate note), `generated.at` bumped.
- **`database/dictionary.md`**: the l_word note documents the phase-2
  recompute + survivor merge as the stored-data repair, `generated.at`
  bumped.

## 2026-10-19 (word uniqueness moves to (l_word, language, class) — word-import-contract ticket 04)

The words unique key moved from the display word to the lookup key
(migration `2026_10_19_000001`: `uk_words_word_language_class` and the
superseded `idx_words_l_word_class` dropped,
`uk_words_l_word_language_class` on `(l_word, language_id, word_class_id)`
created): cross-source spellings of one word (до́м/дом, ёлка/елка,
March/march) are now ONE row per class — the first source's display
spelling stands, later sources attach to the stored row. NULL `l_word`
rows stay unconstrained (NULLs never conflict in Postgres). The migration
refuses a database still holding same-key duplicates — the l_word
recompute and duplicate-merge repair ships with the normalize work and the
dev-DB runbook sequences normalize → migrate; fresh databases are
unaffected.

- **Both parsers upsert on the new triple** (`app/Classes/`):
  `WiktionaryParser` conflicts on `(l_word, language_id, word_class_id)`
  refreshing `l_word` + the staged translations JSON (display word and
  frequency never clobbered — the ADR 0080 merge rule), and its record
  merge, in-batch dedupe, post-upsert id map, `lookupWordId` and
  relay-base upsert all key the computed `l_word` — a second source whose
  display differs (елка arriving when ёлка is stored) resolves to the one
  row instead of silently skipping its children. `GoldenDictParser` keeps
  its key-only update list (frequency + curated staged JSON survive) and
  switched its dedupe, id map, `lookupWordId` and dry-run link key to
  `l_word`.
- **Every other creator keyed mechanically** where the new index would
  otherwise throw: `dictionary:import-cmudict` upserts the missing-l_word
  rows on the triple (entry keys now go through `WordLookupKey`),
  `EntityWordAdoption::createAdoptedWord` mints through a `firstOrCreate`
  on the triple (a row that raced in — or one a stale pre-fold entity
  stamp lands beside — is adopted, not duplicated),
  `TranslationsRelationManager::createWordAndLink` checks the typed word
  by its lookup key (the case/ё variant of an existing row is reused, not
  minted), the Word create page autofills a blank `l_word` through
  `WordLookupKey` (the `FormsRelationManager` precedent), and
  `words:reclass-multiword`'s collision guard checks `l_word` instead of
  the display word.
- **`words:normalize` phase 3 folds on the key**: the alias-class twin
  join and the leftover grouping moved from display to `l_word` (lowest-id
  canonical survivor) — a case-variant alias row (`Big` adj beside `big`
  adjective, legal cross-class) would otherwise reclass straight into the
  unique key's collision. Phases 1a/2 already merge on the key or display
  safely; phase 1a's display-exact twin lookup stays (on a migrated
  compliant DB the pair it merges cannot exist).
- **`domains/dictionary-import.md`**: uniqueness formula and the
  GoldenDict/kaikki upsert conventions, the creator-hygiene contract
  (adoption `firstOrCreate`, Filament key checks + autofill), normalize
  phase 3, the reclass guard, `generated.at` bumped.
- **`database/dictionary.md`**: `words` row rewritten for the new unique
  (first spelling stands), the `l_word` note carries the full
  `WordLookupKey` pipeline, sources gained the migration,
  `generated.at` bumped.
- **Tests**: 12 new (schema: the March/march and ёлка/елка same-key
  inserts throw, cross-class and NULL-l_word coexistence; kaikki:
  second-source display collapses into one row keeping display + rank
  while the staged JSON refreshes, adjacent ё/е lines union; GoldenDict:
  a second-source spelling attaches to the one row with both form rows;
  Filament: "create word & link" reuses the variant-spelled row, the
  create page autofills the key; adoption: a stale ё-stamp attaches to the
  folded-key row; reclass: a case-variant phrase collision is skipped).
  The link pass's lowest-id test re-pointed at the legal cross-class
  shape (same-class case pairs are impossible now); the normalize fixture
  lost its same-key `дом` pair — the merge machinery it carried moved onto
  the legal cross-class unknown-twin pair, and the stressed-twin merge
  coverage moves to the upcoming normalize merge-phase tests.
- **Known gap (tracked)**: the entity tokenizer's key
  (`WordTokenizer::lookupKey`) still derives without the ё fold — the
  tokenizer parity work owns it; adoption's `firstOrCreate` already
  absorbs its crash mode.

## 2026-10-19 (link pass chains into goldendict:import — word-import-contract ticket 03)

`goldendict:import` now ends a default run with the
`dictionary:link-translations` pass — the same stage → link → prune
contract `wiktionary:import-raw` already chains. GoldenDict's inline
bilingual linking never prunes its own staged copies, so linked
candidates used to sit in `words.translations` until someone ran the
pass by hand; now a bilingual run converts its own staged candidates —
and any earlier backlog — in the same invocation.

- **Gates** (the pass is skipped, with an info line, when): `--dry-run`
  (writes nothing at all — including not pruning), `--no-link` (which now
  means "skip the bilingual phases **and** the trailing pass"), or fewer
  than two languages holding words. A non-success from the pass warns and
  propagates the exit code.
- **Guard implementation note**: the language-count gate counts via
  `Language::whereIn('id', Word::select('language_id')->distinct())` —
  `Word::query()->select('language_id')->distinct()->count()` compiles to
  plain `COUNT(*)` in Laravel (the `distinct()` is ignored), which let
  the pass run against single-language DBs where it fails by design.
- **Tests**: the "links candidates" contract test now pins the prune
  (resolved candidates → JSON null, unmatched stay staged); the
  idempotency test's third run keeps only the unmatched `Kätzchen`
  staged; the stress-strip test keeps `кот` (no ru row) staged while
  `кошка`/`собака` prune; a new gate test walks `--no-link` → `--dry-run`
  → default run over a pre-seeded backlog (untouched, untouched, then
  linked + pruned).

## 2026-10-19 (majority-script definition guard — word-import-contract ticket 02)

Definitions now import only when they are written in the word language's
own script (the user's "definitions in the word's language" rule — en-en,
ru-ru), enforced at import time on BOTH importers:

- **`App\Support\DefinitionLanguage`** — the shared guard: language code →
  Unicode script (`en` Latin; `ru`/`be`/`uk` Cyrillic; any other code
  unmapped = no guard, pass-through). A definition keeps when the
  language's own script holds a **strict majority** of its `\p{L}` letters,
  an **exact tie drops**, a **letterless** string (digits/symbols only)
  keeps. Etymologies are deliberately never guarded.
- **`WiktionaryParser::flushDefinitions`** runs every gloss through the
  guard before the insert — the en-Wiktionary edition's English glosses on
  ru words store nothing. Each drop counts in the new import stat
  `definitions_skipped_foreign`, surfaced as "Definitions skipped (foreign
  language)" in the `wiktionary:import` summary table and carried through
  `wiktionary:import-raw`'s per-language stat table generically.
- **`GoldenDictParser::parseEntry`** guards the segment→definition path
  (before the 500-char cap and dedupe), so the bilingual GoldenDict
  dictionaries (Oxford en-ru, Mostitsky ru-en) no longer store the other
  language's article prose as definitions; the `--dry-run` tally sees the
  same filtered record. Same stat key, same summary-table row.
- **Import-time only** — no code path mutates existing definitions; the
  en-sourced English definitions already stored on ru rows are untouched
  (the user explicitly scoped enforcement to new imports).
- **Tests**: `DefinitionLanguageTest` (7 unit: script map, both drop
  directions, strict-majority keeps, tie drops, letterless keeps,
  unmapped pass-through); kaikki feature test (ru entry with only English
  glosses stores zero definitions + bumps the stat, Russian glosses keep,
  en symmetric, etymology unguarded); GoldenDict feature test
  (`goldendict-deflang-ru/en.jsonl` fixtures: other-language segments
  drop with the stat, own-language segments store, 500-char cap pinned).
  Seven existing tests encoding the old store-anything behavior updated
  to the new contract (fixture glosses moved to the word language's own
  script where the test's intent needed a stored definition).
- **Wiki**: `domains/dictionary-import.md` (description, sources, the
  WiktionaryParser/GoldenDictParser bullets, the shared-conventions
  section), `database/dictionary.md` (definitions satellite note),
  `playbooks/import-dictionary-data.md` (import step + source);
  `generated.at` bumped on all three. `wiki:validate` conformant.

## 2026-10-19 (ё-aware lookup key, е-alias form rows, storage trims — word-import-contract ticket 01)

Implemented word-import-contract ticket 01 (the foundation ticket 04's
l_word uniqueness move keys on):

- **`App\Support\WordLookupKey`** — the shared `l_word` formula becomes
  trim → lowercase → combining-mark strip (unconditional, en included,
  via `StressMarks`) → zero-width/format-character strip (`\p{Cf}`: ZWSP,
  ZWJ, BOM, soft hyphen — they survived verbatim and fragmented lookups)
  → ё→е fold (ёлка and елка share the key `елка`; the case-preserving
  fold lives in the new public `WordLookupKey::foldYo`). A strip can bare
  a whitespace edge that sat beside a stripped character, so the key
  trims again after the strips. Display text never folds and never loses
  ё: `words.word`/`forms.form` keep the source spelling under the
  unchanged StressMarks rule.
- **`App\Support\LemmaForms`** — every lemma/form containing ё now also
  emits its е-folded alias row (ёлка carries form rows ёлка AND елка,
  both keyed `елка`), so texts and dictionaries that drop ё still
  resolve; the alias dedupes through the same seen-map, so a source
  already listing both spellings stores two rows, not three.
- **`App\Classes\WiktionaryParser`** — kaikki headwords and staged
  translation candidates **trim at storage** (only emptiness was checked;
  the padded string stored verbatim), en and ru alike; the relay base —
  a proxy headword's display word — trims the same way.
- No migration: existing rows untouched (the recompute is ticket 05).
- Concepts updated: `domains/dictionary-import.md` (description,
  WiktionaryParser bullet, the shared-normalization section, LemmaForms
  passage, source titles, `generated.at` bumped),
  `database/dictionary.md` (`forms` row + purity note extended;
  `generated.at` bumped and the stale `verified: human` stamp REMOVED —
  re-verification is the human's call) and
  `playbooks/import-dictionary-data.md` (import-conventions step covers
  the alias rows and the storage trims; `generated.at` bumped). No
  route/model/command changes, so no `wiki:sync` needed.

## 2026-10-18 (code-review fixes — stale human-verified stamps removed — ru-edition-import ticket 04)

Closing review pass over the ru-edition-import branch. Knowledge-base fix:
`playbooks/import-dictionary-data.md` and `domains/dictionary-import.md`
carried `verified: { by: human:alex, at: 2026-10-18T19:40:00+03:00 }` from
before ticket 03 rewrote their content (the `generated.at` stamps already
postdated the verification) — a stale human-verified stamp vouching for
unreviewed content breaks the OKF trust-signal rule, so both `verified`
entries were REMOVED, not re-stamped: re-verification is the human's call.
`generated.at` bumped (22:30) on both — the concepts' body content is
unchanged, they were just re-checked against ticket 04's parser
precedence fix (which cleared the "source changed after generated.at"
warnings that fix newly raised). No `wiki:sync` needed (no
route/model/command changes). The code-review's code fixes
(`WiktionaryParser` empty-singular etymology fall-through + the parser test
fixture helper) live in the laravel tree — see the ticket-04 commit.

## 2026-10-18 (ru-edition knowledge closure — ADR 0080, per-edition playbook, CONTEXT.md glossary — ru-edition-import ticket 03)

Closed the ru-edition-import spec out in the knowledge base (docs-only
change, no code):

- **New ADR 0080**
  (`docs/adr/0080-per-edition-wiktionary-sourcing-merge-on-overlap.md`):
  each language's dictionary sources from its own Wiktionary edition
  dump — English words from the en-Wiktionary dump, Russian words from
  the ru-Wiktionary edition (kaikki.org/ruwiktionary/rawdata.html; the
  en-Wiktionary "Russian" files are a different, lemma-poor source) —
  and editions **merge on overlap, never replace** (word rows upsert,
  definitions/etymologies insert-new-only; the user's explicit call:
  existing ru rows keep their English definitions and gain the ru
  edition's Russian glosses). Records the two parser field-compat facts
  (`etymology_texts` plural array; `lang_code`-only translation
  objects), the `--target-langs` requirement for single-language runs,
  and the real run's numbers — including the planning assumption the
  data refuted (below).
- **`playbooks/import-dictionary-data.md`**: the raw-dump source shape
  generalized to per-edition downloads with both URLs (en and ru
  editions); per-edition run commands (the en `--langs=en,ru` full
  import, the ru `--langs=ru --target-langs=en` edition import) with
  the `--target-langs` gotcha spelled out; the merge-not-replace
  behavior + post-run repair chain documented on the raw-dump step;
  the demote command's `--file=` note now names the ru-edition extract
  cleanup candidate.
- **`CONTEXT.md`**: the "Raw dump" glossary term generalized — one per
  edition, both URLs, not only the English edition.
- **`domains/dictionary-import.md`**: per-edition sourcing paragraph
  with the run numbers, ADR 0080 in sources, description extended.

**Ticket 02's run** (the 2026-10-18 dev-DB ru-edition import, ~29 min):
extract read 2,729,136 lines, kept 489,522 (both exactly as
classified); import 471,292 words / 6,963 relays demoted / 248 relay
bases created; 6 placeholder word classes auto-created
(abbrev/interjection/root/onomatopeia/gerund/circumfix — titles want
`/admin` curation); link pass 109,689 staged→linked (EN→RU 29,709 /
RU→EN 79,980), raw `word_translations` 111,556 → 186,353;
`words:normalize` merged 1,167 unknown twins + 154 lemma forms
backfilled; frequency re-clamp ranked 18,324 new rows; собака merge
verified (same row id, 14 → 22 definitions, English verbatim + 8
Russian added); en 1,013,927 unchanged. ru landed at **500,430**, not
the planned 460–470k: "en-wiki ru words ⊆ ru-wiki" is false — 29,037
pre-existing en-era rows the ru stream never touched (genuinely absent
headwords; 5,742 same-`l_word` different-class pairs like Лета
name-vs-noun; 14,899 relay-shaped rows whose surface the ru dump
demotes — the `wiktionary:demote-form-of --file=…ru.jsonl` cleanup
candidate). Residual 17,890 definition-less ru rows (the ru edition's
own senseless entries, not en-era proxies).

## 2026-10-18 (parser ru-edition field compat — ru-edition-import ticket 01)

`WiktionaryParser` now reads the Russian-Wiktionary edition dump (ru.wiktionary
kaikki extract) as correctly as the en-edition dumps, via two field-compat
fixes; everything else in that dump was verified parser-compatible as-is
(word/pos slugs, glosses, forms, IPA, `form_of` relays, hard-redirect skip):

- **Etymology field shapes**: the parser read only the singular
  `etymology_text` string (all the en edition emits); it now falls back to
  the plural `etymology_texts` **array** of text blocks (all the ru edition
  emits — 453k entries), joining its non-empty parts with newlines (the
  same multi-line convention en-edition etymology strings already carry in
  the DB); the singular wins when both appear, an all-empty array stores
  nothing.
- **Staged-translation target field**: `stagedCandidate` keyed the target
  language on the object's `code` only; the ru edition's translation
  objects carry only `lang_code`, so that edition would have staged zero
  candidates. Now `code` falls back to `lang_code` (en-edition objects
  carry both with equal values — no behavior change, no dedupe risk:
  staged strings array_unique anyway). The stress-mark strip still keys on
  the resolved code.

Smoke on the real ru dump (testing connection, first 20k lines, 2,766 ru
word rows): 2,558 etymologies and 2,288 staged-translation words landed,
zero combining marks in stored words/forms. The full dev-DB import run and
its numbers are ru-edition-import ticket 02; the edition-per-language
knowledge closure (ADR 0080, playbook) is ticket 03.

## 2026-10-18 (code-review fix pass: kaikki staged candidates strip at staging time, one shared wanted-class decision — dictionary-word-purity ticket 13)

The feature's code-review findings, fixed:

- **Kaikki staged translation candidates strip at staging time**
  (`laravel/app/Classes/WiktionaryParser.php` `extractTranslations`): the
  parser stored `$translation->word` verbatim, so a marked ru/be/uk
  candidate sat in `words.translations` marked indefinitely (the link pass
  strips at pairing time, but unmatched candidates waited). Now the strip
  keys on the candidate's target `code` — stress-mark-language candidates
  store plain (a marks-only candidate stages nothing, per the
  marks-only-artifact convention), non-stress-mark targets keep their
  diacritics. Old marked rows stay prunable through the link pass's
  pairing-time strip.
- **One shared wanted-class decision**
  (`laravel/app/Support/TranslationTargets::wantedClasses`): the
  "multi-word → target `phrase` class, single-word → its pos class, else
  the source word's class, else null" map was built twice (popup
  auto-fetch `FetchWordTranslations::wantedClasses`, link pass inline in
  `LinkTranslationsCommand::linkDirection`); both now delegate to the
  shared helper (staged strings and `array{text, pos}` provider
  candidates both accepted). GoldenDictParser's per-record batch variant
  does not fit the per-source-word shape and stays.
- **words:normalize stat keys renamed self-evident** (internal keys only,
  summary labels stable): `p3_merged`/`p3_reclassed`/`p3_classes` →
  `alias_merged`/`alias_reclassed`/`alias_classes_deleted`,
  `links_rep`/`links_self` → `links_repointed`/`links_self_dropped`.
- **DemoteFormOfCommandTest's `$form` helper** keys through
  `WordLookupKey::normalize` instead of a bare `mb_strtolower`.

Recorded judgments (no code): `EntityWordAdoption::preferClassed` does
NOT reuse `TranslationTargets::pick` — adoption prefers "first
non-unknown-class row in id order, else lowest id", an exclusion pick()
cannot express (a null wanted class there makes the unknown twin
eligible), and the batched chunked-lookup shape is the point of that
path; the single-word creator convention (strip → `WordLookupKey` →
`LemmaForms`) stays as 3-line call sites backed by the two shared helpers
— a payload wrapper would be a Middle Man over them; ADR 0079 gained one
Consequences line on the repair commands' deliberate raw set-based SQL;
SAMPLE_LIMIT/memory-limit constants duplication and the baseline
`.gitignore` rewrite left as-is (trivial / historical).

`dictionary-import.md` updated (kaikki bullet + link-pass bullet +
"GoldenDict vs Wiktionary" paragraph document the staging-time strip and
the shared wanted-class map; description refreshed, `generated.at`
bumped). No routes/models/commands added → `wiki:sync` run anyway,
`wiki:validate` 0 warnings. Tests: `WiktionaryParserTest` marked-candidate
rewrite (plain storage, marks-only stages nothing, non-stress target
verbatim) + `TranslationTargetsTest` wantedClasses unit blocks; TIA
replay green.

## 2026-10-18 (kaikki import hygiene: plain display headwords, shared pos aliases, shared lemma-form rule, romanization skip — dictionary-word-purity ticket 02)

The kaikki (Wiktionary) importer loses its three impurities, reversing the
ADR 0078 display-headword carve-out (addendum added to the ADR):

- **Plain display headwords for ru/be/uk** (`laravel/app/Classes/WiktionaryParser.php`):
  the dump's stressed headword ("свобо́дный") now strips before storage
  and before `l_word` computation, joining the forms rule ADR 0078 already
  set; a headword that strips to the empty string (marks-only artifact)
  stores nothing. Definition/example prose keeps its marks.
- **One shared pos alias table** (`laravel/app/Support/PosAliases.php`,
  extracted from GoldenDictParser's inline `POS_BY_ABBREVIATION`): the raw
  kaikki pos canonicalizes through `PosAliases::canonical()` at parse time
  ("adj" lands in "adjective", aliased lines of one word merge into a
  single row), and GoldenDictParser::posSlugFor delegates to the same
  table — no second inline alias dictionary.
- **One shared lemma-form rule** (`laravel/app/Support/LemmaForms.php`):
  both importers' `flushForms` now build their rows through
  `LemmaForms::rows()` — the word's own (stripped) headword first as the
  lemma form, then the inflected forms, column-capped, stress-stripped per
  language flag, empty-after-strip guarded, `(form, word_id)`-deduped —
  so the forms table alone resolves lookups and ticket 08's single-word
  writers (cmudict, entity adoption, Filament) have one reusable entry
  point.
- **Romanization-tagged kaikki forms skip** the import (Latin
  transliterations like "četverénʹkax" never attach to Cyrillic words).

`dictionary-import.md` and `database/dictionary.md` rewritten where they
documented the carve-out (WiktionaryParser bullet, stress-marks prose,
"GoldenDict vs Wiktionary" paragraph, lookup auto-creation, `l_word` note,
`forms` lemma-form mention); `playbooks/import-dictionary-data.md` got the
pos-alias + lemma-form notes and the widened clean-rebuild/verify list
(both cited parsers changed → content refreshed, re-stamped); ADR 0078 got
the addendum.
No routes/models/commands added → `wiki:sync` run anyway, `wiki:validate`
0 warnings. Tests: new `tests/Unit/PosAliasesTest.php` (14 it-blocks with
`LemmaFormsTest`), updated unit + feature `WiktionaryParserTest` (stressed
headword plain + marked gloss, alias map, romanization skip, lemma form),
`ImportWiktionaryCommandTest` and 6 GoldenDict exact-forms assertions
moved for the lemma forms. All green via TIA replay.

## 2026-10-18 (goldendict parser correctness fixes: profile-forms strip, source-or-target segment gate, empty-after-strip guards — dictionary-word-purity ticket 01)

Review follow-ups on the ADR 0078 rework (below): the parser's
stress-strip rule had gaps where extracted text bypassed it, found by the
dictionary-word-purity review (`laravel/app/Classes/GoldenDictParser.php`):

- **Profile-extracted forms now stress-strip** like the entry's `alts`:
  the forms-selector merge appended its comma/semicolon/slash pieces raw,
  so a ru source article with a forms selector ("дома́, до́мов") stored
  stressed form twins beside the stripped alts. A form that strips to the
  empty string (a marks-only piece) is skipped, never stored as `''` —
  same guard as a headword that is nothing but combining acutes, which is
  now rejected and counted in `headwords_rejected` instead of importing
  an empty `words`/`forms` row.
- **The candidate segment strip is gated on source OR target**: it keyed
  on the target language only, so in an unprofiled ru→X article the
  leading stressed headword echo ("до́м n. house") never matched the
  already-stripped headword — the glued piece then died in the
  mixed-script guard and the real equivalent ("house") was lost with it.
  `stripsCandidateMarks` became `stripsSegmentMarks`.
- Comment-level truth: `import()`'s return docblock lists the real 13
  stats (`target_words_created` was still declared;
  `headwords_rejected`/`translation_targets_unmatched` were missing), the
  `$profile` property docblock lists the `forms` key, the
  `loadLookupMaps`/`existingTargetWordLWords` docblocks no longer describe
  the removed `target_words_created` counting, and `flushTargetWords` —
  which only resolves ids since the link-only rework — is renamed
  `resolveBatchTargetIds`.

Wiki: `domains/dictionary-import.md` documents the either-side segment
gate, the profile-forms strip and the empty-after-strip guards; ADR 0078's
decision statements already cover the intent (plain stored forms,
link-only), no ADR change. Tests: 3 new fixtures/cases in
`ImportGoldenDictCommandTest.php` (profile-forms strip, stressed echo,
marks-only headword/alt); full `--filter=ImportGoldenDict` suite 37 passed
(255 assertions), `pint --dirty` clean, `wiki:validate` 0 warnings.

## 2026-10-18 (dictionary-word-purity 12: knowledge-base closure — ADR 0079, ADR 0078 addendum, run numbers in the wiki)

Closed the spec out in the knowledge base (docs-only change, no code):

- **New ADR 0079** (`docs/adr/0079-form-of-relays-demote-to-forms-stage-link-prune.md`):
  the dictionary-word-purity decisions beyond ADR 0078 — form-of relays
  demote to forms of their base (dump-driven sense-level `form_of` signal
  via the shared `FormOfClassifier`, in-place cleanup via
  `wiktionary:demote-form-of` + `RelayDemotionMap`, ru 363,446 / en
  500,776 relay rows deleted), every word carries itself as its lemma
  form (`LemmaForms`, 1,018,396 backfilled), one stage → link → prune
  lifecycle for translations (`StagedTranslations` union-merge in,
  prune-on-link out; 126,202 candidates converted, staged JSON 91,552 →
  52,472), one resolver/alias-table/lemma-rule toolkit
  (`TranslationTargets`/`PosAliases`/`LemmaForms`) and the
  attach-to-existing creator contract (`words:normalize` as the in-place
  repair: 2,451 twins + 7,514 alias duplicates merged, 216,440 reclassed,
  9,976 absorbed). Records the operational consequences: 2G memory
  ceilings, LEAST rank folds, `word_translations` allowed to shrink
  (net −1,029 from dedupe while ~4,898 genuinely new links appeared).
- **ADR 0078 addendum extended**: the Consequences' flagged follow-up
  (the popup auto-fetch still creating target words) is recorded as
  resolved — link-only everywhere, candidates resolve through
  `TranslationTargets`, leftovers stage.
- **`domains/dictionary-import.md`**: run numbers added to the
  demote/link/normalize sections, sources gained ADR 0079 +
  `TranslationTargets` + `StagedTranslations`.
- **`database/dictionary.md`**: the stale "form-of entries are stored as
  ordinary words" note rewritten (relays demote to forms; the
  `WordFamily` pattern list stays as the read-layer filter for mixed
  entries), frequency note documents the LEAST fold between rows.
- **`playbooks/import-dictionary-data.md`**: demote/normalize sections
  moved to past tense with the landed numbers (a re-run matches nothing),
  the repair pipeline order written out in full (demote → normalize →
  link-translations → import-frequency-lists → crossword:link).

All touched concepts reviewed against the code and the ticket-11 run;
`verified: human:alex` stamps added, `generated.at` bumped.

## 2026-10-18 (dictionary-word-purity 11: the repair pipeline ran on the dev DB; both repair commands raise their own memory ceiling)

Executed the ticket-11 pipeline on the dev DB in dependency order and
verified the data gates (details in
`.scratch/dictionary-word-purity/issues/11-run-pipeline-and-data-gates.md`).
Two operational defects surfaced and are fixed here: `wiktionary:demote-form-of`
and `words:normalize` both died with `Allowed memory size of 134217728 bytes
exhausted` on real data — the demote scan at ~105k dump lines (the relay map
holds the whole language's vocabulary until the stream ends) and normalize in
the phase-3 alias fold (its group map holds every row of the folded classes,
~210k en adj/adjective rows). Both commands now raise their own
`memory_limit` to 2G + `set_time_limit(0)` at the top of `handle()` — the
`ImportRawWiktextractCommand` precedent (1G there; 2G here because these
commands hold full-language in-memory maps, not streaming batches), each with
a regression test probing the bump through the fast failure path
(`DemoteFormOfCommandTest`, `WordsNormalizeCommandTest`).

Pipeline results (dry vs real within normal documented approximations; the
destructive counts matched exactly everywhere): demote ru deleted 363,446
relay rows + 69,035 romanization junk forms (ru words 444,149 → 80,703);
demote en deleted 500,776 (en 1,523,426 → 1,022,650); normalize merged 11
stressed display words, 2,451 unknown-class twins, 7,514 alias duplicates,
reclassed 216,440, deleted 12 orphan alias class rows, backfilled 1,018,396
lemma forms, absorbed 9,976 rows; `dictionary:link-translations` converted
126,202 staged candidates into links and pruned the staged JSON from 91,552
to 52,472 words (net `word_translations` 112,585 → 111,556 — the dedupe
phases collapsed 5,927 redundant pairs while the pass added ~4,898 new);
`words:import-frequency-lists` clamped ranks onto 77,920 words directly +
82,833 via the lemma-form join; `crossword:link` found nothing unlinked after
the re-pointing (a `--retry-unmatched` pass re-attempted 533 stale stamps, 0
new — those tokens have no dictionary headword).

Gates after the run: zero combining marks in ru `words.word`/`forms.form`
(baseline 1/78); `четвереньках` only a form of `четвереньки`; zero
unknown-class twins (baseline 2,954); zero `adj` class rows (adjective: en
179,332 / ru 11,551); zero words missing their lemma form (baseline en
1,507,795 / ru 29,171); full `composer run test` green (1294 passed / 6644
assertions, 544s). Wiki: `playbooks/import-dictionary-data.md` (memory-limit
notes on both repair commands), `generated.at` bumped.

## 2026-10-18 (dictionary-word-purity 09: `words:normalize`)

The idempotent in-place repair of the already-stored dictionary rows the
importers cannot fix themselves — one command, four phases, every phase
counted in a summary table, `--dry` first-class (prints every phase count,
writes nothing). Phase 1 strips the combining stress marks from ru/be/uk
display `words.word` and `forms.form` (spec rule 1; en diacritics are
legitimate spelling and untouched): a stressed word merges into an existing
plain twin (same language + class + stripped display, lowest id survives and
absorbs the children), otherwise it strips in place; stressed forms group
per (word, stripped form) — a group whose plain twin already exists
collapses, otherwise the lowest form id survives and the other variants
dedupe (two stressed variants can strip to one plain string — the earlier
one-off repair's collision gotcha). Phase 2 merges `unknown`-class twins
into the lowest-id non-unknown same-`l_word` row (the cmudict/adoption
twins); a survivor already carrying a ˈ-marked IPA transcription is final —
the twin's transcriptions die with the merge (Kaikki wins where it has
stress data). Phase 3 folds alias word classes into their canonical class
per language (PosAliases semantics resolved by class-row slug — `adj` into
`adjective`): exact-display twins merge, twinless leftovers reclass in
place, orphan alias class rows delete last. Phase 4 backfills the lemma
form: every word carries itself (stripped display, LemmaForms conventions)
where missing — insert-new-only on `(form, word_id)`, chunked word-id ranges
with a progress bar (~1.5M rows in dev). Word merges share one set-based
machinery: pairs land in a temp mapping table, each child move is a single
SQL statement per table — definitions/etymologies/entity_words update in
place; unique-keyed children (forms, transcriptions, examples,
pronunciations, tagged_words, user_word, user_word_event,
word_translation_fetches) move through insert-select ON CONFLICT DO NOTHING
+ delete; word_translations re-point through both arms (canonical pairs,
self-pairs dropped, insert-or-ignore dedupe); staged `words.translations`
JSON union-merges (StagedTranslations); the frequency rank folds in as
LEAST with a zero guard — the cmudict/adoption rows carry the 0 "no rank"
column default (kaikki rows carry the sentinel), and folding a 0 would wipe
every survivor's rank, so only real ranks fold and never upward. Absorbed
rows delete last; case-variant same-class duplicates are deliberately NOT
merged (March/march). Tests: `WordsNormalizeCommandTest` (new, 7 / 64
assertions — seeded mini-DB per phase incl. the ˈ-IPA gate and the
both-arm/user_word/fetch-ledger moves, idempotent second run, `--dry`
writes nothing, `--chunk=2` reaching the same state through tiny chunks).
Targeted suite green, pint green, `composer run test:tia` green (1292
passed / 6639 assertions, 7 new + 1285 replayed). Wiki:
`playbooks/import-dictionary-data.md` (new "Repairing stored data in place"
runbook with the `--dry`-first guidance and expected dev-DB phase counts),
`domains/dictionary-import.md` (new "Data repair" section, sources),
`reference/commands.md` regenerated (`wiki:sync`), `generated.at` bumped;
`wiki:validate` passes. The real dev-DB run is ticket 11 (never this
ticket).

## 2026-10-18 (dictionary-word-purity 10: `wiktionary:demote-form-of`)

The dump-driven cleanup for the relay word rows the pre-ticket-03 import stored. The new command
streams one kaikki dump per language (`--lang`, default file
`kaikki/kaikki.org-dictionary-<Language name>.jsonl`, `--file` override), classifies every entry
through the shared `FormOfClassifier`, and folds the verdicts through the new pure
`App\Classes\Wiktionary\RelayDemotionMap` before anything writes: a surface with any real dump
entry stays a word (also-real subtraction, order-independent), relay lines disagreeing on the base
stay a word, chains close onto the terminal base (X→Y→Z demotes X and Y onto Z, so no relay
re-points at a row its own run deletes), cycles demote nobody and stay valid link targets. The
apply is set-based per `--chunk` (default 25000 relay keys, one transaction each; `--dry` prints
every phase count and writes nothing — run it first): translation links re-point from relay rows
onto bases through both `word_translations` arms (canonical pairs, insert-or-ignore dedupe,
self-pairs dropped, later-chunk relays resolved through the full map so their pairs survive both
deletions), `entity_words.word_id` re-points (the token `l_word` keeps the surface the entity text
carries), staged `words.translations` JSON union-merges onto the base, the relay surface attaches
as a base form (upsert on `(form, word_id)` — a base that already claims it keeps one row), the
relay's frequency rank folds into the base as LEAST (best rank wins; the unranked sentinel
1,100,000 never demotes a ranked base — user-approved deviation from the ticket's "max/GREATEST"
wording, which would have flipped the flagship base `четвереньки` from rank 11,314 to unranked;
matches ADR 0070's never-raise-a-rank fold), then the relay rows delete (relay glosses and
self-forms die with the row — pure pointers). Romanization-tagged junk forms (Latin on ru/be/uk
words) are collected from the same stream and deleted by exact (entry `l_word`, form `l_word`)
pairs; the phase skips non-stress-mark languages. Bases missing from the DB keep their relays as
words — counted, sampled, never deleted; re-runs match nothing (idempotent). Deletes by explicit
ids from the dump map — never a wipe. Tests: `RelayDemotionMapTest` (new, 8 — subtraction,
conflicts, chains, two- and three-entry cycles, self-base gate, missing-base passthrough),
`DemoteFormOfCommandTest` (new, 5 / 39 assertions — seeded mini-DB plus the
`tests/fixtures/kaikki-demote-form-of.jsonl` mini-dump: dry writes nothing, the demote end state
including the flagship `четвереньках` case with `--chunk=2` forcing the cross-chunk link and
staged-JSON paths, idempotent re-run, rejects unknown language and missing file). Targeted suites
green (8 + 5), pint green, `composer run test:tia` green (1285 passed / 6575 assertions, 1285
replayed). Wiki: `playbooks/import-dictionary-data.md` (new "Cleaning imported relay rows"
runbook with the `--dry`-first guidance), `domains/dictionary-import.md` (the planned-ticket
mention replaced by the implemented command + map semantics, sources), `reference/commands.md`
regenerated (`wiki:sync`), `generated.at` bumped; `wiki:validate` passes. The real dev-DB run is
ticket 11 (never this ticket).

## 2026-10-18 (dictionary-word-purity 08: duplicate prevention at the remaining creators)

The two creators still minting `unknown`-class twins of classed rows (uniqueness is
`(word, language_id, word_class_id)` — 2,954 twins in the dev data) now resolve against the
language's existing `l_word`s and only create when nothing matches. `dictionary:import-cmudict`
already attached transcriptions to existing same-`l_word` rows; its creation path now goes through
the shared conventions — plain display word (`StressMarks` strip on the shared path, a no-op for
en), `App\Support\WordLookupKey` key and the `App\Support\LemmaForms` lemma form, so a
cmudict-created word carries itself as its form like every imported word. `EntityWordAdoption`
replaced the raw-token `firstOrCreate` (which matched on the surface string and class, so a token
whose `l_word` the dictionary already carried minted a twin): adoption attaches to the EXISTING
same-`l_word` row — first non-unknown-class row in id order, else the lowest id — via one batched
candidates query per 500-token chunk (the pass runs inside `crossword:refresh`); a genuinely new
token creates one row with a plain display word (combining stress marks stripped off the raw surface
for ru/be/uk, casing kept) and its lemma form, and a marks-only token stays stamped instead of
storing a junk row. Filament joins the contract: the Word page's "Create word & link" strips the
display word for ru/be/uk (a stressed input reuses the existing plain row), normalizes `l_word`
through `WordLookupKey` instead of a bare `mb_strtolower`, adds the lemma form on create, and its
word search matches the normalized key; the Forms relation manager strips the display form for the
stress-mark languages and autofills a blank `l_word` through the same key (create + edit). Tests:
`CmudictImportTest` (attach-to-existing with no twin, lemma form on create, re-run idempotent),
`AdoptEntityWordsTest` (lemma form, existing-row adoption preferring the classed row over a
lower-id unknown, plain ru display + lemma form), `Filament/WordTranslationsRelationManagerTest`
(normalized key + lemma form; stressed input collapses into the plain row),
`Filament/WordFormsRelationManagerTest` (new file — normalized key autofill, ru form strip, en
create/edit). Targeted suites green (20 + 36), `composer run test:tia` green (1272). Wiki:
`domains/dictionary-import.md` (new "Creator hygiene" section + sources), `domains/crossword.md`
and `domains/interactive-words.md` (adoption attach-don't-twin semantics), `generated.at` bumped;
no routes/models/commands signatures touched, no `wiki:sync` needed; `wiki:validate` passes.

## 2026-10-18 (dictionary-word-purity 07: dictionary:link-translations — the single staged-link pass)

`wiktionary:link-translations` (renamed **`dictionary:link-translations`** — no alias, all callers
and docs updated) was the only re-reader of the staged `words.translations` JSON but matched weaker
than the goldendict resolver (class-strict single lookup, no same-class preference, no phrase
preference, no any-class fallback, one query per candidate), never pruned what it linked (91,552
staged rows and growing), and warned per unmatched candidate — millions of lines at real scale. It
is now the single staged-link pass: per staged word it builds the writers' wanted-class map
(multi-word candidate → target's `phrase` class, single-word → the source word's class slug mapped
into the target language, unmappable → null = l_word alone) and resolves through the shared
`App\Support\TranslationTargets::forLanguage` (same-class → phrase → any-class → lowest id; one
query per word instead of per candidate). Pairing keys go through `App\Support\WordLookupKey::normalize`
(replacing the private `normalizeTargetWord`). **Prune-on-link**: every candidate that resolves is
removed from the word's JSON via `App\Support\StagedTranslations::prune` (exact stored strings —
the importer-staged goldendict JSON and the job-staged auto-fetch JSON share the contract), an
emptied JSON stores null, the row saves only when the JSON actually changed, so re-runs are
idempotent and the Total-staged column shrinks; unmatched candidates stay staged for a later pass.
Unmatched output is a per-direction count plus a capped sample (first 10 as "source → candidate",
one per table line — long cells wrap mid-string at the terminal width) in the summary table
(Linked / Unmatched / Words pruned / Total staged columns); the per-candidate warn is gone. The
`statement_timeout` (30s) and stuck-`delete from "words"` transaction-kill guards are kept.
`ImportRawWiktextractCommand` calls the new signature (`--no-link` help + messages);
`ImportWiktionaryCommand`'s description and the `WordTranslationProvider` docblock (which named the
removed private method) updated; ADR 0029 carries a rename note. Tests: `LinkTranslationsCommandTest`
rewritten for the new signature and contract (16 tests: resolver fallbacks, phrase class, lowest-id
tie, prune-keeps-unmatched, empty→null, exact-string prune of a stressed legacy candidate, re-run
idempotency, capped sample without per-candidate warns); `ImportRawWiktextractCommandTest`'s
end-to-end test now asserts the prune contract (fully-matched words store null). Full
`composer run test:tia` green (1264 passed). Wiki: `reference/commands.md` regenerated by
`wiki:sync` (rename), `playbooks/import-dictionary-data.md` (runbook step 4 + source title),
`domains/dictionary-import.md` (command bullet + linking-differs paragraph + source title),
`database/dictionary.md` (words.translations flow bullet), `generated.at` bumped; `wiki:validate`
passes. Manual command — run after populating all languages.

## 2026-10-18 (dictionary-word-purity 06: popup auto-fetch is link-only — no word rows)

`FetchWordTranslations` used to CREATE its target-language word rows — including `phrase`-class rows
with no token cap (clause-length machine translations qualified) and a `mb_strtolower`-only l_word —
the exact "phrases from translations" pattern ADR 0078 bans. The job is now link-only: candidates
normalize at the JOB layer first (trim + `App\Support\StressMarks` strip for ru/be/uk targets — the
providers strip too, but the job no longer depends on a future provider keeping that promise;
empty-after-strip, overlong and sentence-punctuation candidates dropped), resolve against the target
language's EXISTING words through the shared `App\Support\TranslationTargets` resolver with the
parser's wanted-class rule (`App\Support\WordLookupKey::normalize` keys; multi-word candidate →
`phrase` class, single-word → candidate pos class else the source word's class, missing class →
null = match by l_word alone), and matching candidates become `WordTranslation::link` pairs.
Unmatched candidates stage into the source word's `translations` JSON through the shared
`App\Support\StagedTranslations` merge for the later link pass (ticket 07). The `phraseClassId`
auto-creation and every `Word::firstOrCreate` are gone — `word_classes` and target-language `words`
counts are untouched by the job (the invariant test asserts the ru count is unchanged).
The `word_translation_fetches` ledger keeps its exclusion semantics (empty = never re-checked;
succeeded also never re-fetches — staged candidates wait for the link pass, not a re-query; failed
retries unchanged) and records the outcome in new `linked_count`/`staged_count` smallint columns
(additive migration `2026_10_18_000002`, applied to the dev DB). Callers
(`WordTranslationFetchService::dispatchIfEligible`, `EntityWordAdoption::dispatchFetches`,
`WordController::maybeFetchTranslations`) needed no changes — their gates read links + ledger
status only. `tests/Feature/WordTranslationFetchTest.php` rewritten for link-only semantics (17
tests: no-creation invariant, defensive strip via a mocked resolver, staging merge/re-merge,
phrase-class preference, google fallback under class mismatch, exclusion on unusable-only
candidates, no-refetch-after-staging, cooldown), full `composer run test:tia` green (1257 passed).
Wiki: `domains/interactive-words.md` ("What it writes" + ledger bullets, description, sources),
`domains/dictionary-import.md` (ADR 0059 section no longer claims phrase auto-creation),
`database/dictionary.md` (ledger row + words.translations staging note + auto-fetch note),
`generated.at` bumped; `wiki:sync` regenerated `reference/models.md` (new fillable/casts);
`wiki:validate` passes.

## 2026-10-18 (dictionary-word-purity 03: kaikki form-of relays demote to forms, not words)

Kaikki marks inflected variants as standalone entries whose every sense carries the sense-level
`form_of` list (+ the `form-of` tag) — `четвереньках: prepositional of четвере́ньки` — and
`WiktionaryParser` ignored the signal entirely, importing ~379k/444k ru and ~974k/1.5M en relay
entries as full word rows with relay glosses and self-referential form rows. Now `parseLine`
classifies every entry through the new parser-agnostic shared helper
`App\Classes\Wiktionary\FormOfClassifier` (entry-shape in → `{relay, base}` out; also reusable by
the planned demote command): relay iff every sense carries `form_of[0].word` or the `form-of` tag,
mixed entries (some relay senses + real glosses) stay words, an entry without senses stays a word
(no sense-level signal), a base that collapses into the entry's own lookup key would demote the
entry onto itself (stays a word), and a trivially present entry-level `form_of` is honored. A relay
record carries empty definitions/forms/sounds/translations/etymology and a `relay_base` (stress-
stripped for ru/be/uk); `flushBatch` partitions it out of the word upsert and `demoteRelays`
resolves the base by `l_word` with the shared class preference (`App\Support\TranslationTargets`,
same-class row → lowest id; unknown pos skips the preference), creates a missing base as a proxy
dictionary headword (relay's pos class, no staged translations, lemma form through the shared
`App\Support\LemmaForms`), and attaches the relay headword as the base's form via the same
`LemmaForms` conventions — never a word row. Same-key line merges follow union-of-senses semantics:
relay+real → mixed word, relay+relay demote only onto an agreeing (normalized-equal) base. Import
stats gained `relays_demoted` / `relay_bases_created`, surfaced in the `wiktionary:import` summary
table. Pre-existing relay word rows are ticket 10's demote command; the `WordFamily` read-layer
relay-gloss defense stays until then. `wiki/domains/dictionary-import.md` updated (parser bullet +
frontmatter description/tags/sources), `generated.at` bumped. No routes/models/commands added (the
command's output table changed, not its signature) — `wiki:sync` run, no-op.

## 2026-10-18 (dictionary-word-purity 04: word_id-leading indexes on every words child table)

The per-word access path into the dictionary's child tables was unindexed: `forms` (3.02M rows) and
`transcriptions` (922k) only had `word_id` *buried* behind a leading text column (`uk_forms_form_word_id
(form, word_id)`, `uk_transcriptions_triple (transcription, word_id, transcription_type_id)`), so per-word
joins, importer recount batches and FK cascade deletes seq-scanned — the root cause of the 17-minute wipe
crawl. Migration `2026_10_18_000001_add_word_id_indexes_to_forms_transcriptions_and_word_children.php` adds
`idx_<table>_word_id` btrees (naming mirrors `idx_definitions_word_id` from 2026-09-16) on the six audit
hits: the ticket's `forms`/`transcriptions`/`user_word` plus the rest of the word-child audit surface —
`examples`, `pronunciations`, `user_word_event` (all `ON DELETE CASCADE` off `words`); `definitions`,
`etymologies`, `entity_words`, `tagged_words` `(word_id, tag_id)` and `word_translation_fetches`
`(word_id, target_language_id)` already led with `word_id` and were left alone (`word_families` is a
read-layer class, not a table). Dev-DB `EXPLAIN ANALYZE` of `SELECT * FROM forms WHERE word_id = 1993864`
flipped from seq scan to `Index Scan using idx_forms_word_id`, 0.159 ms / 3+8 buffers. Coverage pinned by
`tests/Feature/WordIdLeadingIndexesTest.php` (red → green: asserts via `Schema::getIndexes` that each
index exists AND leads with `word_id`, so a future column reorder in the migration fails the suite).
`database/dictionary.md` gained the indexes bullet + migration source, `generated.at` bumped;
no routes/models/commands touched, no `wiki:sync` needed.

## 2026-10-17 (goldendict import rework: plain stress-free words, phrase only from headwords, link-only translations — ADR 0078)

The first real bilingual imports left three junk classes in `words` —
33,677 of 54,489 ru rows carried combining stress acutes in the display
form (stressed twins of the same word multiplying per import), 21,980 ru
rows sat in the invented `phrase` class mostly via multi-word translation
candidates (including comma-split name lists like "Кулешова, Роза
Алексеевна" and clause leftovers like "жировые складки на талии"), and
40,398 of 54,489 ru words were bare translation-created stubs (96.9% of
all `word_translations` pairs pointed at one). Reworked the GoldenDict
importer (ADR
[0078](../docs/adr/0078-goldendict-plain-words-link-only-translations.md),
amending ADR 0059's scope; Wiktionary import and `FetchWordTranslations`
deliberately untouched):

- `laravel/app/Classes/GoldenDictParser.php` — (1) for the StressMarks
  languages (ru/be/uk) the stored display `word`/`form` and every staged
  translation candidate are stripped of combining acutes at parse time:
  "приве́т" stores as "привет", stressed variants collapse into the plain
  row, definition prose keeps its marks; (2) a multi-word headword with
  sentence punctuation or more than 3 tokens is rejected (`headwords_rejected`
  stat) — dictionary phrases like "give up"/"до свидания" still import as
  `phrase`; translation candidates determine no word class at all; (3)
  bilingual mode is **link-only**: candidates resolve by `l_word` against
  the target language's EXISTING rows (same class preferred, multi-word
  prefers `phrase`, else lowest id), never create rows — `flushTargetWords`
  shrank to a resolve, `ensureTargetWordClass`/`candidateClassSlug`/the
  dry would-be-created tally are gone, `target_words_created` is replaced
  by `translation_targets_unmatched`, unmatched candidates stay staged in
  `words.translations` for a later re-run; (4) new `forms` profile
  selector kind: comma/semicolon/slash-split form lists merging with the
  entry's `alts`.
- `laravel/app/Classes/WiktionaryParser.php` — (5, data follow-up) the
  kaikki raw dump carries the stressed ru variants in the inflected-form
  lists ("до́ма" for "дом"): the parser now strips ru/be/uk display forms
  through the same StressMarks rule (headwords stay as the lemma field
  delivers them — unstressed), and the re-imported ru forms (1,401k
  stressed rows) were repaired in place with a one-off strip+dedupe pass.
- `laravel/app/Console/Commands/ImportGoldenDictCommand.php` — `forms`
  joined `PROFILE_SELECTOR_KEYS`; stats rows swapped (Target words
  created → Unmatched candidates, + Headwords rejected); `--target-lang`
  help text updated.
- `laravel/config/goldendict.php` — `forms` semantics documented,
  `test-forms` test profile added.
- Tests: 34 passing in `ImportGoldenDictCommandTest` — bilingual
  expectations rewritten to the link-only semantics (pre-created target
  rows, class-preference + decoy assertions, unmatched/staged counting,
  dry-run honesty against the DB) and three new tests with fixtures
  (headword junk guards, ru stress-strip collapse, forms selector).
- Data: en+ru wiped (the `--fresh` chunked-cascade wipe crawled on missing
  child-table indexes and was finished with a set-based child-first delete)
  and re-imported through the reworked pipeline: kaikki raw dump
  (1,473,492 en + 431,933 ru words — several times the old per-language
  imports), 79,506 en↔ru links from the kaikki pass, cmudict IPA
  (409,794 en transcriptions total), frequency ranks (331,484 en /
  39,968 ru ranked), curated band lists, then the bilingual goldendict
  runs — Mostitsky ru→en (12,314 clean words, 1,927 junk headwords
  rejected, 4,006 links to existing en words, 6,931 candidates staged
  only) and Oxford en→ru (42,260 clean words, 366 rejected, 29,073 new
  links, 21,604 staged; the dry run's 43,550 estimate minus ~14.5k pairs
  the kaikki pass had already linked). Verification: ru words and forms
  carry no combining acutes, phrase rows only from dictionary headwords,
  zero target rows created by the bilingual imports. Popup auto-fetch
  still creates target words (`FetchWordTranslations`) — flagged for a
  follow-up decision.
- `wiki/domains/dictionary-import.md`, `wiki/database/dictionary.md`,
  `wiki/playbooks/import-dictionary-data.md` updated (`generated.at`
  bumped); no routes/models/commands added, `wiki:sync` run for the
  reference pages; `wiki:validate` and `test:tia` green.

## 2026-10-16 (Mostitsky eXtro (Ru-En) imported — second real extraction profile, JSONL pre-filter)

The freemdict `Mostitsky eXtro (Ru-En)` dictionary (Igor Mostitsky's
anomalous-phenomena dictionary, DSL in a 7z) is in the database:
14,241 source words, ~58k definitions, 3,853 English target words,
11,746 `word_translations` links (`goldendict:import --lang=ru
--target-lang=en --profile=mostitsky-ru-en`; dry-run → testing-DB smoke
→ detached real run per the playbook). Extraction went through a new
**`mostitsky-ru-en`** profile in `config/goldendict.php` — the second
real-world profile after `oxford-en-ru`, and a new selector technique:
pyglossary renders the DSL `[trn]` equivalents as plain
`padding-left:1em` paragraphs indistinguishable by shape from the
`[com]` commentary, so the translations selector hooks the only
remaining property — the absence of Cyrillic — via a `translate()`
strips-the-Cyrillic-alphabet equality test; configuring it also vouches
translations, which killed the heuristic's mined commentary candidates
(1154 → 209 on the first 500 entries, target words 789 → 81).
`span.sec` (► source links, «см. тж», GoldenDict artifacts) is stripped;
the bold headword echo deliberately stays in the definitions (that
dictionary's `<b>` carries real content elsewhere — a blanket strip
would hit 1,167 articles), and its `[p]` tags are subject domains
(`уфол.`, `астрон.`), not word classes — no `pos` selector. A
conservative host-side JSONL pre-filter (documented in the playbook)
dropped 73 non-word records (`[N]` citation stubs, `**`/`***` info
cards, `_` navigation pages — eyeballed first: bare-number headwords
like `1947` are real entries and stayed) and unquoted 5 DSL-syntax
quoted headwords; the first filter version dropped the trailing newline
on rewritten records and silently concatenated 5 pairs — caught by a
`wc -l` vs record count mismatch, repaired with a raw_decode
re-serialization pass (the JSONL is one-record-per-line again, all
14,241 decode). Playbook + domain doc updated with the profile, the
content-language selector trick and the pre-filter step (`generated.at`
bumped); no routes/models/commands touched, no `wiki:sync` needed;
`wiki:validate` and `test:tia` green.

## 2026-10-15 (goldendict import quality fixes: no sentence words, no stressed duplicates, real word classes, pos/strip selectors)

The Oxford En-Ru import's output triaged three data-quality complaints —
sentences imported as Russian words ("cars zoomed past маши́ны
проноси́лись ми́мо" as a single target word), stressed duplicates beside
existing unstressed rows ("маши́ны" created although "машины" existed),
and every word landing in the `unknown` class — to their parser roots and
fixed them, then wiped the first import's rows (95,352 words deleted by
the import's created_at window after verifying the window held nothing
else — all unranked-frequency, all phrase/unknown classes) and re-imported
clean (**84 s**): 66,481 candidates (was 93,302), 35,630 new ru words (was
53,819), 64,086 links, and real classes (en noun/adjective/verb…;
`unknown` down to a 1,402-article residue of unmappable POS labels).

- `laravel/app/Classes/GoldenDictParser.php` — four fixes: (1) candidate
  guards reject mixed Latin+Cyrillic pieces (glued example sentences) and
  pieces over 4 whitespace tokens (clause-sized sentences; ≤4-token
  phrases stay, the ADR 0059 shape); (2) two new profile selector kinds —
  `pos` (matched texts through `posSlugFor`, length guard 12→24 so
  "transitive verb" maps, first mappable text overrides the heuristic pos
  hints below the multi-word→phrase precedence) and `strip` (matched
  nodes removed from the article DOM before any extraction, never a
  match/miss signal); (3) a configured translations selector **vouches**
  for where equivalents live: a miss yields no candidates for the article
  instead of the raw-block-text fallback (the fallback was what mined
  example scaffolding into junk target words; definitions/transcriptions
  keep their fallback); (4) target-word resolution is **by `l_word`** — a
  candidate whose normalized key the target language already carries
  under any class reuses that row (same class preferred, else lowest id)
  for its link instead of creating a stressed/different-class duplicate;
  only unseen l_words insert; the dry-run tally applies the same rule.
- `laravel/app/Console/Commands/ImportGoldenDictCommand.php` — the two
  new selector keys join `PROFILE_SELECTOR_KEYS` validation.
- `laravel/config/goldendict.php` — `oxford-en-ru` gains `pos` →
  `partOfSpeech` divs and `strip` → `exampleGroup` blocks.
- Tests: 5 new (pos selector + fallback, strip before every extraction,
  candidate guards with a kept 4-token phrase, l_word reuse of an
  existing target word, l_word batch dedupe + idempotent zeros); two
  tests re-pointed from the retired raw-text fallback to the vouching
  rule (`falls back per selector`, `empty text as a miss` — definitions
  and transcriptions still demonstrate the fallback there).
- Dev-data cleanup beyond the re-import: 424 pre-existing en rows had
  junk strings merged into their `words.translations` staging by the
  first import (staging survives word deletes on rows it merged into);
  524 mixed-script/over-long strings filtered out.

## 2026-10-15 (first real dictionary import: Oxford Russian Dictionary En-Ru — oxford-en-ru profile, UTF-8-safe candidate trim, TIA gitignore)

The first real GoldenDict dictionary went through the pipeline end to end
(the "no real freemdict dictionary was available" placeholder is now
retired): the Oxford Russian Dictionary (En-Ru) MDX, 43,164 articles —
converted host-side with `goldendict_convert.py` (4.5 s) and imported with
`goldendict:import --lang=en --target-lang=ru --profile=oxford-en-ru`
(**59 s** wall: 42,134 source words, 62,494 definitions, 66,490
transcriptions, 53,819 new Russian target words, 89,864 canonical
`word_translations` links on top of the existing Wiktionary data).

- `laravel/config/goldendict.php` — new `oxford-en-ru` extraction profile,
  the first real one: transcriptions from the direct text nodes of
  `phoneticText` divs/spans (the sound-play ▶ glyphs live in `<a>`
  children, so direct-text selection yields clean "BrE dɒɡ"/"AmE dɔɡ"
  strings; a `[normalize-space(.) != ","]` predicate drops the separator
  text node between the BrE and AmE segments — without it every article
  grew a stray "," transcription); translations from
  `targetLangTranslation` spans excluding those inside `exampleGroup`
  blocks (the phrase-book example translations would flood the target
  words with multi-word junk); definitions from `sense-1`/`sense-2`
  numbered sense units. Baseline dry-run comparison (500 entries):
  transcriptions 1 → 955, candidates 2,541 → 1,573 (cleaner), definitions
  3,294 → 1,190 (sense units, not raw blocks).
- `laravel/app/Classes/GoldenDictParser.php` — bug fix found by the real
  dictionary: `CANDIDATE_TRIM` as a byte-wise `trim()` mask cut Cyrillic
  letters in half (the quote marks "«»„“”" share UTF-8 bytes with letters
  — "л" ends in the same 0xBB as "»", "р" the same 0x80 as "…"), the
  corrupted candidates died in Postgres with `invalid byte sequence for
  encoding "UTF8": 0xd0` and the bilingual import crashed. The trim now
  runs through a `/u` regex character class (`trimCandidateEnds()`).
  Regression test `trims quote marks off Cyrillic candidates without
  cutting letters in half` + `tests/Fixtures/goldendict-cyrillic-trim.jsonl`.
- `laravel/.gitignore` — `/goldendict/` added (dictionary sources are
  already ignored in the outer repo, but the Pest TIA git repo is rooted
  at `laravel/`, and its ContentHash `file_get_contents`es every untracked
  changed file → the 123 MB `.7z` blew the 128M CLI memory limit before
  any test ran).
- `wiki/domains/dictionary-import.md`, `wiki/playbooks/import-dictionary-data.md`
  — the profile bullet now cites `oxford-en-ru` as the working example,
  the bilingual-mode bullet documents the UTF-8-safe trim constraint, the
  playbook's profile-authoring section points at the real profile.
  `generated.at` bumped on both; `wiki:validate` green.

## 2026-10-15 (anki-popup-refresh 06 — knowledge-base closure: updates in place, per-language Anki targets, popup action toolbar)

Closure of the anki-popup-refresh feature (code in its tickets 01–05:
per-language Anki settings over the legacy global base; per-language note
types "ExtApp Vocabulary ⋅ <code>" with update-on-duplicate sends and a
memoized on-open duplicate lookup; the popup footer replaced by an icon
action toolbar as the popup's first row; the Anki tab's language
selector; popup detect-on-open with an update glyph and "Updated in
Anki."). This ticket lands only the knowledge base; zero feature code.

- `docs/adr/0077-browser-side-ankiconnect.md` — new "Addendum v3
  (2026-10-15): anki-popup-refresh — re-sends update in place,
  per-language targets, detect on open", five points: (A) decision 5
  reversed for the app's own notes — the unchanged deck+Word+Language
  search now ends in an in-place refresh (all thirteen values rebuilt,
  tags replaced, the note addressed by id alone so deck and review
  scheduling cannot move) when the hit is one of our own types, while a
  foreign-model-only match still skips and `allowDuplicate: false` stays
  as the race net (explicitly supersedes addendum D's closing sentence
  and addendum v2's "refreshed only after Anki-side deletion"); (B) the
  update is a full value refresh addressed by id; (C) wire shapes
  verified against the plugin source — the combined action is
  `updateNote` (singular, `{note: {id, fields?, tags?}}`, supplied tags
  REPLACE the set; no plural `updateNotes` exists in any release), the
  fallback is `updateNoteFields` + a real tag replacement
  (`getNoteTags`→`removeTags`→`addTags`), entered only on
  "unsupported action"-style errors; (D) the per-language decisions —
  the sparse `languages` settings map over the legacy global base
  (per-language → legacy → defaults, `default` computed server-side and
  never persistable), per-language note types `ExtApp Vocabulary ⋅
  <code>`, the note type following the RESOLVED entry, the unsuffixed
  legacy type never rewritten but its notes updatable, ensure memo per
  endpoint+deck+note type; (E) detect-on-open — a findNotes query that
  never ensures, resolves null on failure, memoizes per page session
  (failures un-memoized), seeded by send results.
- `GLOSSARY.md` — new terms Deck, Per-language Anki settings, Language
  note type, Update send; Send to Anki / Note type / Sample card
  refreshed for the add-or-refresh semantics, the per-language variants,
  and the selected language (glossary-only, implementation-free).
- `wiki/domains/anki-export.md` — description + source titles updated
  (per-language, updates-in-place, `ankiWordConfig.js` and the
  note-update wrappers sourced); "What it does" states add-or-refresh;
  flow step 2's vitest list gains the new specs; step 4 rewritten —
  find-before-add now adds or updates in place, per-word target
  resolution via `ankiWordSyncConfig` (note type follows the resolved
  entry), memo per endpoint+deck+note type, detect-on-open pointer to
  Interactive Words, and the legacy-notes story flipped (a re-send now
  brings pre-rework/pre-v2 notes current; only a pre-v2 twelve-field
  TYPE needs the taught migration); the "sends only add" boundary bullet
  replaced.
- `wiki/domains/interactive-words.md` — the footer is gone from the
  prose: description, "What it does", the `word-popup-*` class list
  (`-footer` → `-actions`, `-anki-updated` added), and the Save-to-Anki
  section rewritten as "Anki send from the popup toolbar" — icon states
  + detect-on-open (memoized, never ensures, silent fail → add form,
  cancelled/dataRef stale guards), per-language resolution via
  `ankiWordSyncConfig`, duplicates-become-updates ("Updated in Anki.";
  `word.anki_already` kept for foreign-model matches only), status lines
  beneath the toolbar; the ADR 0046 bullet now says the opt-out hides
  the toolbar's two progress icons.
- `wiki/domains/profile.md` — description names per-language Anki
  settings; the legacy-base sentence corrected (read-fallback, not what
  the popup resolves first); the popup cross-reference paragraph names
  `ankiWordSyncConfig` and the toolbar send; Popups-tab preview wording
  (toolbar icons render disabled); "progress buttons" → "progress
  actions". All three concepts' stamps bumped.
- Checks: `wiki:validate` → conformant, 9 warnings, all pre-existing and
  unrelated (none on the touched concepts; ticket 05's expected
  anki-export staleness warning cleared by this rewrite). `wiki:sync`
  run to verify: no reference drift — stamps only (no routes/models/
  commands changed). No test reads the docs (grep: only code comments
  mention the ADR).

Review fixes pass (anki-popup-refresh 07, same day) — the two-axis
review's findings, folded into this closure's facts rather than a new
concept: `UiSettingsController` now strips incoming top-level
`anki.deck`/`anki.fields` (the request has no rules for them anymore) and
grafts the previously saved legacy base back onto the wholesale-replaced
section, so a tab save can never rewrite the read-only base — the tab's
autosave payload (`lib/ankiTabState.js`) correspondingly carries the
connection + languages map only, and without enabled languages the tab
falls back to the resolved `default` entry as a read-only view (deck input
+ toggles disabled); note ownership in `lib/ankiSync.js` is now "any note
type with the app prefix" (`ANKI_NOTE_TYPE` + the separator exported from
`lib/ankiTemplate.js` — a language disabled since the note was sent still
gets its "⋅ <code>" note updated in place); the popup's `word.anki_mismatch`
interpolates the actual `:model` (per-language types can mismatch too);
and the tab's re-sent sample reports the new
`profile.anki_sample_updated` ("Sample card updated in \":deck\".") instead
of the calm "nothing was duplicated" line this entry and ticket 04's note
describe. `docs/adr/0077` addendum v3 points A and D corrected
accordingly; both concepts' stamps bumped.

## 2026-10-15 (anki-popup-refresh 04 — Anki tab: language selector + per-language editing)

Fourth ticket of the word-popup refresh (spec
`.scratch/anki-popup-refresh/spec.md`): the profile Anki tab's card-content
side becomes per-language — deck, the thirteen toggles, the template
preview, and the sync/sample targets all follow one language selector,
while the connection block stays global.

- `laravel/resources/js/lib/ankiTabState.js` (new) — the tab's per-language
  editing state as pure functions over the resolved `ankiSettings` prop and
  the page's enabled-language codes: whole-section state shaped exactly like
  the persisted `anki` section (connection + legacy base + complete
  languages map, so every autosave PATCH round-trips the WHOLE section — a
  languages-only payload would wipe the legacy base via wholesale section
  replace; the never-persistable `default` entry never enters the state),
  entry accessors + scoped deck/toggle setters, the selection rule (native
  language when enabled, else the first enabled code, else none — and with
  none selected the tab edits the legacy base), the sync-layer config
  (`ankiTabSyncConfig`: selected deck/fields + `noteType` from ticket 02's
  `noteTypeForLanguage`, the key omitted without a selection), and the
  sample payload stamped with the selected language (one override point —
  the Language field AND the duplicate search both derive from the
  payload's `language_code`). Pinned executable by the new
  `ankiTabState.test.js` (15 tests).
- `laravel/resources/js/Pages/Profile/AnkiSettings.jsx` — the selector
  (enabled languages from the page's `languages` prop, labels via
  `native_name ?? name`) + the deck input now lead the card-content section
  full-width; the deck left the connection block (URL/port/test alone up
  top); toggles, preview, sample build and both write actions read the
  selected entry; the sync/sample status lines report the active deck; the
  mismatch panel passes the error's `modelName` into the copy. Tab source
  pins added to `AnkiSettingsMirrorTest` (module wiring + the quoted
  `'default'` literal banned from the tab).
- `laravel/app/Http/Controllers/ProfileController.php` — the shared
  `languages` prop gains `code` (the selector and the per-language map/note
  type are code-keyed); `Edit.jsx` passes the prop through to the tab.
- Strings — `profile.anki_language` (en "Language" / ru "Язык" / be "Мова")
  added and seeded (`UiStringSeeder` re-run); the two mismatch strings now
  interpolate the mismatched model via `:model` (the hardcoded "ExtApp
  Vocabulary" would mislead against a per-language type), and
  `profile.anki_sync_description` no longer names the base type — it says
  "the selected language's deck and note type".
- Tests — ProfileTest pins `languages.N.code`; UiStringSeederTest pins the
  selector string in en/ru/be; vitest 177/177; Pest (touched suites + TIA)
  green.
- Wiki — `domains/profile.md` (Anki tab: selector paragraph, global
  connection, per-language card content + Send to Anki) and
  `domains/anki-export.md` (flow steps 1 + 3) updated, stamps bumped. Two
  adjacent stale facts were corrected in passing because my edits touch
  their sentences: the ensure memo key is endpoint+deck+NOTE TYPE (ticket
  02's change), and a found duplicate of one of the app's own note types is
  refreshed in place (ticket 02's reversal — the tab still reports it with
  the calm "nothing was duplicated" line). The full ADR 0077 addendum v3 /
  decision-record closure remains ticket 06's; the popup-side per-language
  wiring (send/detect config from `ankiSettings.languages`) remains ticket
  05's.

## 2026-10-15 (anki-popup-refresh 01 — per-language Anki settings: storage, resolution, validation)

First ticket of the word-popup refresh (spec
`.scratch/anki-popup-refresh/spec.md`): deck + field toggles become
per-language server-side, while every existing consumer keeps reading the
unchanged legacy shape.

- `laravel/app/Support/AnkiSettings.php` — `for()` now also returns a
  resolved `languages` map: one `{deck, fields}` entry per
  `Language::enabled()` code (in `sort_order`, then code) plus a `default`
  entry for words with an unknown/missing `language_code`, each walking
  per-language → legacy global `deck`/`fields` → all-on defaults — the
  agreed "global values become defaults" migration, so legacy-only users
  resolve identically to before. Top-level `url`/`port`/`deck`/`fields`
  unchanged; `default` (new `DEFAULT_LANGUAGE_KEY`) is never persistable.
- `laravel/app/Http/Requests/UpdateUiSettingsRequest.php` — the `anki`
  section gains `languages` rules built per enabled code (deck nullable
  string max 100; the canonical thirteen boolean field rules), so
  `validated()` strips whole non-enabled entries and unknown keys inside an
  entry — same pattern as every other section key. Controller untouched
  (wholesale section replace as before).
- Tests — `UiSettingsTest`: per-language round-trip, other-sections
  intact, junk rejection (`anki.languages.en.deck` length,
  `anki.languages.en.fields.*` non-boolean), non-enabled/absent codes and
  unknown keys stripped; `ProfileTest`: prop carries every enabled
  language + `default` (exact key-set pin), legacy-base fallback chain,
  per-language-over-legacy preference. `AnkiSettingsMirrorTest` untouched
  and green — this ticket changes no JS-mirrored constant (the note-type
  naming twin is ticket 02's); vitest 131/131.
- Wiki — `domains/anki-export.md` (flow step 1) and `domains/profile.md`
  (Anki tab) updated for the per-language storage/validation/resolution;
  stamps bumped. The tab's language selector (ticket 04), popup wiring
  (ticket 05) and the ADR addendum closure (ticket 06) are pending, so the
  concepts note the interim state explicitly.

## 2026-10-15 (anki-popup-card 08 — Route A review fixes: glossary count, group-coverage pin, small tidy)

The two-axis review of the whole popup-card feature (`git diff
625a93a...HEAD`) raised three hard items and four smells; this ticket
closes the hards and takes the cheap tides. No behavior changes — the
card's emitted HTML/CSS and the tab's rendering are byte-identical (the
behavioral vitest pins prove it: ankiTemplate.test.js 45/45 untouched).

- `laravel/tests/Unit/AnkiSettingsMirrorTest.php` — new group-coverage
  pin closing the gap that let `body` ship checkbox-less in ticket 06:
  it regex-extracts `FIELD_GROUPS` from the Anki tab's JSX source on
  disk (the house mirror-pin pattern — both sibling pins live PHP-side,
  and a second Components-side vitest file would need its own ADR
  blessing) and asserts the flattened keys cover all thirteen exactly
  once — none lost, none duplicated, nothing foreign. Red-drilled by
  dropping a key: exactly the new test failed; reverted green.
- `laravel/resources/js/lib/ankiTemplate.js` — value-side tidy, output
  byte-identical: the sparse-map "absent = on" convention got a name
  (`isOn(fields, key)` — six decision points, one home); the counted
  sections' composition (summary wording + list markup) deduped into
  `definitionsSection`/`examplesSection`/`etymologySection`, shared by
  the flat fields and the Body's per-entry sections with each context
  keeping its own empty story (placeholder vs. nothing); the dense
  nested-ternary transcriptions push became `transcriptionsLine`,
  mirroring `translationsLine`.
- `GLOSSARY.md` — the three "twelve" mentions fixed honestly for schema
  v2, implementation-light: "Card field" names thirteen once and anchors
  the field-set to ADR 0077 (flat reading fields enumerated, `Body`
  last); "Note type" says thirteen with Body last; "Card template" now
  tells the V2 truth — the Back is header/footer scalars around the Body
  slot, the other flat fields searchable values the template no longer
  renders (the old "Back renders all twelve fields" was false twice
  over). The "Hide class" entry qualified: only the template (scalar)
  toggles restyle via re-sync; the body toggles are send-time.
- `wiki/playbooks/running-tests.md` — the Facts bullet on the blessed
  Components-side vitest pin now names both pass-throughs it locks
  (labels AND the resolved `fields` map — fields went send-time in
  ticket 06); stamps bumped.
- `wiki/domains/anki-export.md` — the vitest-specs sentence extended the
  same way (label and fields pass-throughs); stamps bumped.

Checks: full vitest 131 passed (15 files); `AnkiSettingsMirrorTest` +
`WikiTest` 8 passed (23 assertions) with the red-drill demonstrated;
`wiki:validate` 8 warnings = the pre-existing baseline; `npm run build`
clean (pre-existing chunk-size warning only); `vendor/bin/pint --dirty`
PASS (315 files).

## 2026-10-15 (anki-popup-card 07 — Route A closure: toggle semantics split in the Anki tab, ADR 0077 addendum v2, wiki)

V2 round closure for the anki-popup-card feature. Ticket 06 shipped the
code (schema v2 — the 13th `Body` field carries the popup-interleaved
dictionary, rendered at send time; the body toggles plus the `body` master
became send-time while the scalars kept the V1 hide-class template
semantics) but deliberately left the tab's toggles unregrouped and the
knowledge base unstaled. This ticket lands the presentation split and the
knowledge-base reversal; no template/value/sync logic changes.

- `laravel/resources/js/Pages/Profile/AnkiSettings.jsx` — the card-content
  toggles regrouped from three display groups into two whose descriptions
  state the semantics split: **Template fields** (word, familiarity,
  frequency, word_family, part_of_speech, tags, language — the Back's
  header/footer scalars in visual order; "re-syncing the template restyles
  ALL cards") and **Dictionary body** (the `body` master — now with its
  first-ever checkbox, the data/validation/send paths having shipped in
  ticket 06 — plus translations, transcription, definitions, examples,
  etymology in the popup's per-entry order; "shapes newly sent cards
  only"). Each group renders a muted-italic description line under its
  title.
- `laravel/database/seeders/ui-strings/profile.php` — new en/ru/be keys:
  `profile.anki_field_group_template` +
  `profile.anki_field_group_template_description`,
  `profile.anki_field_group_body` +
  `profile.anki_field_group_body_description`, and
  `profile.anki_field_body`; the three dead V1 group-title keys removed
  from the source of truth (their DB rows linger orphaned — the seeder
  only upserts); `profile.anki_card_description` rewritten in place (its
  "a switched-off field is hidden by the card template" sentence was
  scalar-only truth after the split). Seeded against the dev DB
  (updateOrCreate, non-destructive); ru/be spot-checked served.
- `docs/adr/0077-browser-side-ankiconnect.md` — new "Addendum v2
  (2026-10-15): Route A" recording the reversal: schema v2 (thirteen
  fields, `Body` last; flat fields keep searchable values but only Body
  renders the dictionary; the bracketed header-transcription line is
  gone), the toggle-blind invariant split — scalars keep values-always-
  sent + hide-class semantics, body toggles are send-time via the fields
  map both senders pass, so re-sync no longer restyles the body content of
  existing cards (accepted consequence, taught in the tab's group
  descriptions) — the shipped value-side fidelity notes (Body labels
  every entry; Body translations not native-narrowed; two calm
  placeholder label keys), and the user-visible 12 → 13 migration taught
  by the mismatch copy (delete the note type in Anki — notes go with it —
  sync again, re-send).
- `wiki/domains/anki-export.md` — description, flow steps 2–4 and
  boundaries rewritten for schema v2: the Back as header + `{{#Body}}`
  slot + footer, scalar-vs-body toggle semantics, the 13-field note with
  the Body as the rendered dictionary, the seven-string labels option,
  the twelve-field-install mismatch migration, and the pre-v2 old-notes
  consequence (no Body value → slot drops → header + footer only, until
  the migration's delete-and-resend); stamps bumped.
- `wiki/domains/profile.md` — Anki tab section rewritten: thirteen toggles
  in the two semantics-labeled groups (the `body` checkbox included), the
  schema-v2 Back, `buildNoteFields`' `fields` option and 13-field note,
  the sync section's teaching mismatch copy; tabs table row updated;
  stamps bumped.
- `wiki/domains/interactive-words.md` — the Save-to-Anki "Note values"
  bullet rewritten (thirteen fields; the send-time body toggles ride the
  shared `ankiSettings.fields` prop) and the mismatch failure bullet now
  describes the teaching copy; stamps bumped.
- GLOSSARY.md still says "twelve" — out of this ticket's file scope,
  flagged for a follow-up.

Checks: vitest 131 passed (15 files); PHP `AnkiSettingsMirrorTest` +
`ProfileTest` + `UiSettingsTest` + `UiStringSeederTest` + `UiStringsTest` +
`WikiTest` green; `wiki:validate` back to the 8 pre-existing warnings (the
10 staleness flags ticket 06's code commits raised on these three concepts
cleared by these content updates); pint PASS. No routes/models/commands
touched — no `wiki:sync` needed.

## 2026-10-15 (anki-popup-card 05 — review fixes: expander CSS pairing, shared label builder, doc sync)

Review-fix commit on `anki-popup-card` closing the two-axis review of the
feature's diff (standards + spec). Three code findings, one doc finding,
one spec amendment:

- `laravel/resources/js/lib/ankiTemplate.js` — real defect fixed: the
  translations expander's emitted `<summary class="ef-more-summary">` was
  matched by no CSS rule (the selector was the descendant form
  `.ef-more .ef-summary`, which matches nothing in the baked value), so the
  "+N more…" affordance lost its pointer cursor, `list-style: none`, and
  marker suppression. Since field VALUES are frozen into already-sent
  notes, the CSS now names the emitted class: `.ef-more-summary` joins the
  shared tap-affordance rule and the marker-suppression rule, and the
  override rule targets it directly. `.ef-details` — emitted with no rule —
  got the rule it deserves: the popup's `mt-2` lives on the `<details>`
  itself, so the spacing moved off the summary onto `.ef-details`. A new
  pairing test in `ankiTemplate.test.js` extracts every `ef-*` class the
  generated template HTML, hide classes, and value-side structure emit and
  requires each to appear as a class selector in TEMPLATE_CSS — this class
  of miss cannot recur.
- `ankiTemplate.js` + `WordPopup.jsx` + `AnkiSettings.jsx` — the five-key
  labels object (identical in both senders) became the shared
  `uiLabels(t)` builder in `ankiTemplate.js` (the localized counterpart of
  `DEFAULT_LABELS`, over the app's `word.*` ui-strings). The send-path
  source pin (`WordPopup.ankiSend.test.js`) now pins
  `labels: uiLabels(t)` and forbids a hand-rolled object; the key→string
  mapping is pinned behaviorally in `ankiTemplate.test.js` (a lib export,
  so stronger than the old anywhere-in-the-send-block substring match).
- `wiki/domains/anki-export.md` — step 4 names `uiLabels(t)` as the shared
  mapping; `generated.at` corrected forward (ticket 04 had bumped it
  backwards, 15:20 → 13:05).
- `wiki/playbooks/running-tests.md` — vitest section de-staled: the suite
  is lib modules PLUS the one blessed Components-side source pin
  (ADR 0077 addendum point E), colocation bullet states the exception.
  Its 15:20+03:00 stamp was future-dated (authored in an 11:07 commit);
  reset to the true edit time.
- `wiki/domains/profile.md` + `wiki/domains/interactive-words.md` — the
  senders' label sentence in each now names the shared `uiLabels(t)`
  builder (their "tab passes its locale's via `useI18n`, as the popup does"
  / "passed from `sendToAnki`" prose stayed true but the seam moved);
  stamps bumped (both had also been verified as moved forward, not
  backwards, in ticket 04).

## 2026-10-15 (anki-popup-card 04 — knowledge-base closure: the Anki card looks like the reading popup)

Closure of the anki-popup-card feature (code in its tickets 01–03: the
generated card template now puts the word alone on the Front and the
reading popup's header/body/footer structure on the Back with the popup's
palette and local font stacks; field values carry the popup's dictionary
structure — per-entry "WORD — pos" labels, counted `<details>` summaries,
the translations "+N more…" expander, " · " transcriptions, the localized
"form of" prefix — with UI-language label strings baked per note at send
time). This ticket lands only the knowledge base; zero feature code.

- `docs/adr/0077-browser-side-ankiconnect.md` — new "Addendum
  (2026-10-15): the card looks like the reading popup" with five points:
  (A) structure lives in the field VALUES while the template stays static
  (counts/entry labels are per-note data, label wording is the sender's UI
  language — neither computable in an Anki template; its own literals
  shrank to three conditional meta-line prefixes), (B) the Back's sections
  are native collapsed `<details>` — no JS, collapsed by default, (C)
  local font stacks only — review never fetches webfonts, (D) the accepted
  old-notes consequence — pre-rework notes keep their plain values under
  the new template with two cosmetic deltas (bare WordFamily without
  "form of"; "[a, b]" transcriptions) and no re-send refresh
  (find-before-add blocks it), (E) the first vitest file outside `lib/`
  (`WordPopup.ankiSend.test.js`, a source-pin mirror of the send's label
  pass-through) blessed within ADR 0066's intent.
- `wiki/domains/anki-export.md` — flow step 2 rewritten for the
  popup-styled template (Front = word alone, Back header/body/footer,
  conditional meta lines, popup surface, local fonts); step 4 rewritten
  for the structured values + send-time label baking + the old-notes
  consequence; a "static by design" boundary bullet added; vitest
  spec list gains the Components-side source pin; description + source
  titles updated; stamps bumped.
- `wiki/domains/profile.md` — Anki tab's Card content / Template preview
  paragraphs rewritten: the tab's preview and sample card show the
  popup-styled card with conditionals resolved, and `buildNoteFields`'s
  labels option (the tab passes its locale's via `useI18n`, as the popup
  send does); stamps bumped.
- `wiki/domains/interactive-words.md` — the Save-to-Anki "Note values"
  bullet notes the popup-structured values and the UI-language label
  baking from `sendToAnki`; stamps bumped.
- `GLOSSARY.md` — the "Card template" term's stale sentence fixed
  ("Front shows Word + Transcription" → the word alone on the Front, the
  popup's header/body/footer on the Back); glossary-only, no new terms.
- Checks: `wiki:validate` green; `tests/Feature/WikiTest.php` green.
  `wiki:sync` not needed — no routes/models/commands touched.

## 2026-10-15 (retro-fixes 10: round-2 closure — remaining staleness cleared, verification battery green)

Round-2 bookkeeping close. The two staleness warnings carried since retro-fixes 05 (both concepts
list `AGENTS.md` as a source, which the vitest-block edit re-staled) are cleared: **`conventions/coding-conventions.md`**
got a content update, not just a stamp — its Testing section covered Pest only and would have
re-created exactly the false belief the round exists to kill, so it now states the JS rule (pure-JS
logic in `resources/js/lib/` is vitest-tested, new lib modules get vitest tests beside the module,
same as Pest for PHP); **`architecture/docker-services.md`** content checked unaffected (the golden
rule + services table are untouched by the Testing-section edit) and re-stamped. Both `generated.at`
bumped past the AGENTS.md mtime, existing `verified` stamps preserved per round-1 (ticket 04)
convention; `wiki:validate` → **0 warnings**. Verification battery, all sequential in-container:
full `composer run test` **1166 passed / 0 failed** (6056 assertions, 8 parallel workers, 508s —
the exact parallel conditions that used to trip the WordClassSeederTest flake, now fixed by
retro-fixes 06), vitest **94/94 in 14 files** (up from 52/11 — retro-fixes 08's 42 anki specs),
`npm run lint` exit 0 with 0 errors / 81 documented warnings (retro-fixes 07's deliberate warn
decisions), `npm run build` green, `pint --dirty` PASS. Round totals: findings #1 (AGENTS.md
vitest guardrail + anki vitest port), #2 (ESLint + CI), #4 (popup lockstep test), #5 (flake), #6
(researcher citation rule) fixed; #3 closed as a false premise (`.zcode/` gitignored by design —
documented in the spec, nothing to commit). Commits `7c085ce` / `1a09abc` / `1db51e0` / `0f87321`
/ `cd6e6ff` / this one, all on `anki-connection`; spec addendum + tickets 06–09 live in
`.scratch/retro-fixes/`. Carry-forward (operational, not a finding): bare `composer run test:tia`
errors pre-run on the unauthenticated-`gh` remote-baseline fetch — run the documented `--fresh`
invocation once locally or authenticate `gh`.

## 2026-10-15 (retro-fixes 09: the popup sections mirror gets the Anki lockstep treatment)

Round-2 retro finding #4: the anki review's mirror-lockstep test (`AnkiSettingsMirrorTest`) covered
only the new feature, while the older PHP↔JS mirror it flagged as "synced only by comments" —
`PopupVisibility::SECTIONS` ↔ the Popups tab's module-local, unexported `POPUP_SECTIONS`
(`resources/js/Pages/Profile/PopupsSettings.jsx`), guarded since ADR 0046 by nothing but the owner
comment — stayed untested, so a renamed or reordered section silently desynced the server-side
defaults (`PopupVisibility::for()` via `HandleInertiaRequests`' `popupVisibility` prop) from the
settings UI. New `tests/Unit/PopupSectionsMirrorTest.php` copies the established pattern: read the
JSX source from disk, regex-extract the `const POPUP_SECTIONS = […]` body, `preg_match_all` the
quoted keys, one order-sensitive `expect(...)->toBe(PopupVisibility::SECTIONS)` — keys and order in
a single assertion, no JS runtime, and the pure-regex extraction held so the export-the-constant
fallback stayed unused. `POPUP_GROUPS` deliberately not mirrored: display grouping only, every key
it lists is a member of `POPUP_SECTIONS`, and `PopupVisibility` has no grouping concept. Desync
proof: renaming `'etymologies'` → `'etymology'` in the JSX fails the test with a per-key array diff
at index 9; restored and green. `profile.md`'s Popups tab section names the pin (`generated.at`
bumped); `interactive-words.md` left alone — its `PopupVisibility` mention is the runtime
visibility behavior, not the settings mirror. Test file only; pint PASS; suite green 1166 passed
(via `--fresh` local TIA baseline record — gh is unauthenticated so the remote-baseline fetch
errors; the documented `--fresh` run records locally and subsequent `test:tia` replays work). No
routes/models/commands touched, no `wiki:sync` needed.

## 2026-10-15 (retro-fixes 08: anki JS modules vitest port — the ad-hoc Node smoke runs become real tests)

Follow-up to the anki-connection retro's finding #1: that session believed JS had no test infra
(false — vitest, 11 existing `lib/*.test.{js,mjs}`, CI `frontend` job) and verified
`ankiConnect.js` / `ankiTemplate.js` / `ankiSync.js` with ad-hoc Node smoke runs that are gone the
moment they end — though they earned their keep (ticket 03's caught the missing `deckNames` import
the Vite build missed). Landed three colocated specs transcribing the recorded smoke scenarios:
`ankiTemplate.test.js` (12 tests: byte-determinism, Front = Word + Transcription only, hide-class
back wrappers vs all-twelve `buildNoteFields` values (ADR 0077 semantics), the Front-never-hides-Word
exception, native-language CODE filter with all-translations fallback — including the numeric
`native_language_id` silent-degrade trap from ADR 0077's Consequences, HTML escaping,
`{{Field}}` substitution), `ankiConnect.test.js` (19 tests: endpoint defaults/trimming/coercion,
invoke's JSON POST shape + the three failure normalizations into `AnkiConnectError` kinds, and one
representative wire-shape assert per action wrapper — including the nested `model:{name,
templates:{card:{Front,Back}}}` objects ticket 03 corrected against the plugin source),
`ankiSync.test.js` (11 tests: cold-ensure create wire shape, memoization + memo-miss by deck,
`{force: true}` re-push with hide classes baked, both mismatch kinds after exactly the probe calls,
first-send find-before-add query + 12-field `allowDuplicate: false` addNote, duplicate/foreign-model
skips, memoized second word, raw-word guard). Modules untouched. Testing-pattern lessons recorded in
`running-tests.md`: `vi.stubGlobal('fetch', …)` for the request/response client, and for a module
with module-level memo state (ankiSync's `ensured` Set) use static single-generation imports with a
unique endpoint|deck key per test — `vi.resetModules()` + dynamic import mints fresh error classes
that break `instanceof`, and a per-test config counter that resets makes every test re-mint the
first test's memoized key (ensures silently become memo hits); reuse one config object within a
test to exercise the shared-session memo. Suite 52→94 tests, all green; `npm run lint` exit 0 (new
files add no warnings); pint PASS; `anki-export.md` + `running-tests.md` mention the pins,
`generated.at` bumped on both; no routes/models/commands touched, no `wiki:sync` needed.

## 2026-10-15 (retro-fixes 07: ESLint flat config + CI lint job — the static no-undef/imports/hooks guard)

From the anki-connection session retro (2026-10-14), finding #2: ticket 03's missing `deckNames`
import in `resources/js/lib/ankiSync.js` — a guaranteed runtime ReferenceError — passed `vite
build` (Vite bundles but does no static resolution of what an identifier refers to) and was caught
only by the implementer's ad-hoc Node smoke; vitest pins only what has tests, so the other ~73
`.jsx` files had nothing statically checking them. Landed: eslint 10 + `@eslint/js` 10 +
`eslint-plugin-react-hooks` 7 + globals 17 as devDeps (installed inside the container),
`laravel/eslint.config.js` (flat config over `resources/js/**/*.{js,mjs,jsx}`; browser globals
everywhere, Node globals for the vitest specs and `lib/wordTokenizer.cli.mjs`; ignores
node_modules/vendor/public), `"lint": "eslint resources/js"` in package.json, and a `lint` job in
`tests.yml` mirroring the `frontend` job (`npm ci` + `npm run lint`, Node 22, npm cache off the
lockfile). Goal is correctness, not style, and the tiers say so: `no-undef` + core recommended +
rules-of-hooks stay `error` (canary-proofed: a file using `deckNames` un-imported and a hook after
an early return both fail the run); the v7 compiler-*analysis* rules whose hits are all
legacy-but-working patterns (`refs` 14, `set-state-in-effect` 13, `immutability` 2,
`preserve-manual-memoization` 1) sit at `warn`; two deliberate patterns got file-scoped carve-outs
with in-file comments (`no-control-regex` off in `lib/markdown.js` — the \u0000/\u0001 sentinels
ARE the code-span protection mechanism; `react-hooks/globals` off in `i18n.jsx` — the module-level
`strings` cache IS the `t()` mechanism). Genuine violations fixed in place: two `no-useless-escape`
hits (`\-` → `-` in the mirrored tokenizer/WordText word patterns — semantics-identical,
TokenizerParityTest green). The 51→81 warnings (17 unused legacy `import React`,
destructuring leftovers, exhaustive-deps stability nags) are review signal, deliberately not
mass-fixed. `running-tests.md` gains the lint command + rule-tier contract and an
`eslint.config.js` source, `generated.at` bumped.

## 2026-10-15 (retro-fixes 06: WordClassSeederTest deterministic ordering — kill the parallel flake)

From the anki-connection session retro (2026-10-14), finding #5: `tests/Feature/WordClassSeederTest.php`
compared two DB queries with no `orderBy` (line 23 matched `$beClasses->keys()` against
`$enSlugs->values()`), so the en/be slug-sequence alignment rested on Postgres natural row order —
stable only while both queries scan the same physical layout, which the parallel workers' freshly
created `ext_app_test_test_{N}` databases do not guarantee; it failed exactly once in ticket 01's
full-suite run while passing in isolation, and a flaky red trains agents to discount red. Fix is
test-only: both compared sides now `orderBy('slug')` (total order via the `(language_id, slug)`
unique index on `word_classes`), so the sequence-vs-sequence assertion keeps checking that the be
slug sequence mirrors the en slug sequence — still fails on any missing/extra slug — plus the four
spot-checked Belarusian titles; the idempotency test is count-only and untouched. Seeders and
models unchanged; the seeder's curated `CLASSES` order is an iteration order, not a persisted one
(rows carry no order column), so sorted comparison matches the seeder's actual contract ("Same slug
set as English"). `running-tests.md` doesn't name the test, so it stays as-is; no routes/models/
commands touched, no `wiki:sync` needed.

## 2026-10-15 (retro-fixes 05: AGENTS.md vitest guardrail + researcher source-citation rule)

From the anki-connection session retro (2026-10-14), finding #1: AGENTS.md's
Testing section listed only Pest and pytest while `laravel/package.json`
ships `"test": "vitest run"`, CI gates it (`.github/workflows/tests.yml`
"Vitest (pure JS logic)" job), 11 `*.test.{js,mjs}` files live beside the
modules in `resources/js/lib/`, and `running-tests.md` documented all of it
— so planning sessions that (correctly) trust AGENTS.md as the command
surface concluded JS had no test infra, and the anki feature's JS modules
got ad-hoc Node smoke runs instead of real vitest tests. AGENTS.md now has
the **JS logic tests (vitest)** block: `npm run test` in the container,
tests beside modules, Node-only per ADR 0066, "new lib modules get vitest
tests, same as Pest for PHP", CI job pointer. Finding #6: the retro's
AnkiConnect research recorded pre-2022 `updateModelTemplates` /
`updateModelStyling` shapes from search summaries; the implementer caught it
only by reading the plugin source. `.zcode/agents/researcher.md` (machine-
local, gitignored) gains a rule: external-API request/response shapes must
be verified against official docs or upstream source, with the URL cited in
the notes. Finding #3 ($high agent-model pins "uncommitted") closed as a
false premise — `.zcode/` is gitignored by design, the pins persist on
disk, and future sessions read agent definitions at start; nothing to
commit. AGENTS.md is not a wiki concept, so no concept stamps change;
tickets 06–09 for the remaining findings (#2 ESLint, #4 popup-sections
mirror test, #5 WordClassSeederTest ordering flake, #1-follow-up anki
vitest port) live in `.scratch/retro-fixes/`.

## 2026-10-14 (anki-connection review: six code-review fixes — eager tags, mirror lockstep test, kind-true error panels, Word always on Front)

Post-feature review pass over tickets 01–05: two deduplications, one
executable contract, one misdiagnosis fix, one card-template rule, one
eager-load, one API tightening. Findings deliberately rejected: the legacy
`--color-*` tokens in the Anki tab (the whole host Profile surface uses
them; parity until that surface migrates) and "deduplicating" the error
taxonomy if-cascade between WordPopup.jsx and the tab (the surfaces
intentionally emit different i18n string sets, `word.*` vs `profile.*`).

- `laravel/app/Classes/WordFamily.php` — `EAGER_LOAD` gains `tags` (plain
  relation, pivot columns needed): `WordController::show` plucks tag names
  off the bound word, which previously cost one lazy query per popup open.
- `laravel/tests/Unit/AnkiSettingsMirrorTest.php` (new) — the PHP↔JS
  mirror is now executable: reads
  `resources/js/lib/ankiTemplate.js`/`ankiConnect.js` from disk and pins
  the twelve field keys + Anki field names (key→name map and
  `AnkiSettings::FIELDS` order), the three connection defaults and the
  note-type/card-name strings. Plain string assertions, no JS runtime;
  proven red on a fake drift before going green.
- `laravel/resources/js/Pages/Profile/AnkiSettings.jsx` — the
  requestPermission-then-throw block deduplicated into a module-level
  `grantPermission(config)` (test probe + sync grant-and-retry share it),
  the sample-note construction hoisted into one `sampleNoteFields` (feeds
  the live preview and the sample-card send), and the sync failure panel
  now branches on the error's kind: `AnkiConnectError` with kind `anki`
  renders an inline "Anki error: <message>" panel (`anki-sync-anki-error`)
  instead of misdiagnosing as a connection problem — network/unknown keep
  the connection hint.
- `laravel/database/seeders/ui-strings/profile.php` — new
  `profile.anki_anki_error` (en/ru/be); reseeded via `UiStringSeeder`.
- `laravel/resources/js/lib/ankiTemplate.js` — `generateCardTemplate`'s
  Front face NEVER hides `word` (recognition cue + duplicate-search key; a
  hidden Word would blank the card), though Word's toggle still hides its
  Back wrapper; Transcription on Front may still hide.
- `laravel/resources/js/lib/ankiSync.js` — `sendAnkiNote`'s `word` option
  is required, no fallback to the HTML-escaped `noteFields.Word`: a
  missing raw word throws an `AnkiConnectError` instead of silently
  breaking the duplicate search (both callers already pass it raw).
- Wiki: `anki-export.md` records the Front-never-hides-Word rule, the
  raw-word requirement and that both surfaces branch on the same error
  kinds; `interactive-words.md`'s endpoint row notes the tags eager load;
  stamps bumped. `wiki:validate` 0 warnings.

## 2026-10-14 (anki-connection 04 — per-word "Save to Anki" in the word popup)

Fourth ticket of the Anki-export feature: the word popup's footer gains a
**Save to Anki** button — one click sends the clicked word to the user's
Anki deck from reader or simulator, duplicates skipped, failures never
silent. Still browser-only.

- `laravel/app/Http/Controllers/WordController.php` — `GET /words/{word}`
  payload gains `tags: [names]` (the linked word's tag names via
  `Word::tags()`, additive between `frequency` and `entries`); feature
  tests extended (`WordEndpointTest` +2: tagged payload order, empty list
  for untagged words).
- `laravel/resources/js/Components/WordPopup.jsx` — the footer now always
  renders: the Save-to-Anki button sits OUTSIDE the `progress_actions`
  gate (right-aligned `word-popup-anki-send`, disabled "Saving…" while in
  flight), the gate only hides the progress buttons. `WordPopup` builds
  the note from the loaded payload with `buildNoteFields()` (familiarity
  from the popup prop, tags from `data.tags`, Translations narrowed by the
  native language CODE from the shared `auth.user.native_language` prop)
  and sends through `sendAnkiNote()` (ensure memoized module state shared
  with the Anki tab). Status line under the buttons: added / "Already in
  Anki." / unreachable hint + Profile → Anki link / Anki error verbatim /
  `AnkiNoteTypeMismatchError` re-sync warning; state resets per payload.
  The Popups-tab preview renders the button disabled (no handler),
  mirroring the progress buttons.
- `laravel/resources/js/Pages/Profile/AnkiSettings.jsx` + `Edit.jsx` —
  the tab now receives `nativeLanguage` (the code) instead of
  `nativeLanguageId`: the numeric id could never equal a `language_code`,
  so the preview and sample card silently carried ALL translations instead
  of the native-language filter. Popup and tab now behave identically.
- `laravel/resources/js/Pages/Profile/sampleWordData.js` — SAMPLE_TAGS
  comment updated (fixture-only stand-in; the real popup passes payload
  tags).
- `laravel/database/seeders/ui-strings/words.php` — 8 new en/ru/be
  `word.anki_*` strings (button, saving, added, already-in-Anki,
  unreachable hint, mismatch, error, go-to-settings); reseeded via
  `UiStringSeeder`, be spot-checked.
- Verification: container Node smoke of the popup's exact data path
  (stubbed fetch): first-send wire (ensure + findNotes
  `deck:"…" Word:"melted" Language:"en"` + 12-field addNote with native
  filtered Translations, Tags, allowDuplicate false), memoized second send
  (find+add only; all-translations fallback; empty tags → empty field),
  duplicate skip (no addNote). `npm run build` clean (pre-existing chunk
  warning). `test:tia` → 1161 passed (6047 assertions). Pint PASS.
- Wiki: `interactive-words.md` — new "Save to Anki (per-word send)"
  section, footer/class/payload updates, anki tag, stamps bumped;
  `profile.md` — cross-reference paragraph + the native-language-code
  correction recorded; stamps bumped.

## 2026-10-14 (anki-connection 03 — template push + sample card into Anki)

Third ticket of the Anki-export feature: the Anki tab can now write into
Anki — one click lands the deck, the "ExtApp Vocabulary" note type and the
generated Front/Back/CSS in the user's desktop Anki, and a sample card
proves the pipeline end to end. Still browser-only; Laravel never calls
Anki.

- `laravel/resources/js/lib/ankiConnect.js` — the thin client grew the
  deck/model/template/note action wrappers (deckNames, createDeck,
  modelNames, modelFieldNames, modelTemplates, createModel,
  updateModelTemplates, updateModelStyling, findNotes, notesInfo, addNote).
  Parameter shapes verified against the AnkiConnect plugin source
  (git.sr.ht/~foosoft/anki-connect, plugin/__init__.py): the two update
  actions take a model OBJECT — `{name, templates: {card: {Front?, Back?}}}`
  and `{name, css}` — and `updateModelTemplates` silently skips card names
  the model doesn't have (guarded in ankiSync.js).
- `laravel/resources/js/lib/ankiSync.js` (new) — the push orchestration:
  `ensureAnkiTarget()` (deck create-if-missing; note type create-if-missing
  with all 12 fields + the "Recognition" card, otherwise field-set
  verification then templates + CSS push), memoized once per page session
  per endpoint+deck (the explicit sync button passes `force: true`, which is
  what re-applies toggled-off `ef-hidden` classes); `AnkiNoteTypeMismatchError`
  (kinds `fields`/`card`) instead of ever rewriting a foreign same-name note
  type; `sendAnkiNote()` with a find-before-add duplicate check scoped to
  deck + Word + Language (raw word passed by the caller for the search,
  HTML-escaped field values on the wire, `allowDuplicate: false` race net,
  `{added, noteId}` result).
- `laravel/resources/js/Pages/Profile/AnkiSettings.jsx` — new "Send to Anki"
  section: Create / update template in Anki (fresh forced ensure) + Send
  sample card (fixture-built note through the memoized ensure), inline
  sync/sent/duplicate status lines, `AnkiNoteTypeMismatchError` warning
  panels naming expected vs found fields/cards, and the shared
  connection-hint failure panel (extracted `ConnectionHint` component, also
  rendered by the Test connection probe) whose Request permission step now
  grants + replays the blocked action in one click. `SecondaryButton`
  (ui.jsx) gained an additive `disabled` prop for the running state.
- `laravel/database/seeders/ui-strings/profile.php` — 9 new en/ru/be
  strings (`profile.anki_sync_*`, `profile.anki_sample_*`,
  `profile.anki_mismatch_*`). Reseeded via `UiStringSeeder` (idempotent),
  be spot-checked via tinker.
- JS verification (no JS test infra): container Node smoke run of
  ankiSync/ankiConnect against a stubbed fetch — ensure wire shapes, session
  memoization, forced re-push baking `ef-hidden` from toggles, both mismatch
  kinds, the duplicate query + addNote shape (12 fields, tags,
  allowDuplicate false) and duplicate-skip. The smoke run caught a missing
  `deckNames` import that the Vite build had not flagged. `npm run build`
  clean (3.0s, pre-existing chunk-size warning only).
- Tests: no PHP behavior changed (strings only); `composer run test:tia`
  green; pint clean; `wiki:validate` 0 warnings. `wiki:sync` not needed (no
  route/model/command changes).
- `wiki/domains/profile.md` — Anki tab section gained the "Send to Anki"
  paragraph (ensure semantics, mismatch warnings, duplicate check, memoized
  ensure, permission retry), ankiConnect.js scope line updated, tab-table
  row + frontmatter description mention template sync, new `anki-sync`
  source, stamps bumped.
- Live-Anki round-trip (template appearing in Anki's editor, sample card in
  the deck, re-sync restyling existing cards) still needs a desktop Anki —
  pending the user's manual QA, same caveat as tickets 01/02.

## 2026-10-14 (anki-connection 02 — Anki card fields + generated template + live preview)

Second ticket of the Anki-export feature: the Anki tab gains its
card-content side — twelve field toggles, a deterministic card-template
generator and a live Front/Back preview. Everything works offline; no Anki
calls.

- `laravel/app/Support/AnkiSettings.php` — new canonical `FIELDS` const
  (the twelve card-field keys in the note type's fixed order) and `for()`
  now also resolves the `fields` map (absent key = on, the Popups-tab
  convention) beside url/port/deck.
- `laravel/app/Http/Requests/UpdateUiSettingsRequest.php` — `anki.fields`
  array rule + one `boolean` rule per field, generated in a loop from
  `AnkiSettings::FIELDS` (unknown field keys still stripped by
  `validated()`).
- `laravel/resources/js/lib/ankiTemplate.js` (new) — the one JS mirror of
  `AnkiSettings::FIELDS`: the field-key → Anki-field-name mapping (Word,
  Transcription, PartOfSpeech, … fixed order), the "ExtApp Vocabulary" note
  type name, `generateCardTemplate()` (deterministic Front/Back HTML + CSS;
  toggled-off fields get `ef-hidden` baked into the HTML — hide-class
  semantics, values always carry every field; CSS defines `.card` +
  `.ef-hidden{display:none}`), `buildNoteFields()` (full 12-field note from
  a word payload; translations narrow to the native language with an
  all-translations fallback) and `renderCardHtml()` ({{Field}} substitution).
- `laravel/resources/js/Pages/Profile/sampleWordData.js` (new) — the sample
  word fixture (SAMPLE_DATA + SAMPLE_FAMILIARITY + SAMPLE_EXPLAIN, plus
  SAMPLE_TAGS for the Anki preview) extracted from PopupsSettings.jsx so
  the Popups and Anki previews share one source.
- `laravel/resources/js/Pages/Profile/PopupsSettings.jsx` — imports the
  extracted fixture; no behavior change.
- `laravel/resources/js/Pages/Profile/AnkiSettings.jsx` — one combined
  settings state (url/port/deck + fields: the endpoint replaces the whole
  `anki` section, so every autosave PATCH must carry all of it), twelve
  toggles in three groups, and a template preview: sandboxed iframe
  rendering the generated CSS + HTML with the sample values substituted, as
  Front/Back tabs, with Copy HTML (selected face) / Copy CSS buttons.
- `laravel/resources/js/Pages/Profile/Edit.jsx` — passes
  `nativeLanguageId` to AnkiSettings for the preview's translation filter.
- `laravel/database/seeders/ui-strings/profile.php` — 23 new en/ru/be
  strings (`profile.anki_card_*`, `profile.anki_field_*` ×12 + 3 groups,
  `profile.anki_template_*`, copy buttons); `profile.anki_title`/
  `anki_description` broadened to cover connection + card content. Reseeded
  via `UiStringSeeder` (idempotent upsert), ru/be spot-checked.
- Tests: `UiSettingsTest` +3 (field save, junk rejection + unknown-key
  strip, section isolation), `ProfileTest` reworked anki-prop cases now
  assert all-twelve-on defaults and saved field opt-outs. `test:tia` →
  1159 passed (6043 assertions); touched files re-run → 35 passed. Build +
  pint clean; `wiki:validate` 0 warnings. `wiki:sync` not needed (no
  route/model/command changes).
- `wiki/domains/profile.md` — Anki tab section rewritten (connection +
  card content + template preview), tab-table row, wide-layout sentence now
  covers both wide tabs, new sources (`anki-template`, `sample-word-data`),
  stamps bumped.

## 2026-10-14 (anki-connection 01 — Anki profile tab + connection settings)

First ticket of the Anki-export feature (branch `anki-connection`): a sixth
**Anki** tab on the profile page holding the AnkiConnect connection settings.

- `laravel/app/Support/AnkiSettings.php` (new) — canonical connection
  resolver: `url`/`port`/`deck` resolved over defaults
  (`http://127.0.0.1` / `8765` / `ExtApp`) from the `anki` section of
  `ui_settings`, mirroring `PopupVisibility::for()`.
- `laravel/app/Http/Requests/UpdateUiSettingsRequest.php` — `anki.*` rules
  (url string ≤255, port integer 1–65535, deck string ≤100, all nullable);
  unknown keys keep being stripped by `validated()`.
- `laravel/app/Http/Controllers/UiSettingsController.php` — `anki` joined
  the section replace loop.
- `laravel/app/Http/Middleware/HandleInertiaRequests.php` — new shared
  `ankiSettings` prop next to `popupVisibility`.
- `laravel/resources/js/lib/ankiConnect.js` (new) — the browser-side
  AnkiConnect client (invoke / version / requestPermission only), the sole
  AnkiConnect caller by design: Laravel never calls Anki. Normalizes
  fetch rejection (CORS/refused) and AnkiConnect's error-inside-HTTP-200
  into `AnkiConnectError` (`network` vs `anki`).
- `laravel/resources/js/Pages/Profile/AnkiSettings.jsx` (new) — the tab
  body: URL/port/deck inputs autosaved via `useUiSettingsAutosave('anki')`,
  a Test connection button reporting the AnkiConnect version, and a failure
  panel with the exact CORS fix (Request permission action +
  `webCorsOriginList` + Anki restart, origin shown live).
- `laravel/resources/js/Pages/Profile/Edit.jsx` — sixth tab `{id: 'anki'}`
  after Popups, `width="wide"` panel + card like Popups; `ui.jsx` width
  comments updated (Popups + Anki are the wide tabs).
- `laravel/database/seeders/ui-strings/profile.php` — `profile.tab_anki` +
  14 `profile.anki_*` strings (en/ru/be); reseeded via `UiStringSeeder`.
- Tests: 6 new Pest tests — 4 in `UiSettingsTest` (anki save / section
  isolation / junk+unknown-key stripping / unapproved user) + 2 in
  `ProfileTest` (`ankiSettings` prop defaults + saved reflection).
  `profile.md` updated for the sixth tab.

## 2026-10-14 (goldendict-import review fixes — stats accuracy, extraction edges, converter error)

Review-fix commit on the `goldendict-import` branch: five two-axis review
defects fixed test-first (red → green), no command signature changes, no
schema changes.

- `laravel/app/Classes/GoldenDictParser.php` — (1) `translation_candidates`
  is now counted in real runs too (per staged record in `flushBatch`,
  matching the dry-run `tallyRecord` semantics) instead of printing 0 in
  every real run's stats table; (2) `target_words_created` counts only
  genuinely created rows — the batch's triples are diffed against the
  target language's existing words through a new `existingTargetWords`
  helper (the `flushTranslationLinks` new-only rule), and the same
  knowledge makes `--dry-run` tallies DB-honest: pending would-be-created
  target words resolve in batches against the DB, so re-import previews no
  longer overstate (would-be links stay an in-run estimate). The target
  word-class map is now preloaded like the source-side maps, so dry runs
  hand out real ids for existing classes and `lookups_created` counts only
  genuinely missing target classes in both modes; (3) a profile selector
  whose matched nodes hold only whitespace/bracket text is now a miss for
  that kind (falls back to the heuristic) instead of silently emptying the
  extraction — such articles count as "Profile no-match articles";
  (4) translation candidates equal to the article's own headword
  (case/normalization-insensitive via `WordLookupKey`) are dropped, so
  "Katze, cat" no longer creates a junk target word linking cat to itself.
- `laravel/app/Console/Commands/ImportGoldenDictCommand.php` — untouched.
- `docker-compose/python/ai/goldendict_convert.py` — `mdx_encryption` now
  catches `struct.error` (a truncated `.mdx` is shorter than the 4-byte
  header-length prefix), so the normal pyglossary failure path produces
  the one-line error instead of a traceback.
- Tests: 5 new Pest tests (stats real-run/dry-run honesty ×3, profile
  empty-match fallback ×1, self-candidate guard ×1) + 2 new fixtures
  (`goldendict-profile-empty-match.jsonl`,
  `goldendict-self-candidate.jsonl`); 1 new pytest
  (`test_truncated_mdx_is_a_clean_error`). `ImportGoldenDictCommandTest` →
  25 passed; import-family regression filter → 72 passed; python suite →
  158 passed. Playbook (`import-dictionary-data.md`) and this concept
  updated where they asserted the old behavior; `generated.at` bumped on
  both.

## 2026-10-14 (goldendict-import feature closure — playbook runbook, wiki sweep, deploy note)

Closure of the goldendict-import feature (branch `goldendict-import`,
commits `a5a2363`..`55a6f42`): no feature code changes — documentation and
verification only.

- `wiki/playbooks/import-dictionary-data.md` — the GoldenDict section is
  now the complete runbook a new agent can follow start-to-finish:
  download the dictionary under the repo (so the host-side converter and
  the container-side import see the same files; the freemdict legal note
  moved up front where the download happens) → convert on the host
  through the bind-mounted venv → `--dry-run --max-entries=500` preview
  (writes nothing) → smoke-run with real writes against the testing DB
  (`DB_CONNECTION=testing migrate:fresh --seed`, then the same capped
  command) → the real import detached with redirected output (heartbeats
  every 100k entries: counts, RSS, elapsed) → verify in the Filament
  admin. New "Prod deploy (python image rebuild)" subsection: the
  converter's `pyglossary==5.4.2` is pinned in
  `docker-compose/python/requirements.txt`, which the prod python image
  bakes — after this feature lands on master, the prod machine's next
  deploy needs `build python` + `up -d` + `./deploy.sh --stamp` before
  `./deploy.sh` will run (the stamp guard refuses by design; dev is
  unaffected — the bind-mounted venv was pip-installed directly).
- `wiki/domains/dictionary-import.md` — swept every ticket-01..05 claim
  against the code (command signature and validations, profile
  fallback/no-match sampling semantics, heartbeat interval and fields,
  column caps): all accurate. The "What it is" intro now names both
  source shapes (kaikki dumps and converted GoldenDict dictionaries), and
  the `goldendict:import` component bullet records stage 1's host-venv
  pyglossary dependency — the fact behind the deploy note. `generated.at`
  bumped on both files.
- Honest feature state: tickets 01–04 resolved; ticket 05 is
  **ready-for-human** — the profile mechanism is complete and tested, but
  the first REAL-dictionary profile is still to be authored (no freemdict
  file exists on this machine; `config/goldendict.php` ships only the
  documented `example-classes` shape profile, and the playbook how-to
  makes authoring it mechanical). Everything else is end-to-end:
  MDX/DSL/StarDict → JSONL → `goldendict:import`
  monolingual/bilingual/profiled, every flag live, no schema migrations
  (raw article HTML stays in the on-disk JSONL, re-importable anytime).

Checks: full parallel `composer run test` → **1,134 passed, 10 failed**
(5,877 assertions) — the 10 are exactly the pre-existing live Google
Translate 403 failures tickets 02/03/05 clean-tree-reproduced
(`WordEndpointTest` ×3, `RefreshEntityWordsJobTest` ×3,
`AdoptEntityWordsTest` ×2, `EntityEnrichmentTest` ×1,
`WordTranslationFetchTest` ×1 — external API calls without `Http::fake`;
re-verified by running the five suites standalone: the same 10 failures,
52 passed, accounting for the full run's entire failure count). Python
suite `docker exec ext_python python -m pytest /app/ai -q` → 157 passed,
1 pre-existing starlette warning. `wiki:sync` produced timestamp-only
churn on the three machine-owned reference files (the
`goldendict:import` signature was recorded in ticket 02 and is unchanged
since) — reverted per convention; `wiki:validate` conformant. Pint PASS
(311 files; no PHP touched).

## 2026-10-14 (goldendict-import ticket 05 — extraction profiles)

Fifth commit of the goldendict-import feature (branch `goldendict-import`):
`goldendict:import --profile=<slug>` now selects a declarative XPath
selector map layered over the generic heuristic extractor, so structured
dictionaries extract cleanly without code changes per dictionary:

- `laravel/config/goldendict.php` (new) — the profile registry. Per
  profile: `transcriptions` (matched nodes' texts become transcriptions,
  enclosing `[brackets]`/`/slashes/` trimmed, NOT gated on the IPA-marker
  heuristic — the profile author vouches for the nodes), `translations`
  (matched nodes' texts replace the heuristic block segments as the
  translation-candidate source; the noise filters still apply) and
  `definitions` (matched nodes' texts are the definition segments instead
  of the nearest-block grouping). Each value is an XPath 1.0 string or a
  list of them queried in order; the
  `contains(concat(" ", normalize-space(@class), " "), "…")` idiom matches
  a class as a whole token. Ships with an `example-classes` shape profile
  — **no real freemdict dictionary exists on this machine**, so the first
  real profile is still to be authored (ticket left `ready-for-human`).
- `GoldenDictParser` — constructor takes the command-validated profile;
  per article each selector kind overrides only its own extraction and
  only when it matched something in THAT article (a miss falls back to
  the heuristic — an entry is never dropped; a StarDict
  `sametypesequence=m` plain-text body therefore flows through the
  heuristics untouched). Articles where NO selector matched are counted
  (`profile_no_match_articles` stat row "Profile no-match articles") and
  sampled — first 5, as `word — <80-char snippet>` — into one
  `Goldendict profile '…': no selector matched in N articles` log notice
  at the end of the run. Empty-HTML articles never count as misses.
- `ImportGoldenDictCommand` — `--profile` is live (the inert warn is
  gone): unknown slug fails with the known list, unknown profile keys or
  non-string/empty selector values fail before anything is written, and
  selectors are compile-checked against a throwaway document so broken
  XPath fails up front instead of silently matching nothing. Demoed:
  the same fixture JSONL under `--dry-run` flips from 5 definitions /
  1 transcription (heuristics) to 3 definitions / 2 transcriptions /
  1 no-match article (profile).
- Tests: `ImportGoldenDictCommandTest` grows 4 profile cases (selectors
  apply + per-kind fallback + the with/without-profile re-import demo,
  unknown slug, unknown selector keys, capped no-match sampling via
  `Log::spy`) on new fixtures `tests/Fixtures/goldendict-profile.jsonl`
  and `goldendict-profile-nomatch.jsonl`; the ticket-03 inert-`--profile`
  test became a real monolingual profile import. Targeted suite:
  20 passed (163 assertions); import-family regression (6 suites):
  67 passed (316 assertions).
- Wiki: this log, the import playbook gained an "Extraction profiles"
  how-to (plus the converter's now-real DSL/StarDict input list and the
  DSL/StarDict profile-authoring quirks), `dictionary-import.md` gained
  the profile component bullet, `generated.at` bumped on both.
  `wiki:sync` produced timestamp-only churn on the reference files (the
  command was already recorded in ticket 02) — reverted;
  `wiki:validate` conformant (88 warnings, the same pre-existing set).

## 2026-10-14 (goldendict-import ticket 04 — DSL + StarDict conversion)

Fourth commit of the goldendict-import feature (branch `goldendict-import`):
the ticket-01 converter now covers the other two GoldenDict formats — ABBYY
Lingvo `.dsl` and StarDict — producing the same
`{"word": str, "alts": [str], "html": str}` JSONL, so `goldendict:import`
(tickets 02/03) stays format-blind:

- `docker-compose/python/ai/goldendict_convert.py` — DSL and StarDict file
  input worked through pyglossary 5.4.2 unchanged (DSL reader sniffs
  UTF-16LE/BE/UTF-8 and normalizes card markup to HTML — `[m1]` → indented
  `<p>`, `[trn]` unwrapped, `[c]`/`[p]` → green font/label, `<<x>>` →
  `bword://` links; StarDict reader handles `.dict.dz` via gzip and folds
  `.syn` synonyms into alternate headwords). The one converter change:
  StarDict **directory** input — pyglossary picks formats by file extension
  and StarDict is identified by `.ifo`, so a bare set directory has no
  extension; `resolve_input()` sniffs the directory and requires exactly one
  `.ifo` (zero/several → one-line error naming what was found), otherwise
  passes the `.ifo` path through. Usage notes for all three formats live in
  the module docstring (playbook link-up is ticket 06).
- Tests: `ai/test_goldendict_convert.py` grows 7 cases — DSL round trips in
  UTF-8 and UTF-16LE-with-BOM (identical records from both encodings),
  StarDict via `.ifo` path (`.dict.dz` + `.syn` → `alts`) and via directory
  (plain `.dict`), the two ambiguous-directory errors, and a committed-
  fixture sync block. New committed fixtures:
  `ai/fixtures/goldendict/{dsl-utf8.dsl, dsl-utf16le.dsl, dsl.expected.jsonl}`
  (DSL is text — hand-written) and
  `ai/fixtures/goldendict/stardict/{sample.ifo, .idx, .dict.dz, .syn}` +
  `stardict.expected.jsonl` (hand-built by a minimal StarDict writer in the
  test module, spec-shaped `word\0 + BE u32 offset + BE u32 size` idx
  records; the `.dict.dz` is a plain deterministic gzip stream — dictzip is
  gzip-compatible and pyglossary reads it with `gzip.open`). Suite:
  `docker exec ext_python python -m pytest /app/ai -q` → 157 passed.

Python-service addition only (no Laravel code) — same as ticket 01, this is
a log entry only.

## 2026-10-14 (goldendict-import ticket 03 — bilingual import)

Third commit of the goldendict-import feature (branch `goldendict-import`):
bilingual mode for `goldendict:import` — the `--target-lang=` flag is now
live:

- `laravel/app/Classes/GoldenDictParser.php` — constructor takes an
  optional target language; `parseEntry` extracts translation candidates
  from each article (block segments minus bracketed/slash transcription
  runs, standalone grammar tokens — POS abbreviations plus f/m/c/pl/sg
  gender tags — and the leading headword, then split on commas/semicolons
  into pieces surviving the `FetchWordTranslations::usableCandidates`
  mirror: no `[.,;:]`, `mb_strlen ≤ 256`; bare-IPA and numeric-only
  pieces dropped). Flush gains three bilingual phases: target words via
  batched `Word::upsert` on `(word, language_id, word_class_id)` with
  update `['l_word']` (the bulk equivalent of the per-word
  `firstOrCreate` triple; source class slug mapped into the target
  language with unseen-lookup auto-creation, multi-word candidates →
  `phrase` per ADR 0059, `l_word` through `WordLookupKey` so Cyrillic
  stress marks stay in the display form only); canonical
  `word_translations` links (`word_a_id < word_b_id`, ADR 0020) via one
  batched upsert per flush counting only genuinely-new pairs (the
  `LinkTranslationsCommand` conventions — no per-row `link()`); and
  `words.translations` JSON staging in the flat-deduped Wiktionary shape —
  candidates travel with the word upsert's INSERT side (the `['l_word']`
  update list keeps existing rows' JSON intact) plus a merge pass that
  unions re-imported candidates, never clobbers. Non-dry bilingual runs
  clear unmatched entity tokens for both languages. New stats:
  translation candidates / target words / links (in the table and the
  heartbeat line).
- `laravel/app/Console/Commands/ImportGoldenDictCommand.php` —
  `--target-lang` validated against the registry and required to differ
  from `--lang`; `--no-link` skips the bilingual phases entirely (the
  parser gets no target language); only `--profile` remains an inert
  stub. Stats table extended.
- Tests: `laravel/tests/Feature/ImportGoldenDictCommandTest.php` +9
  bilingual tests (end-to-end brand-new target language, popup payload
  via `words.show`, canonical link order with a pre-existing lower-id
  target word, junk-candidate rejection, idempotent re-run + staged-JSON
  merge, `--no-link`, `--target-lang` validation, Cyrillic stress-mark
  display/l_word split, bilingual dry-run) with fixtures
  `tests/Fixtures/goldendict-bilingual-en-de.jsonl` and
  `goldendict-bilingual-en-ru.jsonl`; `tests/Pest.php` `createLanguages`
  gained an opt-in `de` entry. `composer run test:tia` green except the
  same 10 pre-existing live Google Translate 403 failures ticket 02
  documented (clean-tree reproduced there).

Wiki: `wiki/domains/dictionary-import.md` (bilingual component bullet,
linking-difference note in the comparison section) and
`wiki/playbooks/import-dictionary-data.md` (bilingual usage in the
GoldenDict steps) updated; `wiki:validate` conformant.

## 2026-10-14 (goldendict-import ticket 02 — monolingual import)

Second commit of the goldendict-import feature (branch `goldendict-import`):
stage 2 of the pipeline, the artisan side that ingests ticket 01's JSONL
into the unified dictionary tables:

- `laravel/app/Console/Commands/ImportGoldenDictCommand.php` +
  `laravel/app/Classes/GoldenDictParser.php` —
  `goldendict:import {file} --lang= [--batch-size=500] [--max-entries=]
  [--dry-run]` imports a converted `{"word","alts","html"}` JSONL:
  headwords → `words` (batched upsert on
  `(word, language_id, word_class_id)`, `FREQUENCY_UNRANKED` sentinel for
  new rows), plain-text article segments → `definitions` (500-char cap),
  IPA in brackets/slashes → `transcriptions` (auto-created per-language
  `ipa` type), `alts` → `forms`. Article HTML is parsed with native
  `DOMDocument`/`DOMXPath` (text nodes grouped by nearest block ancestor,
  POS abbreviations from pos-classed/`<i>`/`<abbr>` elements); multi-word
  headwords → `phrase` (ADR 0059); unseen lookups auto-create; heartbeats
  every 100k entries; `--dry-run` writes nothing (not even lookups) and
  reports the would-be counts. `--target-lang`/`--profile`/`--no-link`
  are accepted but inert until the bilingual/profile tickets. Re-imports
  refresh `l_word` only — curated frequency ranks survive, and
  `clearUnmatchedForLanguage` gives entity tokens another linking chance.
- `laravel/app/Support/WordLookupKey.php` — the `l_word` normalization
  (lowercase + combining-mark strip via `StressMarks`) factored out of
  `WiktionaryParser` and now shared by both importers.
- **Bug fix in `WiktionaryParser::insertNewOnly`**: the existing-rows map
  was filled inside `each(fn …)`, and arrow functions capture by value —
  the map stayed empty, so the documented "re-imports text-dedupe" never
  fired and re-imports duplicated definitions. Both parsers now fill the
  map in a plain `foreach`; verified empirically on the testing DB (two
  imports of one gloss → one row).
- Tests: `tests/Feature/ImportGoldenDictCommandTest.php` (validation,
  happy path on the hand-written fixture
  `tests/Fixtures/goldendict-sample.jsonl`, brand-new-language lookup
  auto-creation, dry-run, `--max-entries`, idempotent re-run +
  frequency-rank preservation, inert flags) and
  `tests/Unit/WordLookupKeyTest.php`. Targeted suites green (45 passed /
  188 assertions incl. the wiktionary import suites);
  `composer run test:tia` green except 10 failures that also fail on the
  clean tree (live Google Translate 403s, pre-existing, unrelated).

Wiki: `wiki/domains/dictionary-import.md` (GoldenDict components +
`WordLookupKey` + dedupe-fix note) and
`wiki/playbooks/import-dictionary-data.md` (GoldenDict entry-point
section) extended; `wiki/reference/commands.md` regenerated via
`wiki:sync` (timestamp-only churn on models/web-routes references
reverted); `wiki:validate` conformant.

## 2026-10-14 (goldendict-import ticket 01 — MDX→JSONL converter)

First commit of the goldendict-import feature (branch `goldendict-import`):
the format-normalizing front door of the new dictionary-import pipeline, pure
python-service addition (no Laravel code yet — `goldendict:import` is ticket
02, and ticket 06 does the feature's wiki sweep including
`playbooks/import-dictionary-data.md`):

- `docker-compose/python/ai/goldendict_convert.py` — CLI that streams a
  GoldenDict/MDict `.mdx` into a JSONL of
  `{"word": str, "alts": [str], "html": str}` records written next to the
  source (`<input>.jsonl`, overridable as the 2nd argument). Runs on the host
  via the bind-mounted venv (`ai/venv/bin/python ai/goldendict_convert.py`),
  so dev usage needs no container rebuild; entries stream through pyglossary
  direct-read mode (one entry in memory at a time). Alternate headwords come
  from MDX `@@@LINK=` redirects (folded into `alts` by the reader);
  `entry://` links land rewritten as `bword://`. Regcode-encrypted MDX
  (header `Encrypted` flag) is rejected up front with a one-line error —
  pyglossary would brute-force the key blocks or silently skip encrypted
  records, and neither is an importable result. Output is written via a
  `.part` file and atomically renamed, so a failed run leaves no partial
  JSONL for the importer to pick up.
- `pyglossary==5.4.2` pinned in `docker-compose/python/requirements.txt`
  (stdlib-only core; no pre-installed package touched) — installed into the
  dev venv and the running `ext_python` container; the image rebuild + stamp
  at the next deploy bakes it (deploy note is ticket 06).
- Tests: `ai/test_goldendict_convert.py` (CLI subprocess seam) — pyglossary
  5.x has **no MDX writer** (read-only plugin), so the committed fixture
  `ai/fixtures/goldendict/sample.mdx` is produced by a minimal MDX 2.0 writer
  (zlib + struct) kept in the test module, with a hand-written expected
  `sample.expected.jsonl`; a sync assertion keeps the committed binary and
  the writer's `ENTRIES` from drifting apart. Suite:
  `docker exec ext_python python -m pytest /app/ai -q` → 150 passed.

No existing python-service concept covers converter scripts (alignment and
enrichment concepts are scope-specific), so per the ticket this is a log
entry only.

## 2026-10-14 (anki connection 05: knowledge-base closure — ADR 0077, GLOSSARY.md, anki-export concept)

The Anki-connection feature (tickets 01–04) closed out documentation-first,
no feature code touched. **ADR 0077** (`docs/adr/0077-browser-side-ankiconnect.md`)
records the two decisions a future reader would otherwise re-litigate — the
browser is the only AnkiConnect caller because the server's topology
(Docker/WSL2 dev, remote prod) can never reach a user's desktop Anki, and
the values-always-sent + hide-class template semantics (Anki cannot remove
note-type fields, so a toggle is a template class, and re-syncing restyles
existing cards without re-export) — plus the app-owned "ExtApp Vocabulary"
note type (foreign field sets warn, never rewrite), find-before-add
duplicates, and ticket 04's native-language pitfall as a Consequences rule:
`buildNoteFields` compares language *codes* while settings store a numeric
`native_language_id` — mixing the units fails silently. **`GLOSSARY.md`**
created at the repo root (domain-modeling format): Anki-export terms only —
AnkiConnect, connection settings, card field, note type, card template,
hide class, template sync, send to Anki, sample card. **`wiki/domains/anki-export.md`**
is the feature's front-door concept (sources = the ADR + the five feature
files): why-browser-side, the four-step flow (settings → template → sync →
sends), and the boundaries (the twelve-field set is the schema version;
sends only add; Anki ops are never server-initiated) — cross-linking
`profile.md` (tab side) and `interactive-words.md` (popup send) rather than
duplicating them, and linked from `wiki/index.md`. Both linked concepts
gained ADR 0077 source entries + inline pointers with stamp bumps.
`wiki:validate` → 0 warnings; `WikiTest` green.

## 2026-10-14 (retro fixes 02–04: Wiktionary idempotency test, orchestration doc, wiki warning burn-down 88 → 0)

Retro-fixes branch, three more findings closed. **02** — regression test for
the Wiktionary re-import dedupe at the command level
(`ImportWiktionaryCommandTest`, import ×3 → stable counts + zero duplicate
`(word_id, lower(definition))` groups), mutation-checked: reintroducing the
by-value `$existing` capture makes it the exactly-one failure. **03** —
`docs/agents/orchestration.md` (local-only dir like its siblings) records
agent-type session-start registration, why worktree parallelism is banned
here, and disk-anchored resumable dispatches. **04** — the wiki warning
burn-down: `wiki:validate` went from 88 standing warnings to **0**, so it is
a quiet pass/fail signal again. The 88 decomposed into 4 dead source paths
in `ai-providers.md` (migrations squashed into `2026_09_10_000002`) and 84
mtime-drift warnings over 23 concepts (48 genuine, 36 checkout artifacts).
All 23 concepts (plus the two dictionary-import concepts the goldendict
review commit re-staled) were re-verified against current code by read-only
agents; verified claims got `verified: { by: agent:zcode }` stamps (existing
`human:alex` verifications preserved), `generated.at` re-stamped past source
mtimes. Substantive content fixes: **`production-deployment.md` and
`log-doctor.md` still documented the Docker prod machine that
`deploy.yml` abandoned on 2026-09-14** — the playbook now leads with the
native model (`deploy-native.sh`: pull-first, flock, ai_env pip self-heal,
python restart + `/health` gate only on python diffs, 4 seeds,
daemon-reload + unit restarts) and marks the deploy.sh/stamp-guard sections
as Docker-layout; log-doctor gained a stale-layout warning (its workflow
still targets Docker prod, needs porting). Also corrected: alignment editor
is now the Inertia page with `canEditMatch` mutation gating (13 endpoints,
approved-side freeze) and work-nested routes (`EditEntityAlignment` and flat
`/alignments/{id}` gone) across `sentence-alignment` / `entities-alignment`
/ `run-alignment` / `frontend` / `overview`; signature model split
(BGE-M3 active for signatures via env, LaBSE code default / align-only) in
`docker-services` / `overview` / `run-alignment` / `sentence-alignment`;
`coding-conventions` middleware rule (dir exists, no Kernel) and the
nonexistent CLAUDE.md/copilot references; `profile` tab-redirect behavior;
`access-control` pivot name + registration redirect; `bilinguals-simulator`
component layout; `interactive-words` explain-endpoint source path; minor
precision in `entities` (illustration hash in text digest) and the
dictionary-import pair (bracket-trim scoped to transcriptions, DSL UTF-16BE).
`wiki:validate` exit 0 / 0 warnings; `WikiTest` green.

## 2026-10-14 (retro fixes 01: hermetic test baseline — suite green on a clean tree)

Fixed the broken green baseline (retro finding #1): ten tests across five
Feature suites (WordEndpoint, RefreshEntityWordsJob, AdoptEntityWords,
EntityEnrichment, WordTranslationFetch) failed on every run with a live
Google Translate `403 API_KEY_SERVICE_BLOCKED`. Root cause was not missing
`Http::fake()` per se but an env leak: `phpunit.xml` force-blanked
`OPENROUTER_API_KEY` only, so dev `.env`'s real Google key leaked into tests
as a "configured" provider pointed at `translation.googleapis.com`, and
`QUEUE_CONNECTION=sync` ran `FetchWordTranslations` inline inside the
request — nine tests died on the propagated 403, and the tenth
(`WordTranslationFetchTest` "does not queue a fetch when no provider is
configured", a `Queue::assertNotPushed`) failed on the same leak and was
unfixable by faking. Fix: force-blank `GOOGLE_TRANSLATE_API_KEY` and
`YANDEX_TRANSLATE_API_KEY` in `phpunit.xml` (tests opt in per test via
`enableTranslationProviders()` + `Http::fake()`), and arm
`Http::preventStrayRequests()` for every Feature test in `tests/Pest.php`
so any future leaked wire call throws immediately instead of hanging.
Zero test-body edits needed — the five suites pass standalone (62 passed)
and the full `composer run test` is green (1,149 passed / 0 failed, 334.6s,
8 workers) for the first time since the translate-fetch feature landed; no
additional network leakers surfaced under the guard. Contract recorded in
`wiki/playbooks/running-tests.md` ("The suite is hermetic — and green"):
on a clean tree, any failure is yours.

## 2026-10-12 (code-review fixes — popup-card-redesign)

One review-fix commit on `popup-card-redesign` addressing the feature's
code-review findings, no behavior regressions intended:

- **Glossary vocabulary restored** on the popup card: ticket 03's
  `variantLabel` / `word-popup-variant(-label)` rename is reverted to
  `sectionLabel` / `word-popup-section(-label)` — CONTEXT.md's Form-of entry
  puts "variant" on its avoid list and the Word popup entry already speaks
  of "one section per Word class".
- **Extraction**: `TranslationLine`/`TranscriptionLine` collapsed into one
  `CollapsibleLine` (preview count, separator, class label, bracket
  decorator, empty placeholder); the two identical Explanation-guidance
  branches into `ExplainGuidance({messageKey})`.
- **Shared ask predicate**: the pane's auto-request guard and its guidance
  render now derive from one `canAskExplanation(explain)` in
  `lib/wordExplain.js`, pinned by `lib/wordExplain.test.js` (ADR 0066
  lib-only vitest scope — the guard logic is pure and lives in `lib/`).
- **Four-state gap closed**: an auto-ask pane renders its loading line from
  the first paint (no blank frame between mount and the fire).
- **Explain memo dedupes every state**: the page-lifetime `explainCache`
  now stores `loading`/`error` entries too (previously successes only), so
  re-activating the Explanation tab mid-request no longer double-POSTs and
  an error no longer auto-refires without a click; cache writes notify
  subscribers so a pane remounted mid-request adopts the outcome. "Ask
  again" and the error-state **Retry** (label per design-system voice;
  `word.explain_retry` reworded from "Try again" en/ru/be) both refetch.
- **Profile width variant**: the parallel `wide = false` booleans on
  `TabPanel`/`Card` became one `width = 'panel' | 'wide'` convention backed
  by `profileWidthClass()` in `ui.jsx`.
- **UI strings**: `word.more_translations`/`word.more_transcriptions`
  merged into one `word.more` (copy was identical in en/ru/be).

Checks: `npm run test` (vitest) green incl. the new `wordExplain` pins;
`vite build` green; `php artisan test tests/Feature/UiStringSeederTest.php`
and `tests/Feature/UiStringsTest.php` green; `php artisan wiki:validate`
conformant; `vendor/bin/pint --dirty` clean. Wiki: `interactive-words.md`
(class inventory `-section`/`-section-label`, Retry copy, memo semantics)
and `profile.md` (`width` variant) updated, `generated.at` bumped.

## 2026-10-12 (popup-card-redesign feature closure — ticket 05)

The popup-card-redesign feature is complete on branch `popup-card-redesign`
(one log entry per ticket below; this entry closes the feature). Shipped in
four commits: `1cb6ec0` (01) swapped the `/profile` frame cap
`max-w-3xl` → near-full-width `max-w-[1800px]` with per-tab panel capping
(`TabPanel wide`), `2144523` (02) made the Popups tab the only tab-wide card
and laid it out as a `lg` two-column grid (checkbox groups ~⅓ left, live
preview ~⅔ right, stacking checkboxes-above-preview below `lg`; the preview
wrapper gained the real `word-popup` structural class), `d9c6f94` (03)
reworked the shared `PopupContent` card in place — clicked-word-only title,
"WORD — pos" variant labels, translations as the primary line with a calm
no-translations placeholder, main transcription with a "+N more" toggle,
three counted definitions/examples/etymology collapses, inside-positioned
`<ol>` markers, and a stable `word-popup-*` class on every card element —
and `8fa2758` (04) made the Explanation tab auto-request on activation while
respecting the page-lifetime explain cache (manual button gone, retry
re-labeled `word.explain_retry`). Because the reader, the simulator, and the
profile preview all render the same exported `PopupContent`, the card
changes landed everywhere at once. Payload, routes, models, artisan
commands, and the 11 `ui_settings.popup` visibility keys are unchanged
(verified against the branch diff) — machine-owned `wiki/reference/` needed
no `wiki:sync`. Closure sweep re-verified `wiki/domains/profile.md` and
`wiki/domains/interactive-words.md` line-by-line against the shipped JSX
(only drift: profile.md still said the preview sits "below the checkboxes" —
fixed; the card-content claims and the full `word-popup-*` class inventory
checked out as written), `generated.at` bumped on both; CONTEXT.md's
"Context explanation" glossary entry no longer calls the request "manual"
(vocabulary already names the card "Word popup", so no new glossary term was
added). Checks (closure): `vendor/bin/pint --dirty` clean (dirty set is
markdown-only — no PHP to format); `php artisan wiki:validate` conformant.
Full suite, `npm run build`, and the spec status line run as the
orchestrator's post-closure gate.

## 2026-10-12 (Explanation tab auto-request — popup-card-redesign ticket 04)

Clicking the word popup's Explanation tab now fires the AI context explain
request immediately; the "Explain this word" button is gone
(`Components/WordPopup.jsx`, `ExplainPane`). Tab activation mounts the pane
(`PopupContent` renders it only while the tab is active), so a []-deps
effect with a `firedRef` guard fires once per activation and never on
re-renders; the guards (preview, `enabled === false`, `modelKey == null`,
`sentenceId == null`, non-null initial `explanation`) make the fire a no-op
when there is nothing to ask and are what respect the page-lifetime
`explainCache` — re-activating the tab with a memoized answer renders it
instantly with zero requests. `request()` itself, the cache Map/key format,
the backend (`POST /ai/word-explain`, 20/min throttle), and the preview's
canned `word.explain_preview` answer (zero network) are untouched. Loading,
the error branch + retry, and "Ask again" stay; the retry button is
re-labeled to a new `word.explain_retry` ("Try again"/"Попробовать
снова"/"Паспрабаваць зноў") so the now-unused `word.explain` could be
dropped from `database/seeders/ui-strings/words.php` (re-seeded via
`UiStringSeeder`, whose `UiStrings::flush()` refreshed every locale map;
the stale `ui_string_keys`/`ui_strings` rows for `word.explain` were then
deleted by hand since the seeder never prunes). Explain-pane elements got
their stable classes per ticket 03's inventory: `word-popup-explain`,
`-explain-guidance` (both guidance branches), `-explain-loading`,
`-explain-error`, `-explain-retry`, `-explain-answer`, `-explain-empty`,
`-explain-again`. Checks: `php artisan test
--filter=AiWordExplainEndpointTest` 12 passed (57 assertions, backend
unchanged); `php artisan test --filter=WordEndpointTest` 18 passed (90
assertions); `php artisan test --filter=UiStringSeederTest` 5 passed (22
assertions, seeder change); `npm run test` (vitest) 47 passed — lib-only,
`WordPopup.jsx` has no JS tests; `vendor/bin/pint --dirty` pass;
`npm run build` compiles; `wiki:validate` conformant.
`wiki/domains/interactive-words.md` refreshed (auto-request bullet,
explain classes in the inventory), `generated.at` bumped.

## 2026-10-12 (Word popup card content rework — popup-card-redesign ticket 03)

The shared `PopupContent` (`Components/WordPopup.jsx`) is reworked in place,
so the reader, the simulator, and the profile preview pick the card up
verbatim. The title shows the clicked word only (the POS span moved out);
each variant section labels itself "WORD — pos" ("MELT — verb" for base
groups, "MELTED — adjective" for popup-headword sections — headword
uppercase, POS lowercase, em dash; `sectionLabel` became `variantLabel` and
the h3 dropped its `uppercase` utility). Per-variant order is now
translations → transcriptions → collapses: translations are the primary
line (8-preview cap + "+N more…" kept; an empty variant shows the new calm
`word.no_translations` note — the background auto-fetch lands on the next
open), transcriptions show the main value with a `+N more` toggle
(`TranscriptionLine`, remaining count), and definitions/examples/etymology
are three independent counted `<details>` collapses ("Definitions (N)" /
"Examples (N)" / "Etymology (N)", closed by default, per-variant state;
`word.examples`/`word.etymology` gained `:count`, new keys
`word.definitions`, `word.no_translations`, `word.more_transcriptions` —
seeded en/ru/be and re-seeded via `UiStringSeeder` with cache forgets). The
definitions `<ol>` uses inside-positioned markers (`ml-4` dropped,
`list-inside` added) so ≥2-digit numbers can't spill past the card. Every
card element now carries a stable `word-popup-*` class (full inventory in
`wiki/domains/interactive-words.md`; the six `word-popup-explain-*` names
are reserved for ticket 04). `SAMPLE_DATA` in `PopupsSettings.jsx` now
exercises a multi-transcription variant, 10 translations on one variant, a
zero-translations variant, and multi-item examples/etymology. Two side
effects of the seeding mandate, both outside the ticket's file list: the
dev DB's `languages` registry predated the `be` row (en/ru/fr only), so
`LanguageSeeder` (idempotent upsert) was run before `UiStringSeeder` could
seed be texts; and re-seeding exposed a latent `UiStrings::build()` bug —
the English-under-the-locale layering relied on Postgres scan order (no
ORDER BY), so the rewritten en rows of a re-seed floated above their locale
values and the dev `ru` map went all-English. Fixed with an explicit
locale-last ORDER BY, TDD'd red→green with a new
`UiStringsTest` case (en row updated after the locale row exists).
Checks: `php artisan test --filter=UiStringsTest` 8 passed (12
assertions), red first (1 failed) against the unfixed build; `php artisan
test --filter=WordEndpointTest` passed (payload untouched);
`npm run test` (vitest) green; `vendor/bin/pint --dirty` pass;
`wiki:validate` conformant. `wiki/domains/interactive-words.md` (card
content + class inventory) and `wiki/domains/localization.md` (mapFor
ordering) refreshed, `generated.at` bumped on both.

## 2026-10-12 (Profile: two-column Popups tab — popup-card-redesign ticket 02)

The Popups tab is now the only tab-wide card in the profile. `ui.jsx`'s
`Card` gained a `wide` prop (default false): the pre-existing inner
`max-w-xl` cap becomes opt-out, so the six regular cards are pixel-identical
while the Popups card spans the full frame. `Edit.jsx` passes `wide` to the
popups `TabPanel` (lifting ticket 01's `max-w-3xl` panel cap) and to its
`Card`. `PopupsSettings.jsx` wraps checkbox groups + live preview in a
responsive grid: `lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]` with
`lg:gap-10` puts the groups on the left ~⅓ and the preview on the right ~⅔;
below `lg` the container is a plain block, so it stacks
checkboxes-above-preview exactly as before (`mt-8`/`space-y-6`/`mt-10`
spacing preserved). The preview wrapper gains the `word-popup` structural
class — same as the real portal wrapper (`WordPopup.jsx`), minus its
`absolute z-50` positioning — while keeping the inline
`width: min(560px, 100%)`, the popup font size, and the aria-label.
Checkbox ids (`popup-section-<key>`), `CheckboxRow`, and the debounced
`useUiSettingsAutosave('popup', …)` hook are untouched. No user-facing
strings changed. Checks: `php artisan test --filter=ProfileTest` 12 passed
(80 assertions) and `php artisan test --filter=UiSettingsTest` 14 passed
(101 assertions), before and after; `npm run build` green with
`lg\:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]{grid-template-columns:minmax(0,1fr) minmax(0,2fr)}`
present in the built CSS; `vendor/bin/pint --dirty` pass (dirty set is
JSX-only, nothing for Pint to reformat). `wiki/domains/profile.md`
refreshed (Popups-tab layout + `word-popup` preview class, generated.at
bumped).

## 2026-10-12 (Profile: wide page frame — popup-card-redesign ticket 01)

The `/profile` page frame is no longer a narrow column: `Edit.jsx` swapped
the frame cap `max-w-3xl` (768px) for a near-full-width `max-w-[1800px]`
(soft cap, `px-4 sm:px-6 lg:px-10` side padding and centering kept), so on
large screens the page header and tab strip span the window. All five tabs
keep today's content look: a new `TabPanel wide` prop (default capped) puts
`max-w-3xl` on each tab panel so card borders stay modest, and the
pre-existing `max-w-xl` inner cap in `Card` keeps form content at 576px.
Popups tab still capped in this ticket — its two-column widening is ticket
02, which flips exactly one call site to `wide`. Layout only: no behavior,
props, routes, or other files touched. Checks: `php artisan test
--filter=ProfileTest` 12 passed (80 assertions) before and after;
`npm run build` green with `max-w-[1800px]{max-width:1800px}` in the built
CSS; `vendor/bin/pint --dirty` pass (dirty set is JSX-only, nothing for
Pint to reformat). `wiki/domains/profile.md` refreshed (generated.at
bumped).

## 2026-10-11 (Python service: hand-rolled test scripts ported to pytest)

The 8 plain-python test scripts under `docker-compose/python/ai/` are now a
pytest suite (146 tests, ~5s in `ext_python`, no model weights): pytest added
to `requirements.txt` (image rebuilt), `ai/pytest.ini` + `ai/conftest.py`
(shared `StubModel`, autouse `_EMBEDDING_CACHE` reset + `CACHE_MODELS=1` pin,
`env_file` fixture for config's file-first live accessors, `api_client`
fixture with app.state snapshot/restore). All suites ported
(`check_*`/`main()` runners → `test_*` functions; `monkeypatch` replaces raw
`os.environ` mutation; `test_enrichment.py` restructured from import-time
checks into classes with silero/spacy skip markers). The previously broken
`test_splitter.py` (gitignored production fixtures) now runs on committed
synthetic fixtures (`ai/splitting/fixtures/*.txt`, gitignore exception)
reproducing both regressions — verified they fail under flatten-all /
newline-as-boundary. New coverage: `/align` + `/embed` wire contracts, `/health`
probe, aligner `process()` file flow (chunked commit + global index offsets).
Deleted dead `ai/similarity/` (only stale `__pycache__` remained). Manual runs
only for now (no CI job). Suite run twice consecutively green (state-leak
check). Wiki: running-tests playbook gained the Python section;
sentence-alignment/sentence-enrichment/docker-services/overview refreshed
(stale dev venv name `ai_env` → actual `ai/venv`; prod native path genuinely
uses `ai_env/`, unchanged); AGENTS.md Python test command + venv fix.

## 2026-10-11 (tag pools: feature closure — verification pass across all four pools)

Verification pass closing the tag-pools feature (ADR 0076) — no feature code
changes. All four pools verified as one feature on branch `tag-pools`: words
(`tags` + `tagged_words`, the renamed dormant scaffold), works (`work_tags` +
`tagged_works`), entities (`entity_tags` + `tagged_entities`), and alignments
(`alignment_tags` + `tagged_alignments`) — each with its Filament tag
resource and its attached model's tags multi-select with inline create,
badge column, and multi-select filter. Full plain parallel suite green:
1,120 passed (5,736 assertions, 8 processes). Operational note: the suite
now exceeds composer's default 300-second process timeout — the first
`composer run test` attempt was killed at 300s mid-run, and the immediate
re-run failed 457 tests with missing-relation errors (`relation
"ui_string_keys" does not exist`) because the killed run left half-migrated
`ext_app_test_test_*` worker databases behind; that failed pass still
dropped them at its end, and the next clean run was fully green. Until the
duration is addressed, run the suite as `composer run test --timeout=0`.
`wiki:validate` conformant (exit 0; the 85 staleness warnings are
pre-existing, same count as the pool tickets reported). `wiki:sync`
produced timestamp-only churn on the three machine-owned reference files
(no model/route/command changes) — content verified identical, churn
reverted. Pint clean (`--test`) on all 40 PHP files the feature's four
commits touched, listed explicitly rather than via `--dirty`. Dev database
brought current with plain additive `php artisan migrate` (pending
`2026_10_11_000002/000003/000004` applied without error; the ticket-01
pivot rename was already in place); the post-migrate tinker check reads
`pgsql tagged_words=0 word_tags=gone pools=all-present` — the renamed words
pivot carries zero rows (no orphans; the scaffold was dormant) and all six
new tables exist. Learner-facing tag UI, tag-name translations, seed
vocabularies, and work→entity tag inheritance remain out of scope per
ADR 0076.

## 2026-10-11 (tag pools: alignment-tags pool — Alignment Tags resource + first Sentence Alignments edit page)

The alignments pool went live, fourth and last of the four per-type pools
(ADR 0076), plus the first-ever edit surface for `EntityMatchResource`.
Migration `2026_10_11_000004` created both tables in one additive migration:
`alignment_tags` (definition table mirroring `tags`/`work_tags`/`entity_tags`
— `slug` 100 unique, `name` 256, timestamps) and the `tagged_alignments`
pivot (`entity_match_id` → entity_matches and `alignment_tag_id` →
alignment_tags, both cascade, unique `(entity_match_id, alignment_tag_id)` =
`uk_tagged_alignments_pair`, timestamps). `AlignmentTag` model (fillable
slug+name, inverse `alignments()` with `withTimestamps`) +
`AlignmentTagFactory`; `EntityMatch::tags()` resolves through
`tagged_alignments`. New `AlignmentTagResource` ("Alignment Tags", Entities
navigation group): required name (256) + globally unique slug (100),
`alignments_count` usage column, edit/delete + bulk delete. The Sentence
Alignments list gained a `tags.name` badge column and a multi-select tags
relationship filter (OR semantics) — its first filter; tags are flat labels
orthogonal to the pipeline status lifecycle, which is untouched.
`EntityMatchResource::form()` restructured per operation: the Entities
section (entity-pair picks) and the chunk_size/max_n knobs are now
`visibleOn('create')` (create-only and otherwise unchanged); a tags
multi-select with inline create (`createOptionForm`, relationship default
createOptionUsing) joins the form — the edit page's editable field set is
exactly that multi-select, and hidden fields are excluded from dehydration
and validation, so a match's pair and knobs can never leak through an edit
save. The new `EditEntityMatch` page (`/{record}/edit`) opens from a table
Edit record action given an explicit `->url(...)` so it navigates to the
page instead of opening Filament's default edit modal; the React alignment
editor stays the one row-editing surface (ADR 0062). Tests:
`AlignmentTagsTest` mirrors the pool set — sync no-duplicate, unique pair
constraint, pivot timestamps, inverse relation, cascade deletes both
directions, resource list/create, edit-form persistence, pair/knob
non-leak, inline create + attach, filter narrowing, badge render, edit
action targets the page. Docs: ADR 0076,
`wiki/database/entities-alignment.md`.

## 2026-10-11 (tag pools: entity-tags pool — Entity Tags resource + Entities tags UI)

The entities pool went live, third of the four per-type pools (ADR 0076),
copying the work pool's shapes verbatim. Migration `2026_10_11_000003`
created both tables in one additive migration: `entity_tags` (definition
table mirroring `tags`/`work_tags` — `slug` 100 unique, `name` 256,
timestamps) and the `tagged_entities` pivot (`entity_id` → entities and
`entity_tag_id` → entity_tags, both cascade, unique
`(entity_id, entity_tag_id)` = `uk_tagged_entities_pair`, timestamps).
`EntityTag` model (fillable slug+name, inverse `entities()` with
`withTimestamps`) + `EntityTagFactory`; `Entity::tags()` resolves through
`tagged_entities`. New `EntityTagResource` ("Entity Tags", Entities
navigation group like `EntityResource`): required name (256) + globally
unique slug (100), `entities_count` usage column, edit/delete + bulk
delete. The Entities form gained a searchable tags multi-select with inline
create (`createOptionForm`, relationship default createOptionUsing) placed
with the metadata fields; the Entities list gained a `tags.name` badge
column and a multi-select tags relationship filter (OR semantics) joining
the existing language/work/restriction/approval filters. Tags stay
orthogonal to the entity lifecycle — the deletion guard and status logic
are untouched. Tests: `EntityTagsTest` mirrors `WorkTagsTest` — sync
no-duplicate, unique pair constraint, pivot timestamps, inverse relation,
cascade deletes both directions, resource list/create, form persistence,
inline create + attach, filter narrowing. Docs: ADR 0076,
`wiki/database/entities-alignment.md`.

## 2026-10-11 (tag pools: work-tags pool — Work Tags resource + Works tags UI)

The works pool went live, second of the four per-type pools (ADR 0076),
copying the words tracer bullet's shapes verbatim. Migration
`2026_10_11_000002` created both tables in one additive migration:
`work_tags` (definition table mirroring `tags` — `slug` 100 unique, `name`
256, timestamps) and the `tagged_works` pivot (`work_id` → works and
`work_tag_id` → work_tags, both cascade, unique `(work_id, work_tag_id)` =
`uk_tagged_works_pair`, timestamps). `WorkTag` model (fillable slug+name,
inverse `works()` with `withTimestamps`) + `WorkTagFactory`;
`Work::tags()` resolves through `tagged_works`. New `WorkTagResource`
("Work Tags", Entities navigation group like `WorkResource`): required name
(256) + globally unique slug (100), `works_count` usage column, edit/delete
+ bulk delete. The Works form gained a searchable tags multi-select with
inline create (`createOptionForm`, relationship default createOptionUsing);
the Works list gained a `tags.name` badge column and a multi-select tags
relationship filter (OR semantics) — the Work table's first filter. Tests:
`WorkTagsTest` mirrors `WordTagsTest` — sync no-duplicate, unique pair
constraint, pivot timestamps, inverse relation, cascade deletes both
directions, resource list/create, form persistence, inline create + attach,
filter narrowing. Docs: ADR 0076, `wiki/database/entities-alignment.md`.

## 2026-10-11 (tag pools: word-tags tracer bullet — pivot rename + Word Tags admin surface)

The dormant words-tag scaffold became a live admin feature (ADR 0076,
first of four per-type pools — words/works/entities/alignments, no shared
table, no polymorphism, no inheritance, language-neutral slug+name, ship
empty). The pivot renamed `word_tags` → `tagged_words` with its unique pair
index → `uk_tagged_words_pair` (migration `2026_10_11_000001`; safe — zero
callers verified before the rename) so it can no longer collide one letter
away from the future `work_tags` definition table; all pools use
`tagged_<model>` pivots. `Tag` grew fillable slug+name and the inverse
`words()` relation; `Word::tags()` resolves through `tagged_words` with
`withTimestamps()` (the pivot's timestamp columns were never filled
before). New `TagResource` ("Word Tags", Words group): required name (256) +
globally unique slug (100), `words_count` usage column, edit/delete + bulk
delete. The Words form gained a searchable tags multi-select with inline
create (`createOptionForm`, relationship default createOptionUsing); the
Words list gained a `tags.name` badge column and a multi-select tags
relationship filter (OR semantics). Tests: `WordTagsTest` — sync
no-duplicate, unique pair constraint, pivot timestamps, inverse relation,
cascade deletes both directions, resource list/create, form persistence,
inline create + attach, filter narrowing. Docs: ADR 0076, CONTEXT.md Tag
Pools context, `wiki/database/dictionary.md`.

## 2026-10-10 (Belarusian 07: release verification and knowledge-base closure)

Verification pass over the whole feature — no feature changes. Full plain
suite 1072 passed (5,585 assertions); TIA affected run exit 0 (1,072 passed,
1,072 replayed). Python in `ext_python`: `test_enrich_endpoint.py` 9/9,
`test_split_endpoint.py` 3/3, `test_registry.py` 13/13, `test_enrichment.py`
all pass, `test_engine_resolution.py` 6/6, `test_preprocessing.py` 4/4;
legacy `test_splitter.py` still fails only on its gitignored fixture texts
(`FileNotFoundError: fixtures/book_thief_en.txt`, `docker-compose/python/.gitignore`
`*.txt`). Pint clean on all 44 branch-changed PHP files. Seeder idempotency
re-proven on the testing connection (`ext_app_test`): `migrate:fresh --seed`
then a second `db:seed --class=DatabaseSeeder` exits 0 with identical counts
on all 41 tables (languages 3, ui_string_keys 508, ui_strings 1524).
`wiki:validate` conformant; `wiki:sync` produced timestamp-only churn on the
three machine-owned reference files (no route/model/command additions) and was
reverted. Consistency sweep: no language-literal guards left in
WordTranslations, LinkTranslations, or enrichment (the registry drives both
sides); remaining `en` literals are the designated interface fallback locale
(`Support/UiStrings.php`), the WiktionaryParser default target arg, the
Russian enricher's own registry row, and the intentional Cyrillic strip list
(`Support/StressMarks.php`: ru, be, uk). Leftover `wiki:validate` staleness
warnings on two feature concepts (reader.md, sentence-alignment.md) resolved
by review: content matched the final sources, `generated.at` refreshed to the
verification time.

## 2026-10-10 (Belarusian 06: all UI strings translated to Belarusian)

Every key in all 17 `database/seeders/ui-strings/*.php` partials (508 keys,
~509 source entries — `alignments.next_page` is declared twice) now carries a
`be` value alongside en/ru, so the seeded interface renders fully in Belarusian
with no English fallbacks left in the seeded set. Translations are standard
Narkamaŭka Belarusian (аканне/яканне, ў/у alternation, дзеканне/цеканне) built
from the ru semantics with the en tone/length as a guide; placeholders
(`:name`, `:status`, …), counts, and the ru quote style are preserved verbatim
(verified by a throwaway structural checker: key sets, en/ru byte-identity to
the pre-change snapshot, per-key placeholder multisets, quote styles).
Terminology is uniform: alignment = выраўноўванне, match = супастаўленне,
entity = тэкст (library/entities, mirroring ru) / сутнасць (alignments,
mirroring ru), work = твор, sentence = сказ (entities/library) / фраза
(alignments), stress marks = націскі, AI = ШІ, settings = настройкі. The one
ticket-05 be fixture (`reader.no_texts`) is kept. `UiStringSeederTest` swaps
its "reader.russian has no be row" case for a sampled key-per-group be
assertion (17 groups) and now exercises the valueless-language skip with a
synthetic interface-enabled `uk` row instead. Docs: `wiki/domains/localization.md`
updated. Tests: `UiStringSeederTest` 5 passed (22 assertions); full suite 1072
passed (5583 assertions).

## 2026-10-10 (Belarusian 05: interface-language plumbing)

Belarusian is now an interface language end to end. `LanguageSeeder` flips the
`be` row to `is_interface_enabled => true` (validation, Filament selects and
`PreferencesForm` were already registry-driven, so the flag alone makes be
selectable at `PATCH /profile/settings`). `UiStringSeeder`'s language loop is
registry-driven — it iterates interface-enabled languages by `sort_order`
instead of a hardcoded en/ru pair; keys without a value for a language are
skipped (the ADR 0023 English chain covers them at render time) and
updateOrCreate idempotency/admin-authority are unchanged. Seed data:
`WordClassSeeder` gains a `be` block (17 slugs mirroring en/ru: назоўнік,
дзеяслоў, прыметнік, прыслоўе, займеннік, прыназоўнік, злучнік, вызначальнік,
лічэўнік, выклічнік, артыкль, часціца, Уласнае імя, фраза, суфікс, прэфікс,
невядома) and `TranscriptionTypeSeeder` a be IPA row (МФА). The reader index
pending-alignment label no longer binary-branches en/ru: `ReaderController`
ships `languages` as `{code, name, native_name}` objects and the new pure
`lib/languageLabel.js` names en/ru via their ui-string keys and every other
language from the list (native name → name → raw code). Tests: seeder tests
for be rows (ui strings incl. the skip-disabled + missing-key cases, word
classes, transcription types), LanguageTest be-flag flip, ProfileTest
accepts-be, ReaderPageTest pins the prop shape, vitest `languageLabel.test.js`
(5). `composer run test:tia` 1070 passed.

## 2026-10-10 (Belarusian 03: be_stress stress enricher end-to-end)

Belarusian entities enrich like Russian ones. Python: the Cyrillic stress
machinery moved verbatim into a shared `ai/enrichment/cyrillic_stress.py`
(vowel-set parameter) that `ru_stress` closes over Russian letters — its
public surface (`mark_sentence`, `_dict_fallback`, `WORD_RE`,
`COMBINING_ACUTE`, `RU_VOWELS`, `strip_marks`, version 1) and behavior are
unchanged — and the new `be_stress` closes over the Belarusian set (і instead
of и; non-syllabic ў excluded; ё stressed by definition), version 1. The
registry grew a `be_stress` entry whose prepare loads the package's own
Belarusian-trained accentor (`ModelCache.stress_model('bel')`,
`accentor-bel.pt`, cached under `stress_model_bel`): the ticket's quality gate
found the Russian accentor marks Belarusian per Russian phonology (РаніцА́й,
прЫ́йшла, горА́д — garbage), while the bel accentor places most content-word
marks correctly (РАніцай, ракОй, прыйшлА, ГОрад, вадзЕ) with only clitic
noise — sample + verdict in the ticket. Laravel: `RussianStressEnricher`'s
body moved into an abstract `CyrillicStressEnricher` (behavior identical);
`BelarusianStressEnricher` is its `be_stress`/`[be]` face, added to the
manifest — Enrich action, sweep and stamps pick be up registry-driven, the
shared stress annotation vertical carries the display. `FakePythonClient`
serves `be_stress` in its keyed echo. Tests: python
`test_registry.py` 13/13, `test_enrich_endpoint.py` 9/9 (be happy path +
be 503), `test_enrichment.py` (be vowel-set fallback checks + real-bel-model
sentence checks); Laravel `BelarusianEnrichmentTest` (5 tests: be chunk +
versions, v/pv stamps + staleness, form/ё-headword hints, empty-dictionary
harmlessness, reader vertical), manifest test updated; `composer run test:tia`
1061 passed.

## 2026-10-10 (Belarusian prep 04: shared Cyrillic stress-mark normalizer)

One helper owns the "does this target language carry combining stress
marks" question and the stripping: `App\Support\StressMarks` —
`stripsFor()` answers true for the explicit Cyrillic list ru/be/uk
(false otherwise, unknown codes included) and `strip()` removes the
combining marks (`\p{M}`). The two normalizers that each hardcoded the
`=== 'ru'` check now call it: `WordTranslationProvider::normalizeCandidate`
(word-popup auto-fetch candidates, trim-only after the strip) and
`LinkTranslationsCommand::normalizeTargetWord` (dictionary-link lookups,
lowercase + trim after the strip) — so Belarusian translation candidates
and links normalize like Russian. Each call site's post-strip behavior is
unchanged. Tests at the existing seams: `WordTranslationProvidersTest`
(be/uk candidates stripped, en/de untouched, Http-faked) and
`LinkTranslationsCommandTest` (be-target link matches an unstressed
headword); `wiki/database/dictionary.md` updated to the shared list.

## 2026-10-10 (Belarusian prep 02: python enricher module registry)

`/enrich` dispatch is table-driven: the endpoint loops the request's keys
through a new `ai/enrichment/registry.py` (`EnricherModule`: `key` +
`version` = the module's `ALGORITHM_VERSION` + once-per-request `prepare`
(model load / batched spaCy computation; failures raise
`EnricherUnavailable` → 503, phrasal's batched hit lists paired to sentences
in order) + per-sentence `apply`) — the python mirror of Laravel's
`EnricherRegistry`, no per-key branches left in `ai/api/enrich.py`.
`EnrichRequest.enrichers` dropped its hardcoded `Literal` for a pydantic
field validator against `registry.keys()` (unknown keys → 422), so a future
analysis is one module plus one registry entry. ru/en behavior byte-identical
(wire shapes, versions, 503 detail strings and phrasal pairing preserved;
`versions`/`output` JSON key order now follows the request's `enrichers`
order — semantically identical, Laravel consumes per key). Tests:
`ai/enrichment/test_registry.py` (10 checks, container; 9 on a bare venv —
schema checks skip without pydantic), `ai/api/test_enrich_endpoint.py`
(7 checks — the wire-contract pin; API stack required). Laravel untouched;
`composer run test:tia` 1053 passed unchanged.

## 2026-10-10 (Belarusian splitting: per-language engine resolution + be language row)

The python splitter no longer runs one global engine for every language:
`config.splitter_engine_for(language)` resolves per language — an explicit
`SPLITTER_ENGINE_OVERRIDES` entry (`be=razdel,pl=pysbd`; malformed entries
and unknown engine names silently ignored), then the global
`SPLITTER_ENGINE`, except the global engine cannot serve the language (pysbd
has no `be` entry — today that 400'd the whole split request) and the
rule-based engine (razdel) is substituted automatically, so unknown codes
fall back instead of erroring. `/split`'s per-language instance cache
invalidates on the resolved engine (a razdel-backed cached be splitter is
reused, not rebuilt), and Belarusian shares the exact Russian noise-cleanup
preprocessing list. Laravel: `LanguageSeeder` seeds `be` (enabled,
interface-off for now, sort 2) and the test `createLanguages()` helper takes
an explicit code list (default en/ru unchanged). Tests:
`ai/splitting/test_engine_resolution.py` (6 checks, host venv),
`ai/splitting/test_preprocessing.py` (4), `ai/api/test_split_endpoint.py`
(3, container), `LanguageTest` seeder tests assert the be row,
`SentenceSplitterTest` streams+resumes a be entity asserting the `be`
language on the wire. Docs: `wiki/domains/sentence-alignment.md` Split step.

## 2026-10-09 (Refine round chunked + auto-triggered after the first alignment)

A whole book can carry hundreds of one-sided regions (the live 84-region
match ran ~4 minutes), more than one `RefineEntitySentences` execution fits
inside its 600 s timeout — so `AlignmentRefineService::refine()` is now a
*bounded run*: up to 50 regions and a 240 s wall-clock deadline (binding
only after the first region, so a run never reports `has_more` without
moving the cursor). The summary gained `has_more` + `cursor` — the a-side
sentence position past the last examined region, stable across applies
(unlike the appended `order` column) — the job self-chains on it
(`EnrichEntitySentences` pattern, 200-link cap), and `alignments:refine`
loops runs to completion per match. Every run still ends with
`repairCoverage()`, so each link leaves total coverage: every sentence in a
meaning match or a single-sided unmatched row. The align job's `finalize()`
now dispatches the refine once per match on its first completion, guarded by
a new nullable `entity_matches.refined_at` stamp that `refine()` writes when
a run actually starts (later re-aligns don't re-fire; button/command remain
the manual surfaces). Tests: `AlignmentRefineTest` grew to 22 (budget split
+ cursor no-refeed + sentence-completeness across the chain, zero-budget
deadline, chain push/stop/cap, auto-trigger once-guard, stamping). Docs:
`wiki/domains/sentence-alignment.md` refine section, `wiki/database/
entities-alignment.md` `refined_at`.

## 2026-10-09 (Refine gate fix — per-sentence coverage, not summed scores)

First production run of the refine round (entity_match 21) rejected all 84
regions and applied none: the acceptance gate compared raw summed match
scores, so a regrouping had to *beat the incumbent rows' similarities* —
the same raw-cosine bar that makes the greedy ladder lose fusions in round
1. The DP's whole point is the opposite trade (a 0.75 fused window covering
the orphan beats a 0.83 1:1 plus a zero-covered tail: skip penalty makes it
net-positive), and the gate vetoed it every time. The gate now scores
per-sentence coverage — each row's/match's similarity counted once per
sentence it covers, one-sided rows covering at 0.0 — so the accepted
regrouping must raise the region's mean sentence similarity; identical
groupings tie out (no churn), and the sub-0.45 veto stays as a safety net
(the DP structurally cannot emit sub-threshold matches: force −2.0 vs
double-skip −1.0). Per-region decisions now log old/new sums and scores.
Tests: new `accepts a fused window that scores below the head 1:1…`
captures the production veto; existing gate tests re-verified under the
coverage arithmetic.

## 2026-10-09 (Alignment refine round — dp + joined windows for one-sided rows)

Second alignment pass over a completed match, closing the fusion gap the
greedy ladder leaves behind: with no skip cost, round 1 commits a fusion's
strongest member as a 1:1 (measured on the smart-notes triplet, LaBSE:
EN↔RU1 0.8383) and leaves the tail one-sided, even though the joined 1:2
window scores higher (0.8684) — aggregate averaging dilutes toward the
shorter member and the ladder compares raw cosines. `AlignmentRefineService`
collects the one-sided machine rows (Needs-review shape + below the landmark
bar), builds bounded regions around them (±2 row context, merged, capped,
bounded by single-sided pins; two-sided pins go to python as `landmarks`),
and re-aligns each region via `alignChunkRemote()`'s new per-request
`algorithm=dp` + `window_embed=joined` overrides. A region replaces its rows
only on strict score-sum improvement (`new > old + 0.01`, sub-0.45 matches
reject the region outright — the DP can force garbage to save skip
penalties); writes go through `MeaningMatchStore` (machine-only replacement,
pins reserved) plus a final `repairCoverage`. Surfaces:
`alignments:refine {id?} {--all}` and an editor-endpoint dispatch
(`POST .../refine` → `RefineEntitySentences` job → "Refine 1-sided rows"
header button; UI strings seeded). Tests: `AlignmentRefineTest` (12 tests:
fusion regrouping, gate/floor rejections, pin landmarks, single-sided pin
boundary, status gate, command, endpoint access). Docs:
`wiki/domains/sentence-alignment.md` (refine-round stage).

## 2026-10-09 (Prepass anchor distance cap — far-heading anchors no longer teleport the cursor)

Diagnosed entity_match 20 ("How to take smart notes" EN↔RU, work 5): the
first 211 editor rows were one-sided/garbage while matches snapped correct
from row 212. Root cause in the python aligner's high-confidence prepass:
unbanded by design, it locked `Introduction` ↔ the RU part heading `Введение`
74 cells off the diagonal (LaBSE 0.93 vs 0.78 for the translator's actual
`Предисловие`) — a matrix-edge column wins the mutual-best check trivially —
which degraded the whole Предисловие to one-sided rows and desynchronized the
chunk cursors until the editions' density difference re-converged them.
Fix: `_prepass_anchors` now passes a distance cap (`ALIGN_MAX_ANCHOR_DISTANCE`,
live knob, default 30 cells off the expected length-ratio diagonal; `<= 0`
disables) to the shared `_find_anchors` band check, so a heading cognate can
no longer anchor arbitrarily far downstream while anchors keep their ability
to jump moderate edition differences. Regression tests in
`ai/alignment/test_aligner.py` (cap rejects a far 0.93 heading; disabled cap
reproduces the teleport). Alignment 20 re-run from scratch with the fix:
one-sided rows 326 → 152 (the rest are genuine citation/footnote gaps), the
head now matches `Introduction`↔`Предисловие` and
`Everybody writes.`↔`Все пишут.`, and the RU-only part headings (`ЧАСТЬ 1`,
`Введение`) land as one-sided rows.
Concepts updated: [sentence alignment](/domains/sentence-alignment.md).

## 2026-10-08 (Creation-module hardening: self-pair guard, uniform stale handling, limit coverage)

Follow-up review of the creation module surfaced three small gaps, now
closed. **Self-pair guard:** `EntityMatchCreationService::create()` now
throws `SelfEntityPair` ("An entity cannot be matched with itself.") when
both arguments are the same entity — previously only the Library form's
`different` rule blocked it; the Filament create form and the match list's
header action now declare `different` on their second entity select too
(the Find Match action needs none: its candidate query already excludes
the record), and all surfaces translate the exception into their own UX
(back-error on the Library form, danger notification on Filament).
**Uniform stale-entity handling:** the match list's header action and the
entity table's Find Match action now resolve their entity with `find()` +
"Entity not found." notification instead of `findOrFail()`'s 404, matching
the create page. **Limit coverage:** the Filament panel is authenticated,
not admin-gated, so the `ProcessingLimitReached` danger-notification branch
was reachable yet untested — each of the three Filament creation surfaces
now has a non-admin-at-the-limit test (the wrong "panel is admin-only"
comments in two tests and one wiki phrase corrected). New tests: module
self-pair, create-page self-pair form rejection, stale-submission backstop
on both actions, three limit notifications. Concepts updated:
[sentence alignment](/domains/sentence-alignment.md),
[entities](/domains/entities.md).

## 2026-10-08 (One Entity-match creation module)

Opening an Entity match — pairing two same-Work entities so the alignment
pipeline can run — now lives behind one module,
`laravel/app/Classes/EntityMatchCreationService.php`
(spec `.scratch/entity-match-creation/spec.md`; ADRs
[0019](../docs/adr/0019-same-language-entity-matches.md),
[0033](../docs/adr/0033-exact-copy-cloning-and-hash-based-alignment-reuse.md),
[0044](../docs/adr/0044-per-user-processing-limits.md)).
`create($creator, $first, $second, ?$chunkSize, ?$maxN)` returns
`created` / `created_from_copy` / `duplicate` (carrying the existing match)
or throws `CrossWorkEntityPair` / `ProcessingLimitReached`; same-Work
validation, canonical a/b sides, knob defaults (75/6), the Processing
limit, the copy-reuse decision, and the dispatch decision live only there.
All four creation surfaces became thin callers: the Library Alignments form
(`LibraryController::storeAlignment` — external behavior unchanged:
duplicate still a validation error with the `existing_match_id` flash,
limit still a `limit` error) and the three Filament surfaces (the
`EntityMatchResource` create page, the match list's "New Alignment" header
action, the entity table's "Find Match" action), which render outcomes as
notifications — on duplicate, one linking to the existing match. Behavior
changes: **duplicates are rejected everywhere and nothing ever deletes
meaning matches as a side effect of creation** — the Filament actions'
destructive delete-on-duplicate (which wiped existing matches including
their human-curated rows) is gone, and deletion stays the explicit
Delete / DeleteBulk / "Run from scratch" operator actions; the entity-page
Find Match action gains Alignment copy reuse (ADR 0033 — Exact-copy pairs
complete instantly there too); Filament creations now respect the per-user
Processing limit under the creator's locked user row (ADR 0044, admins
exempt). No schema, route, or permission change (the machine-owned wiki
references re-sync diff-free). Tests: new `EntityMatchCreationTest` (the
module contract), extended `FilamentEntityAlignmentCreatePageTest` /
`FilamentEntityAlignmentListActionTest` (duplicate + limit behavior), and
new `FilamentEntityFindMatchActionTest` (the Find Match action's first
coverage). Full suite: 1020 passed (5340 assertions).
`sentence-alignment.md` gains the creation-module section, moves the ADR
0044 count-then-create into the module's name, and fixes the copy fast
path's stale `AlignmentController::store` citation (it means
`LibraryController::storeAlignment`; four surfaces, not three);
`entities.md`'s Alignments-create route row now describes the thin caller —
both concepts with `generated.at` bumps.

## 2026-10-07 (Recommendations page)

New personalized reading queue at `/recommendations` (ADR
[0075](../docs/adr/0075-recommendations-page.md), new
`wiki/domains/recommendations.md`): the viewer's readable completed texts
grouped under works, filtered to texts whose **Word knowledge** snapshot
(ADR 0074) sits at or above a threshold (default 90%, clamped; minimum,
not ceiling), ordered least known first — an easy-read finder.
`RecommendationsController` runs one qualifying filter twice: grouped per
work (`MIN(score)` through a `joinSub`, keeping `paginate()`'s count
correct, 15 works/page) and per page-work texts, all in SQL over
`user_entity_word_knowledge` with the mid-rebuild guard mirrored from
`EntityWordKnowledgeService::refreshStale`. The page is a **read-only**
pass over the snapshot table (no backfill, no computation — a deliberate
ADR 0075 decision), so it re-ranks previously opened texts; search
matches work title/author or qualifying text names, `lang` defaults to
`en` (unknown code → 404), and unrankable texts (null score, mid-rebuild
index, non-completed, unreadable-restricted) are excluded silently. React
page `Recommendations/Index.jsx` follows the Library list idiom (GET
form + `LinkPagination`, wbench palette) with expandable work rows;
top-level `nav.recommendations` sits after Resources; strings seeded from
the new `ui-strings/recommendations.php` + `nav.php`. Tests:
`RecommendationsTest` (12 tests). `entities.md` gains a cross-link from
its ADR 0074 section and a `generated.at` bump.

## 2026-10-07 (Review polish: A-side URLs, clamp helper, has_no_familiarity rename)

Two-axis review (standards + spec) of the five dev commits (ADR 0072–0074
plus the two needs-review fixes) produced this follow-up batch. The match
payload's `work_id` and both Filament "Edit alignment" URL builders now
read the A-side entity's `work_id` only, per ADR 0072's single
verified path — the dropped `bEntity` fallback could build URLs the
controllers would 404 (`a_entity_id` is non-nullable, so the fallback was
dead weight). The off-page merge-row skip (needs-review approve fix) is
folded into `wiki/domains/sentence-alignment.md` — whose `generated.at`
had been left unbumped by the earlier flash-fix edit — and the stale
`wiki/index.md` entities line no longer advertises the deleted
language-scoped create pages. `Alignments/Show.jsx`'s duplicated
page-clamp shape (needs review + unmatched pools) is one
`fetchClampedPage` helper. `EntityController::show`'s `needs_word_test`
prop is renamed `has_no_familiarity`: the check is "no `user_word` rows
of any kind" (word test, crossword markers, adoptions), not "hasn't
taken the test" — ADR 0074's blank-slate hint semantics are unchanged;
`Entities/Show` and `EntityWordKnowledgeTest` follow the rename.

## 2026-10-07 (Entity pages show word knowledge)

Entity detail pages gained a per-user **Word knowledge** percentage (ADR
[0074](../docs/adr/0074-entity-word-knowledge.md)): the occurrence-weighted
share of the entity's dictionary-linked word occurrences the viewer knows —
`Σ(count × min(familiarity, 60)) / (60 × Σ count)`, no `user_word` row = 0,
unlinked tokens excluded, null when nothing is linked. `EntityWordKnowledge`
snapshots land in the new `user_entity_word_knowledge` table (one row per
user+entity, updated in place, no history), written by
`EntityWordKnowledgeService::ensure` on first/stale view — never while
`EntityWordIndexer::isStale` (the page shows "calculating") — and kept
current for non-visitors by the new `entities:refresh-word-knowledge`
scheduler entry (every 5 min, limit-bounded) against the dual staleness
trigger: word-list rebuild (`computed_at < words_indexed_at`) or 3-day age.
The 60 cap intentionally equals the frontend's `FAMILIARITY_STRONG_AT`, so
placement-baseline words (50) read as partially known, not mastered.
`EntityController::show` passes `word_knowledge` + `needs_word_test` (a
word-test hint accompanies a blank-slate 0%); the stat renders in the
`Entities/Show` header. `EntityWordKnowledgeTest` pins the math, the
snapshot lifecycle, the sweep triggers/limit, and the page payload. Docs:
ADR 0074, CONTEXT.md (**Word knowledge** in the Library context),
`wiki/domains/entities.md`.

## 2026-10-07 (Entity routes move onto the work branch)

The whole flat entity surface left the language-segmented
`/entities/{lang}/{entity}` namespace for the work branch (ADR
[0073](../docs/adr/0073-work-nested-entity-routes.md)): view, edit,
metadata PATCH, approval toggle, and the five sentence JSON endpoints now
live at `/works/{work}/entities/{entity}...`, keeping their `entities.*`
route names (`works.entities.show`/`index` were already the per-work list
and the global browse). The `{lang}` segment is gone — the entity carries
its language, so it was two addresses for one resource with one always a
404; the wrong-language 404 becomes a wrong-work 404 via
`abort_unless($entity->work_id === $work->id, 404)` on every action (the
ADR 0072 pattern). Old flat routes are deleted without redirects, and the
language-first create/store pair (`/entities/{lang}/create`,
`POST /entities/{lang}`) died with them along with its `Entities/Create`
page and `StoreEntityRequest` — the work-scoped Library form (ADR 0039) is
the only create surface. Client links all build from payload `work_id`:
the entity page's back link lands on the work's Entities page ("← {work
title} entities", language moved into the subtitle), the editor's back
link and cancel return to the entity's view page, and the per-work list's
cards target the nested view URL. Route-name asymmetry with the alignment
editor accepted. `EntityControllerTest` pins the nested pages, the
wrong-work 404, and every flat route's 404; its flat-store tests were
ported to the work-nested store or dropped where `LibraryTest` already
covered them. Docs: ADR 0073, CONTEXT.md (**Entities page** — entity
view/edit addresses), `wiki/domains/entities.md`.

## 2026-10-07 (Alignment editor moves onto the work branch)

The alignment editor's page and all eleven JSON endpoints left the flat
`/alignments/{id}` namespace for the work branch (ADR
[0072](../docs/adr/0072-work-nested-alignment-editor-routes.md)): the page
is now `GET /works/{work}/alignments/{entityMatch}/edit`
(`works.alignments.edit`), every `AlignmentEditorController` action binds
`Work` and 404s when the match path names another work, the old flat routes
are deleted without redirects, and all client link builders (alignment
cards, entity page, duplicate-match flash link, Filament edit actions) plus
every `AlignmentEditorApiTest`/`AlignmentEditorAccessTest` URL were
rewritten — the match payload now carries `work_id`, which feeds the
client's API base path and the header's new back link to the work's
Alignments page. New editor action **disapprove** (`rejectRow`): similarity
→ 0.0 with the chunk sentinel untouched — a number, not a verdict — so a
rejected row stays in Needs review (pure-similarity rule unchanged) and a
later Re-align deletes it below the landmark bar; the row rail is
icon-only now (✓ / ⊘ / ＋ / 🗑), rows approve in place from the
Needs-review list, and the shared `Pagination` gained a "go to page" jump
input (unmatched pools upgraded from bare arrows). Editor pagination state
moved into the URL (`rows_page`, `rows_per_page`, `unmatched_a_page`,
`unmatched_b_page`, `review_page`): seeded server-side via the new
`AlignmentEditorPageRequest` with out-of-range clamping, mirrored
client-side with `history.replaceState`. `AlignmentPagesTest` pins the
nested page, URL seeding/clamping, and the 404s. Docs: ADR 0072,
CONTEXT.md (**Rejected pair**, rewritten **Alignment editor**),
`wiki/domains/sentence-alignment.md`. Follow-ups on the same branch: the
mutation merge moved to `lib/alignmentRowMerge.js` (response rows for
other pages are skipped — approving from Needs review no longer appends a
phantom "next page" row), and the approve-in-place optimistic removal now
goes through the server snapshot so the merge cannot resurrect the row
(no three-render flash).

## 2026-10-06 (Band word lists min-merge onto frequency ranks)

New command `words:import-frequency-lists {--path=} {--dry-run}` (ADR
[0070](../docs/adr/0070-frequency-lists-min-merge.md)): reads the curated
band word lists in `storage/app/frequency-lists/` (moved out of
`public/frequencies`; tracked in git via a `!frequency-lists/**`
exception in `storage/app/.gitignore` — the lists are hand-compiled from
scratch, and tracking is what carries them to prod and backs them up)
and applies `words.frequency = least(current, band)` — every word in a
file gets the file's line-2 band, only ever lowering a rank. Matching is
direct `l_word` plus the forms path (`forms.l_word → word_id` onto base
words, aggregated with `min(rank)` so fan-out joins can't pick an
arbitrary band), all word-class rows together; misses are counted, words
are never created, and `entities.frequency_counted_at` stays untouched —
a clamp is not an authoritative re-baseline. Header validation happens
for all files before any write. `ImportFrequencyListsTest` covers the
clamp, the forms path, homographs, idempotency, dry-run, per-language
isolation and stress-mark stripping; `wiki:sync` regenerated the command
reference.

## 2026-10-06 (Review polish: bounds + construction paths)

Two-axis review (standards + spec) of the six-candidate implementation
(ADR 0065–0069): no defects found; all suites green (Pest 939, vitest 30,
build). Follow-up polish from the standards axis: `SavedUiSettings` now
owns the bounded integer settings as constants (`READER_FONT_SIZE`,
`SIMULATOR_FONT_SIZE`, `SIMULATOR_AI_PANEL_WIDTH`,
`SIMULATOR_WORKPLACE_HEIGHT`) and the save-request rules
(`UpdateUiSettingsRequest::integerRule`) plus both surface controllers'
clamp seeding read the same min/max/default. `UpdateUiSettingsRequest`
constructor-injects `EnricherRegistry` (no `app()` inside `rules()`),
`ReadingRowsPresenter` lost its nullable-registry fallback (tests resolve
it through the container or construct it with the real registry), and
`SavedUiSettings::section()` takes the user explicitly instead of reading
`auth()->user()` internally. The one-off `verticalApprovedUser` guard
helper became `approvedUser()` in `tests/Pest.php`, per the cross-file
helper convention.

## 2026-10-06 (word test: frequency-rank placement with a presumed-known baseline)

New page (`/word-test`, separate nav link before Resources): draws a fresh
Placement sample — 50 headwords over 20 equal-count Buckets of the
language's ranked inventory (`words.frequency` ≤ 20000, distinct per
`l_word`, `WordTestService`) — as a shuffled checkbox list; submit computes
the Word test score (each bucket credits 1000 × its known share, 0–20000)
and writes the Presumed-known baseline (`UserWord::PLACEMENT_BASELINE` = 50,
raise-only `GREATEST` upsert-select) over every tested-language word at
rank ≤ score. Bucket boundaries are inventory quantiles, not literal
0–1000…19000–20000 rank groups — ADR 0070's band ties leave 12 of 20 such
groups empty, and quantiles self-heal after a corpus import (ADR 0071).
Samples are server-cached under a uuid token (24 h); submits must present
the token and a subset of its ids (Form Request closures → 422). No
persistence beyond the `user_word` rows: retakes only ever raise. Languages
without ranked words (ru today) get an in-page empty state. UI strings in
`seeders/ui-strings/wordtest.php` + `nav.word_test`; tests
`WordTestScoringTest` (pure scorer) + `WordTestPageTest` (page, sampling
invariants, exact score/marked set, raise-only, 422s). Docs: ADR 0071,
CONTEXT.md Word Test context, `wiki/domains/word-test.md`.

## 2026-10-06 (simulator alignment picker: work-grouped and searchable)

The Practice → Simulator alignment picker stopped being a flat list of every
readable match. `SimulatorController::getWorkGroups()` now ships `workGroups`
(`[{id, label, options: [{id, text}]}]`): matches grouped under their work
(the A-side entity's work), works labeled `Title — Author` and sorted A→Z,
each work's newest match first — and only `completed`/`stale` matches list,
so pending/aligning/failed pairs no longer offer an empty read. A work
appears only when it holds at least one such match (works carry no access
semantics; readable-count scoping via `readableMatchQuery`). The select was
rebuilt as `Components/Forms/SearchableSelect.jsx` — a searchable, keyboard-
navigable popup (case-insensitive filter over work and option labels, a
matching header keeps its whole group); pure filter/flatten logic in
`lib/groupedOptions.mjs` with vitest coverage. The old flat
`Components/Forms/Select.jsx` (sole consumer was this page) is deleted, the
prop renamed `textList` → `workGroups`, and the default preselection moved
from "newest match overall" to the first option of the first work. Feature
tests updated in `SimulatorPickerTest` / `SimulatorPinnedMatchTest`.

## 2026-10-05 (Reading-surface chrome kit + one JSON envelope)

The toolbar mechanics ADR 0067 deferred now have one home each (ADR 0068):
`ReadingSideRadiogroup` (presentational, `variant: 'sim' | 'reader'` —
palettes and type stay per surface, value semantics stay with the pages:
side letters + `toggleTo` on the simulator, language codes + the
same-code no-op guard on the reader), `AnnotationToggle` (the grey-icon
rule stated once; the simulator's panel tabs share its primitives via
`PanelToggleTab`), `useFontSize` (numeric-delta clamp/step state;
application and autosave stay per surface), and `PageInput` (mirroring,
commit policy — Enter on the simulator, blur on the reader — NaN handling,
clamping via the new `lib/pagination.mjs`). Pure primitives one-homed:
`patchWordStatus` (the popup progress recolor both surfaces repeated) and
`loadJson`/`saveJson` under the three Working-state stores; the reader's
restore placeholder reuses `SpinnerSvg`. `TextContent` takes the
descriptor contract — `{side, language, wordMap, highlightable,
explainable}`, the reader's shape plus the column language — replacing
its six exploded `target*`/`base*` props (28 → 21); descriptors are
memoized on raw state in `useSimulatorText`, and the reader's gain the
`language` field. Deliberately not unified: font ranges, paging transport,
autosave key names, i18n namespaces.

The reading surfaces' JSON endpoints answer one envelope (ADR 0069): the
`['data' => ['data' => ..., 'code' => ...]]` double wrap is gone — success
is `{data: ...}`, errors `{error}` plus the HTTP status (the flat
`/word-events` convention); `/text`'s inner ADR 0060 keys are untouched.
The AI answers (`askAi`, `askAiStreamed`, `explainWord`) moved from
`SimulatorController` to `ReadingAiController` — the word-explain endpoint
serves the reader's word popup too; paths, names, throttle unchanged.
`EntityAccessService` is constructor-injected in both surface controllers,
and `SavedUiSettings` owns the clamped saved-settings seeding both
repeated (int/bool/annotation prefs). The explain config is built
server-side for both surfaces by `AIModelResolver::explainConfig()` — the
simulator ships it as an `explain` prop (with the simulator-only
`answerLabel`) instead of assembling it client-side. Clients updated in
lockstep (`responseErrorMessage`, `fetchTextPage`, `WordPopup`); ADRs 0068
and 0069 recorded; `web-routes.md` regenerated.

## 2026-10-05 (One coverage repair, one two-phase persist primitive)

The junction-less repair stopped being a caller-held protocol:
`MeaningMatchStore::repairCoverage(EntityMatch)` (ADR 0063, amended) is the
one coverage-repair interface — one transaction seeding the claimed-orders
set, backfilling both sides' junction-less sentences, resequencing by
document position (unconditionally, idempotent) and syncing `linked_count`,
returning `[created, resequenced]`. The two-method protocol
(`junctionlessSentencesFor` + `repairJunctionlessSentences(&claimedOrders)`)
went private, and both callers collapsed to one call: the align job's
`finalize()` (which keeps the status write; failure semantics tighten from
best-effort per side to whole-or-nothing with one warning — no more
half-repaired state only `alignments:repair` could finish) and the
`alignments:repair` command (which keeps its dedupe-first pass and reporting;
its conditional second resequence now always runs as a no-op-capable
idempotent pass). Alongside, the two-phase parking write got one home:
`SparseOrderService::persistOrdersTwoPhase(modelClass, updates)` — park at
unique negatives, then the finals, inside one transaction (savepoint under a
caller's), chunked at 1000. Rewired onto it: `SentenceOrderService::
persistChanged` (keeps the text-hash bump), `rebalanceAll` (now
transactional — the scheduled daily `entity-orders:rebalance` no longer
exposes parks to concurrent readers), `MeaningMatchStore::
resequenceMatchesByDocumentPosition`, and `AlignmentEditorService::
persistRowOrderChanges` (whose divergent bare-`-$id` park formula retires) —
ADR 0064 amended: shared mechanics, row-write domains still separate. New
tests: `MeaningMatchStoreRepairCoverageTest` (both-sides backfill, the
claimed-orders nudge, document order, idempotency) and
`SparseOrderServiceTwoPhaseTest` (past-each-other swap, savepoint nesting
and rollback-with-caller, empty no-op); all affected suites
(`ChunkedEntityAlignment`, `RepairEntityMatchAlignment`,
`ResequenceEntityMatches`, `RebalanceEntityOrdersCommand`,
`SentenceOrderService`, `AlignmentEditorApi`, `EntityEditing`) pass
unmodified. CONTEXT.md gains **Coverage repair**.

## 2026-10-05 (Bounded enrichment sweep scan)

`entities:enrich` no longer hydrates every enrichable-language entity and
runs one `max(entity_sentences.updated_at)` query per entity each five
minutes: `EnricherRegistry::staleForMany()` decides staleness for a whole
batch with one grouped query (the predicate moved into a private
`filterStale` shared with `staleFor`, unchanged per-entity), and the command
walks `chunkById`, stopping at the `--limit` dispatch cap instead of
scanning the whole catalog — ADR 0043's bounded-sweep rule now covers the
scan, not just the dispatch. The sweep also stopped evaluating
`staleEnrichers` twice per dispatched entity, and its `SentenceEnrichmentService`
injection (added when `::create()` died) is gone — the sweep talks to the
registry alone. Tests: `staleForMany`↔`staleFor` parity (fresh stamp,
missing stamp, sentence-less entity) and the id-ordered `--limit` cap.
Domains/commands unchanged — no `wiki:sync` needed.

## 2026-10-05 (One construction path through the python seam)

The three domain services that talk to python (`SentenceEnrichmentService`,
`TextSignatureService`, `SentenceAlignmentService`) lost their `::create()`
static factories: all nine production call sites (the enrich job + sweep
command, the signature job + finalize job + Filament findMatch action, the
align job ×3) now resolve through the container, making the
`AppServiceProvider` `PythonClient` binding (ADR 0061) the one construction
path it always claimed to be — `MeaningMatchStore::create()` and
`PythonClient::create()` (the factory the binding itself uses) stay. Tests
followed the seam: new `Tests\Support\FakePythonClient` (in-memory adapter;
`enrich` echoes the keyed ADR 0057/0067 contract by default, other
endpoints throw the production `PythonClientException` until given a canned
response) bound via a `fakePython()` helper in tests/Pest.php; wire-level
`Http::fake()` now lives only in `PythonClientTest`. Migrated:
EntityEnrichmentTest (the `fakeEnrichResponse` helper became the fake's
default echo), ChunkedEntityAlignmentTest, AlignmentStaleStatusTest,
ReAlignPreservesLandmarksTest, EntityIllustrationAlignmentTest,
EntityStatusLifecycleTest, EntityTextHashRefreshTest, and the unit
TextSignatureService/SentenceAlignmentService tests — whose transport-retry
cases were deleted as duplicates of `PythonClientTest`'s. Also dropped:
`EnrichEntitiesCommand`'s stale "not container-resolvable" comment. The
test-faking guidance in playbooks/running-tests.md now describes
`fakePython()` instead of `Http::fake()`. No schema, route or command
changes — no `wiki:sync` needed.

## 2026-10-05 (Annotation descriptor; python version parity — ADR 0067)

New ADR 0067. The enrichment display layer got its own declarative unit:
`Annotation` (payloadKey + column + settingKey + Filament preview) — two
stress enrichers feed one annotation, and `EnricherRegistry::annotations()`
now drives every derived site (ui_settings validation rules, Reader/
Simulator preference seeding — wire props unchanged, single-language column
select, ReadingRowsPresenter payload guard, SentencesRelationManager
columns, the service's write loop and dynamic token-hint passthrough: a
hint field no active enricher contributes is no longer sent). Python side:
each enrichment module exports `ALGORITHM_VERSION`, `/enrich` responses
report `versions`, enrichers declare `pythonVersion()`, stamps became
`{v, pv, at}`, and `staleFor` re-stales on a reported python version older
than declared — algorithm drift across the python boundary now warns and
self-heals instead of silently keeping old results. Missing `pv` (every
pre-parity stamp) reads as stale: one accepted corpus-wide re-run through
the bounded sweep after deploy. New drift guard
`tests/Feature/EnrichmentVerticalTest.php`; EntityEnrichmentTest and
PythonClientTest adapted; python test_enrichment.py gained version checks.
CONTEXT.md gained the Annotation term (Sentence Enrichment Context);
domains/sentence-enrichment.md updated. No schema, route or command
changes — no `wiki:sync` needed.

## 2026-10-05 (Bilinguals engine extraction; vitest for pure JS logic; one useDragResize — ADR 0066)

New ADR 0066. The 856-line `Bilinguals.jsx` became layout + toolbar + wiring
(~460 lines) over three page-local engine hooks: `useSimulatorText` (match
selection, Reading-row pages, position store, side flip, familiarity
crediting, `textPending`), `useAiStream` (SSE answer stream, retry,
`aiPending`), `useAssessmentQuestion` (template/task-list split). The pure
logic underneath moved to `lib/` — `sseStream.mjs`, `simulatorText.mjs`,
`aiAsk.mjs`, plus pure additions to `simulatorPosition.js`
(`writePosition`/`writeCurrentText`, one home for the flip-migration shim)
and `http.js` (`responseErrorMessage`) — and is now pinned by the repo's
first JS test runner: vitest (27 tests, Node only, no jsdom/React —
components stay Pest/manual territory), run via `docker exec … npm run test`
and a new `frontend` CI job, deliberately not chained into `composer run
test`. Deliberate behavior change decided in the 2026-10-05 architecture
review: the shared `pending` flag split into `textPending` + `aiPending` —
asking no longer aborts during a page fetch and streaming no longer disables
page turns (the Spinner shows on either). Dead code removed (the unused
`pending` prop to `TextContent`, the never-read `questionRef`); the ask
prompt's unmotivated first-asterisk strip is pinned verbatim in
`lib/aiAsk.mjs`. Also one shared `hooks/useDragResize` replacing three
hand-rolled copies (simulator AI panel + workplace, crossword right panel —
still drag-only/ephemeral on the crossword). Wiki: architecture/frontend.md
gained the JS module layering + vitest sections; bilinguals-simulator.md's
Frontend section rewritten around the engines; crossword.md and
running-tests.md updated.

## 2026-10-05 (EntitySentenceStore: one sentence-mutation flow; Filament stale-flip gap fixed — ADR 0065)

New ADR 0065. The mutate → stale-flip → totals-resync flow was a
hand-composed convention per door, and the doors had drifted:
`EntityController` composed the stale flip + totals *after* its write
transaction (non-atomic), and the Filament relation manager resynced totals
only — it never flipped matches stale, contradicting ADR 0062's totals
bullet, this file's f4b44e4-era entry, and the `syncTotalsForEntity`
docblock (a completed match edited in Filament stayed `completed`, with no
re-align signal). Now `EntitySentenceStore` (`app/Classes/`, named after
`MeaningMatchStore`) owns insert/update/delete/deleteMany/reorder for the
entities frontend and the relation manager: each is one transaction wrapping
placement (`SentenceOrderService`), the write (model events bump
`sentences_updated_at`), the stale flip, and the totals resync; the
illustration rules move in with the writes. The relation manager now flips
stale (visible change: the badge appears after relation-manager edits).
`AlignmentEditorService::updateSentenceContent` (moved out of the
controller) keeps ADR 0062's no-stale rule, now pinned by tests in both
directions; the importer exemption is recorded. Docs corrected rather than
re-argued: ADR 0062's totals bullet carries an amendment note,
`syncTotalsForEntity`'s docblock is fixed, CONTEXT.md's Stale/Edit-rule
entries sharpened (dropped the retired "full editor save"), sentence-alignment
§7 + ADR 0055 section and entities.md's Match staleness rewritten.
Tests: new `EntitySentenceStoreTest` (flow + atomicity + pending rule);
relation-manager suite gains stale assertions for create/edit/delete/bulk;
editor API suite pins the no-stale rule and the content-edit hash bump.

## 2026-10-05 (fix: stress marks pinned to the sentence block — wrapped words no longer misplace marks or force a horizontal scrollbar)

WordText positioned each empty `.stress-mark` at `glyphX − hostRect.left`,
but when a host word wrapped across lines (a hyphenated compound on a
narrow window), the host's union bounding rect no longer matched the box
the browser anchors abspos children of a fragmented inline to: marks landed
a line-start offset to the right — past the column edge — widening the
scrollable overflow of `main#contentContainer` (reader) / the TextContent
root div (simulator) into a horizontal scrollbar, and the static vertical
position followed the word's last line instead of the glyph's. Fix:
`.word-token`/`.stress-host` give up `position: relative` and WordText pins
every mark in both axes against `mark.offsetParent` — a stable
sentence-level block, made explicit with `relative` on ReaderRow's side
divs and the simulator base `<td>` (the target td already had it for the
ribbon-mark) — measured from the glyph's one-character Range rect (a single
character never spans lines). Visual unchanged (mark's left edge at glyph
center, ascent-zone vertical); unmeasured marks fall back to their static
position beside the word. No PHP/routes changes; docs:
sentence-enrichment concept.

## 2026-10-05 (fix: Yomitan compat — stressed words keep one intact text node)

The first overlay pass wrapped each stressed vowel in its own
`.stress-mark` span, splitting every stressed word into three text nodes
("floated" → "fl" + "o" + "ated") — Yomitan reads text per text node, so
lookups only saw the fragment under the cursor. Now the stressed word's
text is never wrapped or split: it stays one intact text node of original
characters, and the accent is an *empty* absolutely-positioned
`.stress-mark` child of the word's span (`aria-hidden`, `pointer-events:
none`) pinned horizontally by WordText's layout effect — a DOM Range over
the `data-offset` character gives the glyph center relative to the host
span. Within-word offsets depend only on font metrics (not layout,
wrapping, or column widths), so re-measuring on structural change
(including the async word map arriving), `document.fonts.ready`/
`loadingdone`, and a parent-block `ResizeObserver` (font-size settings,
zoom) covers every case where offsets can move. Gap segments sub-split at
word boundaries so single-letter words («я́», «о́») host their own mark
without splitting anything. `.word-token` gained `position: relative`;
keyless hosts use `.stress-host`. Verified by 28 SSR DOM assertions
(whole-word contiguity inside host spans, correct `data-offset`s, fast
path, phrasal+stress composition, divergence fallback). No PHP changes.

## 2026-10-05 (fix: stress marks render as a CSS overlay over plain text)

The stress-marks toggle used to substitute `stressed_content` (U+0301
combining acutes + Silero's е→ё) into the DOM, so everything reading the
page as text broke: copy/paste and double-click dictionary extensions got
marked characters, and a е→ё swap even changed word-map keys. Now
`WordText` always segments and renders the plain `text`; the new
`resources/js/lib/stressMarks.mjs` (`stressOffsets()`) walks the plain and
stressed strings in lockstep (inserted acutes, е→ё, pre-marked source
marks, divergence ⇒ plain) and returns the plain-text offsets whose
characters get wrapped in a `.stress-mark` span whose CSS `::after`
(`app.css`) draws the acute above the vowel — no combining character and
no ё substitution ever reaches the DOM, so selection, copy/paste,
extensions, and browser find see original characters on both the simulator
and the reader (shared renderer). Stressed е now displays as е́ (accent
over plain е) instead of ё — deliberate. Enrichment pipeline, payload
shape, and popups unchanged; `--font-reading` comment + reader concept
updated (single-font rule now only guards source content that itself
carries combining marks). No PHP/routes/commands changed. Verified via
in-container node: `stressOffsets` unit cases + SSR-rendered `WordText`
DOM assertions (23 checks: no U+0301 in DOM, plain textContent, marks on
the right vowels, fast path, phrasal-hit + stress composition, divergence
fallback).

## 2026-10-05 (simulator language toggle retinted to the reader's vermilion)

The learning-language segmented toggle (EN↔RU) in the simulator toolbar
(`Bilinguals.jsx`) painted its active side with `--wbench-accent`
(ultramarine `#1F3DDB`) while the reader's identical reading-language toggle
uses the `--color-vermilion` family (rust `#B0451E` / night `#D4562F`), so the
same control looked blue on one page and brownish on the other. Retinted the
simulator toggle's active + focus-ring classes to the reader's exact
vermilion classes (incl. `text-vellum`/`dark:text-ink-night`); inactive-side
and hover colors stay `--wbench-*`. Documented as a deliberate exception to
the "new Inertia pages use `--wbench-*`" guidance in
`conventions/design-system.md` (parity with the reader wins).

## 2026-10-04 (fix: PromptTemplateSeeder deploy-safe, added to per-deploy seeds)

`PromptTemplateSeeder` switches `updateOrCreate` → `firstOrCreate` (same
contract as the AiProviderSeeder fix below): rows are register-only, so
admin-edited prompt text in the Filament "Prompt Templates" resource
survives deploys; to restore a default an admin blanks the row's text
(blank falls back to the code constant in `PromptTemplates::row()`). The
seeder is now also in the per-deploy seed list (`deploy.sh`) — that was
the actual gap: the 2026-09-24 move of the prompts into `prompt_templates`
(ADR 0040) never seeded production, so the Filament screen shows an empty
table there and the app silently runs on the fallback constants in
`App\Support\PromptTemplates` (which is why AI kept working). The next
`./deploy.sh` seeds the three rows on prod. Regression test added
(`PromptTemplateSeederTest`); `wiki/domains/bilinguals-simulator.md`
updated.

## 2026-10-04 (fix: AiProviderSeeder no longer resets admin provider settings)

`AiProviderSeeder` switches `updateOrCreate` → `firstOrCreate`: the per-deploy
seed run (`deploy.sh` seeds it with `--force`) now only *registers* providers
missing from `ai_providers` and never updates existing rows, so admin-edited
`is_enabled` (the Filament enable/disable toggle), `name`, and `description`
survive deploys instead of being re-enabled/reset every merge. Previously
`'is_enabled' => true` sat in the update payload, so every deploy silently
re-enabled all six providers. Tradeoff: code changes to a provider's
`getProviderName()` no longer propagate to existing rows (the row is
admin-owned after creation). Regression test added
(`AiProviderSeederTest::preserves admin-edited rows on re-seed`);
`wiki/domains/ai-providers.md` updated.

## 2026-10-04 (refactor: one word-map / meta payload builder, ADR 0060 amendment)

`ReadingRowsPresenter` is now the reading surfaces' whole payload seam: it
gains `wordMapsFor(sideEntities, userId, nativeLanguageId, rows)` — the
page-filtered word maps plus the `highlightable`/`explainable` eligibility
flags (the "not the user's native language" rule, null-entity safe) — and
`metaFor(paginator)`. The reader's private `wordMapForRows` / `isNotNative`
/ `metaFor` and the simulator's `wordMapsFor` + inline meta array are
deleted. The simulator `/text` response flattens to the reader's sibling
shape (`word_maps {a, b}` with `highlightable` / `explainable` as
top-level keys — the wire stays snake_case, the client renames) and now
page-filters its maps like the reader (the client never read off-page
entries; the payload shrinks to what the page renders). `Bilinguals.jsx`
word-map state drops to pure `{a, b}` with eligibility in its own state —
the spread-must-preserve-eligibility hack is gone. ADR 0060 amended;
`wiki/domains/reader.md`, `bilinguals-simulator.md` and
`interactive-words.md` updated. Tests: `SimulatorTextEndpointTest` asserts
the sibling eligibility keys (the simulator's `explainable` was previously
untested) and gains a page-scoping test mirroring the reader's.

## 2026-10-04 (refactor: MeaningMatchStore, ADR 0063)

`SentenceAlignmentService` (971 lines) is split into two classes along its
one real seam. It keeps only the python-match adapter: `verifyEntityPair`,
`alignChunkRemote` (payload assembly + `/align` through `PythonClient`),
and the links/dpPath path builders — `buildCommittedPath` /
`buildSkipOnlyPath` are now public, their docblocks stating the array
shapes as the adapter↔store contract. The new `App\Classes\MeaningMatchStore`
(~600 lines) is the pipeline's meaning-match write path —
`storeAlignmentSegmentFromMatches`, `storeSkipSentences`,
`resequenceMatchesByDocumentPosition`, `junctionlessSentencesFor`,
`repairJunctionlessSentences`, with `persistSegment`, the junction-dedupe
keeper election and `claimOrder` private — the pipeline-side sibling of
`AlignmentEditorService` (ADR 0062). Method bodies moved verbatim;
transactions stay where they were (each store method owns its own, the
job's `persistOffsets` nesting unchanged). Callers rewired directly, no
facade: the job, `alignments:resequence`, `alignments:repair` and
`AlignmentCopyService` call the store for writes and the service for
verify/align; the dead `storeLinks` / `storeAlignmentSegment` surface is
deleted. `LANDMARK_THRESHOLD` moved onto `MeaningMatch` (sibling of
`HUMAN_CHUNK`) — the service const and the job's mirror are gone, and
`EntityMatchResource`'s Re-align modal reads the model const. Context.md
gains a **Landmark** glossary term; `wiki/domains/sentence-alignment.md`
and `wiki/database/entities-alignment.md` updated; the stale
`RETRY_DELAYS_MS` attribution in `wiki/playbooks/run-alignment.md`
corrected. Tests: `ResequenceEntityMatchesTest`,
`ChunkedEntityAlignmentTest` and `SentenceAlignmentServiceTest` pass with
construction-site rewires only (894 passed overall).

## 2026-10-04 (refactor: One alignment-editing domain, ADR 0062)

The Filament draft editor is retired: `EditEntityAlignment`,
`AlignmentEditorPersister`, `AlignmentEditorDraftStore`,
`AlignmentEditorPresenter`, the page blade + `alignment-sentence-editor`
partial and their tests (`EditEntityAlignmentPageTest`,
`AlignmentEditorPersisterTest`, `AlignmentEditorDraftStoreTest`) are
deleted; the `EntityMatchResource` list keeps Re-align / Run-from-scratch /
publish actions and its "Edit alignment" action (and the `ViewEntityMatch`
header action) now open the React editor at `alignments.show`. Editing no
longer flips a stale match to `completed` — only explicit Re-align /
Run-from-scratch / re-import do (ADR 0055 philosophy, now consistently
enforced). The read-only Filament view page and the shared
`alignment-pagination` partial stay.

Same effort, three more moves: (1) the invariants went onto the models
(`MeaningMatch::HUMAN_CHUNK`, `EntityMatch::nextAlignmentChunk/
syncLinkedCount/recountTotals/syncTotals/alignableCountForEntity/
syncTotalsForEntity`) and every writer — editor, aligner job +
`SentenceAlignmentService`, importer, `AlignmentCopyService`,
`alignments:repair`, the sentence-deletion hook — routes through them;
(2) `App\Enums\Side` (backed a/b) owns the side-key mapping with
`EntityMatch::entityFor/entityIdFor`, adopted across the editor classes
(junction `side` values and payloads stay string-based); (3)
`AlignmentEditorService` now owns the editing domain — row
create/delete/approve, sentence add/unlink/delete, the move placement
engine (incl. the two-phase negative-park order write) — while
`AlignmentEditorController` keeps gates, validation, 404/422 mapping and
the mutation envelope (`AlignmentEditorApiTest` passes unmodified).
Finally the totals gap is closed: entity-frontend sentence mutations
(`EntityController` + the Filament `SentencesRelationManager`) now call
`EntityMatch::syncTotalsForEntity` alongside the stale flip, so the editor
header shows truthful image-less counts before a Re-align (new tests in
`EntityEditingTest` + `EntitySentencesRelationManagerTest`).

## 2026-10-04 (refactor: One client for the python service, ADR 0061)

The four scattered python-service call sites (`SentenceSplitter` /split,
`SentenceAlignmentService` /align, `SentenceEnrichmentService` /enrich,
`TextSignatureService` /embed), each carrying a byte-identical copy of the
house transport idiom (base-URL config, connection-error-only retries at
500/1500/3000 ms, `throw: false`, error envelope), are collapsed onto one
seam: `App\Classes\PythonClient` — typed endpoint methods owning response
unwrapping, `create()` factory reading `services.python.url/timeout/
align_timeout` (dead 300 fallback removed; orphaned
`has_similar_batch_size` key dropped). Non-2xx responses now throw
`App\Exceptions\PythonClientException` (extends `RuntimeException`) with
the preserved `Python {endpoint} service error: {status} - {body}` shape;
`TextSignatureService` catches it for its deliberate best-effort null
(embed must not block entity finalization). Payload assembly stays in the
domain services; `WordTranslationProvider`'s lookalike idiom for external
translation APIs is explicitly out of scope. The four services take the
client via constructor injection and keep their `create()` factories, so
production call sites are unchanged; `AppServiceProvider` binds the client
for container-resolved `SentenceSplitter`. New
`tests/Unit/PythonClientTest.php` (retry, endpoint-labelled envelope,
per-endpoint unwrapping, embed null-on-missing-vector, factory URL);
per-service tests keep their transport assertions through the seam. Full
suite 910 passed.

## 2026-10-04 (queue workers died silently: sweep GC before every pop)

Manual `php artisan queue:work --queue=default,low` runs (and, latently, the
prod compose workers) died silently after tens of jobs — shell prompt
returns, nothing in `failed_jobs`, pending count unmoved: PHP's 128M CLI
`memory_limit` fatal in the long-lived daemon, whose in-flight job then
re-leases after `retry_after`. Differential runs (Debugbar on/off, RSS
sampled) exonerated Debugbar and pinned the mechanism: each enrichment job
leaves thousands of cyclic Eloquent roots (model↔collection cycles), PHP's
GC only sweeps on root-buffer overflow, so a daemon processing self-chaining
`EnrichEntitySentences` work climbed ~1MB/job to the fatal (measured:
124.5MB at job 74; user-visible death at 20-30 jobs on bigger early-cursor
chunks). Fix: `AppServiceProvider` sweeps `gc_collect_cycles()` before every
pop via `Queue::looping` — probe A/B: unswept worker fatal by ~75 jobs,
swept worker flat at ~50-60MB over the same fuel. Also: `DB_QUEUE_RETRY_AFTER=900`
set in dev `.env` (was falling back to 660; wiki already mandated 900), and
the backlog drained to 0 pending / 0 failed. Repro + guidance now in
`playbooks/run-alignment.md` (Failure handling); note `queue:listen` (composer
`dev`) is immune — fresh process per job.

## 2026-10-04 (one side flip per text, shared by the reading surfaces)

The two reading surfaces duplicated the flip mechanics: `Bilinguals.jsx`
defined its own `otherSide` and hand-rolled the learning-side math instead
of using `readingRows.mjs`, and each surface wired its own flip state +
persistence in two different localStorage containers (reader:
`ext_app.reader.side-flip.v1` keyed by `positionKey`; simulator: a
`flipped` field inside its per-match position entry keyed by bare match id).
A new `hooks/useSideFlip.js` now owns flip state, persistence, and the
derived first/second sides for both surfaces (`ReaderApp.jsx` and
`Bilinguals.jsx` consume it). The simulator's flip moved to the shared
side-flip store keyed `mm:{matchId}` — one **Side swap** per text, so a
flip on the reader carries into the simulator for the same match (ADR 0037
amendment). Simulator position entries are now `{page, row}`; the stale
`flipped` key is stripped on write and old saved flips reset once (no
migration). No PHP/routes/API changes; docs: ADR 0037 amendment block,
CONTEXT.md Side swap term, reader + bilinguals-simulator concepts.

## 2026-10-03 (fix: reading-rows review follow-ups)

Review of the ADR 0060 branch caught two client bugs and a docs gap.
`WordText` built its per-sentence memos (`displayTexts`, `segmentLists`,
`phrasalMarks`) over the full sentence list but looked them up with a
text-only counter, so any text sentence after an illustration in the same
row rendered the wrong entry (the image slot); lookups now use the
full-list index, restoring the ADR's document-order interleave.
`ReaderApp.setReadingLang` compared the radio's language code against the
side letter (`lang !== defaultSide`, always true), so the first side flip
stuck — the default language's radio could never restore it and
`saveSideFlip` persisted the stuck state; it now compares against
`langs[defaultSide]`. `AiWordExplainEndpointTest`'s two 422 assertions use
`assertUnprocessable()` per coding-conventions. Also documenting the
branch's `phrasal-hit` CSS en route: phrasal-verb hit tokens gained a
background (`--phrasal-hit-background` in `app.css`), a visual change that
shipped without a log entry.

## 2026-10-03 (feat: Reading rows — one row-object payload, ADR 0060)

The reading surfaces' payload of five index-parallel arrays (`rows`,
`rowImages`/`row_images`, `rowKeys`/`row_keys`, `stressedRows`/
`stressed_rows`, `phrasalRows`/`phrasal_rows`) is replaced by one array of
**Reading rows** — self-describing row objects in canonical a/b order, each
sentence carrying its entity-sentence id, text, and annotation keys only
when data exists; illustrations ride as image sentences in document order.
The server ships `defaultSide` (ADR 0037 rule) plus side-keyed companions
and never reorders; the client's flip (`resources/js/lib/readingRows.mjs`)
is just which side each display column shows — the nine flip memos and the
reader's server-side normalization are gone. `/ai/word-explain` now takes
only `entity_sentence_id` (the positional `meaning_match_id + side +
sentence_index` address and the server's mirror-the-presenter list rebuild
are deleted); `ReaderRow` (26 props) takes a row + two side descriptors,
`WordText` takes a side's sentence list, `IllustrationFigure` is shared,
and the simulator's dead `filename` mode + its two fixture files are
removed (`entity_match_id` required). New `ReadingRowsPresenter`; the five
simulator builders left `MeaningMatchPresenter` (keeps `toDisplayRows` +
`meaningMatchesQuery`). Also fixed en route: stale `stressedRows`/
`phrasalRows` on reader page turns (the `PAGED_PROPS` gap — one payload
makes it structural) and the simulator error-path `t()` ReferenceError.
Concepts updated: reader, bilinguals-simulator, interactive-words,
sentence-enrichment; glossary gains **Reading row**. Tests rewritten to
the new shape (ReaderPageTest, EntityEnrichmentTest,
SimulatorTextEndpointTest incl. filename-mode rejection,
EntityIllustrationAlignmentTest, AiWordExplainEndpointTest).

## 2026-10-03 (full suite runs parallel; worker-DB drop actually works)

`composer run test` ran the suite serially (`php artisan test`) while the
parallel machinery sat unused on the TIA path. The script now runs
`vendor/bin/pest --parallel --drop-databases` (8 workers in the dev
container): 899 tests / 4540 assertions measured at 273s serial → ~93-117s
parallel (host wall clock 4m48s → ~2m; first run after a drop pays worker-DB
creation). CI (`tests.yml`) picks this up unchanged — it invokes the same
composer script, its `postgres` service user can CREATE DATABASE, and
`pcntl` is already in the setup-php extension list.

While verifying, found that `--drop-databases` had never actually dropped
anything: Pest's Laravel handler copies the flag into `$_ENV`, but Laravel's
`ParallelTesting` reads its options from `$_SERVER`
(`ParallelTesting::option()` / `inParallel()`), and PHP never merges `$_ENV`
into `$_SERVER`. Workers created/used `ext_app_test_test_{N}` every run and
left all 8 behind — the mechanism behind the 2026-08-03 orphan cleanup, and
`test:tia` leaked them for months despite passing the flag. Fix: both
scripts now export `LARAVEL_PARALLEL_TESTING=1` and
`LARAVEL_PARALLEL_TESTING_DROP_DATABASES=1` as real environment variables
(`sh -c 'VAR=1 …'` prefix), which EGPCS surfaces in `$_SERVER` for parent and
workers alike; the parent runner then drops every worker DB in its teardown
(verified: 0 leftovers after a full run). Trade-off: worker DBs are
recreated each run (~20s), accepted so orphans can never accumulate again.
`tests/TestCase.php`'s `ext_app_test` guard is unaffected — Laravel appends
the worker suffix after app boot.

## 2026-10-02 (fix: directional-adverb guard for multi-word verbs, ADR 0059 v3)

A user report ("Beige-colored skin." flagged as a multi-word verb) did
not reproduce — no stored hit, and the parse contains no VERB token
("colored" is ADJ) — but the review it prompted exposed real precision
misses of the same flavor: "swung OVER TOWARD Max" → `swing over` and
"followed Max DOWN TO the basement" → `follow down`, where the particle
slot holds a directional path adverb, not a particle. Fix (ADR 0059
v3): the parser-evidence particle path skips a particle immediately
followed by a goal/path preposition (to/toward/towards/into/onto/
through/across/past). Locative prepositions ("looked it up on the
network") and infinitival "to" (tagged PART, not ADP — "looked it up to
check") still hit; dictionary-gated combos ("come up to") are tried
before the guard and survive it. `EnglishPhrasalVerbEnricher` version
2 → 3 — the sweep re-stales and re-runs the English corpus with no
manual step. Python regression tests for all four cases;
`wiki/domains/sentence-enrichment.md` updated.

## 2026-10-02 (feat: multi-word verbs via spaCy dependency parsing, ADR 0059)

Real-text review flagged "done, it", "not say" and "could have given" as
phrasal verbs — three compounding root causes: Wiktionary junk under the
verb class ("do it", "could have"), translation-path junk (multi-word
provider candidates stored under the provider pos), and an n-gram matcher
that cannot see punctuation or particles. The user chose a rewrite. Python
(`ai/enrichment/phrasal.py`): spaCy `en_core_web_md` (lazy singleton,
batched via `nlp.pipe`) parses the raw sentence; every VERB token (AUX
excluded) matches by `prt`/`prep` children — particle verbs hit on parser
evidence alone (separable "looked it up" works now), prepositional and
phrasal-prepositional matches stay dictionary-gated; `/enrich` returns 503
when the model is missing (fail loudly, never stamp empty enrichment).
Laravel: `Enricher::version()` + versioned stamps `{v, at}` (legacy string
stamps read as v1) — en_phrasal is v2, so the 5-minute sweep re-enriches the
whole English corpus automatically; lexicon gated by `MultiwordVerbShape`
(2–4 tokens, trailing particle/preposition whitelist; idioms out of scope);
`FetchWordTranslations` stores multi-word candidates under the `phrase`
class; new `words:reclass-multiword {--dry-run}` reclasses existing English
junk verb rows to phrase (en-only — Russian multi-word verbs untouched).
UI string "Phrasal verbs" → "Multi-word verbs" (ru keeps "Фразовые
глаголы"); storage keys (`en_phrasal`, `phrasal_verbs`) unchanged.
`requirements.txt` gains spacy + the model wheel (container-definition:
rebuild python image + `--stamp` on the Docker prod path). CONTEXT.md
renamed "Phrasal verb hit" → "Multi-word verb hit" and versioned the
Enrichment staleness definition; `wiki/domains/sentence-enrichment.md` and
`wiki/domains/dictionary-import.md` updated.

## 2026-10-02 (phrasal lead verb-lemma candidates)

"came forward" went unflagged: the matcher trusted the single `cls` hint,
but `CLASS_PRIORITY` ranks noun above verb, so POS-ambiguous leads ("came",
"went", "turn", "cut"…) resolved to their noun page and never led a match;
a second miss class had verb leads whose imported headword is the inflected
form itself ("looked"), making the single lemma hint useless. A classified
probe of entity 17 (read-only, script since deleted) measured 93 windows
whose phrase IS a stored multi-word verb headword against 6 stored hits —
62 class-hijacked, 31 bad-lemma, the rest literal uses / dictionary gaps.
Fix (ADR 0058): `EnglishPhrasalVerbEnricher` contributes a `verb_lemmas`
per-token hint (every verb-class headword for the surface: direct rows +
forms-table bases, deduped, cap 8; only when en_phrasal runs), python
`phrasal.find_phrasal_verbs` tries linked-lemma + verb_lemmas candidates
with longest-window-first preference; `EnrichToken.verb_lemmas` added.
Re-run of en_phrasal only (ADR 0057's per-enricher staleness in action;
en_stress untouched): entity 17 went 6 → 243 hits, all with `phrase`.
Coverage is now bounded by lexicon completeness — a data matter (the dev
dictionary predates part of the dump; "come forward" is importable but
absent; re-running the dictionary import widens detection, then
`entities:enrich --enricher=en_phrasal` applies it). Review fixes riding
along: the enrich sweep pre-filters languages in SQL (`EnricherRegistry::
languages()`), `keys()` replaces a hardcoded key list in `--enricher=`
errors, unused `SentenceEnrichmentService::registry()` removed, and the
`phrasal_verbs` ui-settings toggles gained endpoint tests.

## 2026-10-02 (enricher registry + phrasal verbs on the reading surfaces)

Sentence enrichment restructured from hardcoded language conditionals to
declaratively scoped **enrichers** (ADR 0057; glossary term added to
CONTEXT.md): `App\Classes\Enrichment\Enricher` + `EnricherRegistry` with
`RussianStressEnricher` (ru_stress), `EnglishStressEnricher` (en_stress) and
`EnglishPhrasalVerbEnricher` (en_phrasal). `SentenceEnrichmentService::enrichChunk`
now takes the enricher list, resolves shared base hints (cls/lemma/headword/
word_id per token key) and merges each enricher's token hints + request
extras into ONE python call; the `/enrich` request carries
`enrichers: [key]` (pydantic Literal replaces the `^(ru|en)$` language
pattern) and results return keyed by enricher (`results[].output`), so a
partial run skips the other modules. Phrasal hits gained a `phrase` field
(the matched lexicon headword, for tooltips). Staleness moved from the
single `entities.enriched_at` to per-enricher `entities.enrichment_stamps`
jsonb (`EnricherRegistry::staleFor`): a newly registered enricher is stale
on its own — ADR 0053's manual enriched_at reset choreography is gone;
stamps merge via jsonb `||`; the migration backfills enriched entities,
leaves never-enriched enrichable ones null (sweep rebuilds) and gives
languages without enrichers `{}`. `entities:enrich` dispatches only the
stale set (`beginEnrichers`) and gained `--enricher=` (force one key);
Filament's Enrich action and FinalizeEntityDerivations keep full runs
(`begin()`); non-enrichable languages stamp `{}` in the job (the sweep no
longer needs its bulk not-applicable stamping). Phrasal verbs became
visible per ADR 0057: reader + simulator ship `phrasalRows`/
`phrasal_rows` (per side: per-sentence hit lists, side null when no hits;
flipped with the reading/learning side like stressedRows), `WordText`
underlines hit-span tokens (dotted `phrasal-hit` class, indexes computed
from the ORIGINAL sentence so they transfer to the stressed variant) with
the matched phrase as tooltip, behind a per-user `phrasal_verbs`
ui_settings preference (reader + simulator, default off, autosaved; new
`reader.phrasal_verbs` / `bilinguals.phrasal_verbs` UI strings, new
`phrasal` icon); the Filament Sentences "Phrasal verbs" column is visible
by default. `wiki/domains/sentence-enrichment.md` rewritten for the
registry/stamps/rendering; ADR 0052 unaffected (its phrasal "Filament-only"
scope is superseded by 0057).

## 2026-10-01 (feat: pronunciation guide moves from navbar modal to a page under a new Resources dropdown, ADR 0056)

The ADR 0054 phoneme reference shipped as a modal behind an unlabeled icon
beside the theme toggle — a weak affordance, invisible to the information
architecture, not linkable, and incidentally visible to guests. The user
asked to move it into the main navbar as a dropdown option after Puzzles and
put its content on a dedicated page. Grilled decisions: the menu is named
**Resources** (EN) / Ресурсы (RU) — a new `nav.resources` UI string, chosen
over "Reference"/"Guides" as the umbrella for study-support surfaces; the
child reuses the existing `nav.pronunciation_reference` string (no
"Ресурсы → Справочник произношения" redundancy problem, unlike the
Reference alternative); the page lives at `/resources/pronunciation-guide`
(route name `resources.pronunciation-guide`, page
`Resources/PronunciationGuide`) — no `public/` collision; access moves to
the standard `auth`+`approved` group (guests lose the chart, accepted per
ADR 0056); tabs and the open card keep client-side state, no URL state.
Implementation: `PronunciationReferenceModal.jsx` and the navbar icon
button are deleted; the content (tabs, `SoundCard`/`PairUnit`/`SoundDetail`,
credits) moved verbatim into the new page (modal shell — portal, focus
trap, scroll lock, Escape — dropped; `aria-haspopup="dialog"` removed from
cards since the detail view swaps in place); NavBar's data-driven `navLinks`
gained the Resources entry (desktop dropdown + mobile accordion render for
free) and `layouts/navigation.blade.php` got the Alpine mirror on both
surfaces. Route is a closure in the auth+approved group (no controller —
the page needs only shared props). `UiStringSeeder` re-seeded for
`nav.resources`. Tests: PhonemeReferenceTest +2 (page component for
approved users; guest → login / unapproved → pending-approval redirects)
and the strings test now pins `nav.resources` in both locales — 5 passed.
Docs: ADR 0056, CONTEXT.md Phoneme Reference Context (new **Resources
menu** term; **Phoneme reference** now "reached from the navbar's Resources
menu"), this concept rewritten (Surfaces & flow, Typography scoping, i18n,
Tests); `wiki:sync` regenerated the routes reference for the new route.

## 2026-10-01 (fix: sentence edits mark matches stale — only an explicit Re-align re-aligns, ADR 0055)

Second prod incident in the same family (2026-09-30 ~20:50 UTC, after the
2026-09-28 ADR 0051 fix): editing a sentence on the entity page flipped a
fully hand-tuned match to `pending`, and the 5-minute `alignments:resume`
picked it up and re-aligned it — landmark-preserving now, but still deleting
and re-deriving every machine row below similarity 0.90 in a new order
(4 en + 4 ru weak rows scrambled). Root cause: `pending` meant both "fresh
match awaiting its first automatic run" and "existing match whose rows must
be re-derived", and the scheduler fed on both. ADR 0055 splits the meaning:
sentence mutations now flip affected matches to a **display-only `stale`**
status (`EntityController::setMatchesPending()` → `markMatchesStale()`,
flipping `aligning`/`completed`/`failed` → `stale`, leaving fresh `pending`
alone); the scheduler is untouched and therefore never sees stale. Only
explicit human actions re-align or clear the flag: Re-align / Run from
scratch (visible on stale too), a full Filament editor save, a sentence
re-import. `AlignEntitySentences::finalize()`/`failed()` now complete or
fail only from `aligning` (mid-run edits keep the stale flag; repairs still
run). React surfaces got the `stale` badge (Entities/Show, the shared
STATUS_BADGE — phantom `verifying` retired); Filament badge + view-page
blade got warning coloring and stale copy. Tests: EntityEditingTest (4
mutation tests now assert stale + a fresh-pinned-pending test), a rebuilt
AlignmentsResumeCommandTest incident regression (edit → stale → resume ×2 →
rows byte-identical), FilamentReAlignActionTest stale visibility/dispatch,
new AlignmentStaleStatusTest (mid-run stale survival through finalize and
failed(), stale holds no processing slot), persister/import clear stale.
Docs: ADR 0055 (supersedes ADR 0015's pending-on-edit rule), CONTEXT.md
Edit rule + new **Stale** term, sentence-alignment.md (completion gate,
Schedule stage, new ADR 0055 stage bullet), entities-alignment.md (status
lifecycle bullet — the status column was previously undocumented here),
run-alignment.md (steps 4-5: fresh-only pickup, stale handling,
approve-before-re-align workflow).

## 2026-10-01 (fix: deploy-native.sh syncs the python venv + restarts ext-python; UiStringSeeder joins the deploy seed lists)

Yesterday's prod deploy shipped the English stress v2 requirements
(`pyphen`) without the python service ever seeing them: prod runs the native
systemd path (`.github/workflows/deploy.yml` → `deploy-native.sh`), and that
script had no python handling at all. The venv at
`docker-compose/python/ai/ai_env/` is git-ignored (machine-local), so repo
requirements changes only ever reached the Docker image path, and uvicorn
serves the checkout without `--reload`, so even pure `ai/` code changes
silently stayed on the old process until a manual restart. `deploy-native.sh`
now pip-syncs the venv from `requirements.txt` on every deploy
(unconditional — pip is a no-op when satisfied, and this self-heals a
drifted venv), restarts `ext-python` only when the pull touched
`docker-compose/python/` or `ext-python.service`, then polls `/health` for
up to 60s and fails the deploy (journalctl tail to stderr) if the service
doesn't come back — a broken import graph now turns the GitHub Deploy run
red instead of surfacing as broken features. `UiStringSeeder` (idempotent
updateOrCreate + cache flush, in DatabaseSeeder but never in the deploy
seed lists) joined the seed list in both `deploy-native.sh` and
`deploy.sh`, so new ui-strings partials reach prod without manual seeding.
Docs updated: `wiki/architecture/docker-services.md` (native-path step
list) and `wiki/domains/sentence-enrichment.md` (its deployment note was
Docker-only). `wiki/playbooks/production-deployment.md` stays
Docker-era/stale on purpose (not rewritten here).

