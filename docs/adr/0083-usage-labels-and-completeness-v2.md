# ADR 0083: Usage labels are a closed, going-forward set, and completeness re-weights to 0–9 with live entries before demoted ones

Date: 2026-10-20
Status: Accepted

Implements popup-ai-enrich tickets 09 + 10 (follow-up spec decisions 8 + 9).
Extends the enrichment line of
[ADR 0082](0082-ask-ai-enrichment-merge-inline-lock-on-success.md): its
merged-in definitions are exactly where the new usage labels come from on the
AI side, and its "enriched content participates everywhere automatically"
consequence is what made the completeness score the right place for
importance to land. Records four decisions: the closed label set, no
backfill, the v2 weights, and live-before-demoted ordering.

## Context

The dictionary stores what a word means but not how usable a sense is:
"costume" and "outfit" sit beside senses marked obsolete in every real
dictionary, and the popup — ordered by richness alone since ticket 02 —
can lead with a dead sense. Meanwhile the ask-AI enrichment (ADR 0082)
writes definitions with no register information at all. Two forces meet:
sense-level usage labels need a home, and the popup's ordering needs to
know that importance is not the same as row count. GoldenDict offers
neither: its labels were flattened away during the HTML conversion, before
this project had a column to hold them.

## Decision

1. **Labels are a closed set, stored per definition, going forward only.**
   `definitions.labels` is a nullable jsonb list over the closed set
   `obsolete, archaic, dated, colloquial, rare, dialectal`
   (`App\Support\UsageLabels::SLUGS`); null = unlabelled. `SLUGS` is the
   single source of truth: the kaikki importer maps `senses[].tags`
   through `UsageLabels::fromKaikkiTags` (intersect — grammar/gender noise
   and unknown slugs drop), the AI reply's `{text, labels}` definition
   objects filter through the same helper, and the merge stores whatever
   remains. The set is closed on purpose: the recalc SQL can mirror a
   label test only while the slugs stay enumerable (decision 4). New
   label sources from now on: kaikki sense tags at import, AI reply
   labels at enrichment.
2. **No backfill (user decision 2026-10-20).** The ~1.5M existing
   definition rows keep null; GoldenDict labels are unrecoverable — the
   HTML conversion flattened the headwords' usage markers away before the
   column existed (documented, no code). Demotion wakes per-word as
   labelled data accumulates.
3. **Completeness v2 (0–9): importance as well as richness.** The stored
   `words.completeness` matrix re-weights (was 0–7): ≥ 1 linked
   translation 3 (was 2); definitions 2 when the entry's class slug is a
   major POS — `noun/verb/adjective/adj/adverb/adv`, both adjective/adverb
   spellings accepted — and 1 on any other POS (was 1 flat); 0 when
   definitions exist but **every** one carries a demoting label
   (`UsageLabels::DEMOTING` = obsolete/archaic — an entry with no
   definitions is live, never demoted); transcriptions, examples,
   etymologies, and a real frequency rank stay 1 each.
   `WordCompleteness::MAX` = 9. The score still drives exactly one thing —
   popup entry ordering — and existence (not row counts) still scores.
4. **Live entries sort ahead of demoted ones, and the SQL mirror is a
   requirement, not an optimization.** `WordFamily::resolve`'s stable sort
   becomes [live entry before demoted entry, then completeness
   descending] over today's assembled order (linked-first, class
   priority, base-group frequency remain the tiebreaks); a demoted entry
   sinks below every live one even at a higher score.
   `WordCompleteness::isDemoted` is the one demotion test, shared by the
   score and the sort so they cannot drift, and it judges the word's
   **stored** definition set: the popup's relay-gloss display filter
   ("simple past of see" stripped from partially-relay entries) swaps the
   loaded relation only AFTER the recalc and the sort have run — judging
   the filtered set would let the lazy path demote an entry the
   `words:recalc-completeness` sweep (relay glosses are unlabelled, live
   rows in SQL) keeps up, and `words.completeness` would flap depending
   on which ran last. `words:recalc-completeness`
   must compute the same matrix set-based in SQL — the demotion check
   mirrors `DEMOTING` through `jsonb_exists_any(labels,
   array['obsolete','archaic'])` (the `?|` operator's function form; PDO's
   `?`-placeholder parser would eat a literal `?|`) and the major-POS case
   joins `word_classes` — with both slug lists read from the PHP constants
   at build time. The command test pins the mirror; a weight change is
   corrected by a full sweep (the v2 re-pin ran 2026-10-20, see
   `wiki/log.md`), and the lazy staleness recalc covers per-word drift as
   labelled rows arrive.

## Consequences

- The popup can now lead with the live sense of a word whose richest
  entry is dead — but today it usually doesn't yet: with zero labelled
  rows in the dictionary (no backfill), demotion changes nothing until
  the import and the AI start labelling. Ordering behavior is in place,
  waiting on data.
- Non-demoting labels (dated, colloquial, rare, dialectal) ride along for
  future rendering but never demote — only the `DEMOTING` subset does.
- The score's meaning changed for every stored row (full sweep rewrote
  1,225,100 of 1,494,359 words), so anything comparing scores across the
  2026-10-20 stamp boundary is comparing different scales. The score
  remains popup-internal — nothing else reads it.
- A definition edited in the admin (labels added/removed) re-weights its
  word on the next popup open through the existing staleness stamp — the
  labels ride the definition row's `updated_at`, no new stamp machinery.
- Ticket 09's closed-set rule extends to any future label source: a new
  slug means a `SLUGS` (and possibly `DEMOTING`) change plus a re-sweep,
  not a schema change.

## Amended 2026-10-23 (popup-usage-labels: labels surface in the popup and Anki; update-on-reimport supersedes no-backfill)

Decision 2's no-backfill is **superseded** (user decision 2026-10-23):
the definition flush is no longer insert-only. A case-insensitively
same-text stored row (the very `word_id|lower(text)` key the old
insert-new-only path skipped on) has its labels refreshed to the incoming
value — null included, so a source that stopped tagging a sense unlabels
it again, and a repeat import converges to zero further updates — while
the stored definition TEXT is never rewritten (case-only matches update
in place), rows the ask-AI enrichment wrote (`source = 'ai'`) are another
writer's and keep their labels whatever the dump says, and etymologies
keep the plain insert-new-only behavior. Every changed row counts into a
`definitions_labels_updated` stat in the import summary, and there is
still no row-rewrite migration: the backfill IS the re-import. Demotion's
"wakes per-word as labelled data accumulates" (consequence 1) now has its
data path; decision 1's "going forward only" narrows to "the closed set
stands, the label sources gain the re-import refresh".

Decision 2's GoldenDict rationale also stands unchanged — inline markers
stay unparsed; with the surfacing below landed, teaching the importer to
read them is a follow-up candidate, not part of this record.

The labels also stopped being DB-only:

- **The popup payload ships them** — `WordPopupPayload::entry`'s
  definitions are `{text, labels}` objects (a null column lands as the
  empty list, so the client never special-cases the unlabelled shape),
  both popup endpoints building from the one assembler, so the shapes
  cannot drift.
- **The popup renders them as localized chips in two display tiers** —
  one chip per label resolved through the six `word.label_*` ui-strings
  (en the plain words; ru/be the dictionary abbreviations), vermilion
  chips + the whole sense line at 50% opacity when any of
  obsolete/archaic/rare/dialectal is present, a muted plain-text chip for
  dated/colloquial. The display dimming set deliberately differs from the
  completeness `DEMOTING` subset (obsolete/archaic): it lives JS-side
  (`resources/js/lib/usageLabelDisplay.js`), and decision 4's recalc SQL
  and scoring are untouched — a rare- or dialectal-only entry still ranks
  live.
- **Anki notes carry them as a plain-text prefix** — `(устар., редк.)
  Жизнь, быт…`, composed once in the shared definitions section so the
  flat Definitions field and the Body's per-entry section cannot drift,
  both tiers composed identically (the prefix is study information, not a
  visual demotion), and senses without a known label rendering
  byte-identically to before.
