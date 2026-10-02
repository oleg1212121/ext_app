# ADR 0058: Phrasal lead resolution via verb-lemma candidates

Date: 2026-10-02
Status: Accepted

## Context

Real-text review (entity 17) showed "came forward" unflagged while the
dictionary plainly contains "come forward" / "came forward" headwords. Root
cause: the matcher requires the lead token's `cls` hint to be `verb`, but
the hint carries the *single* dictionary word the entity linker picked —
and `CLASS_PRIORITY` ranks noun above verb, so POS-ambiguous surfaces
resolve to their noun page: the stained-glass **noun "came"** outranks the
verb, and "came" (also "went", "turn", "cut", "sat", "made", … — exactly
the verbs that head everyday phrasal verbs) can never lead a match.

A classified probe of entity 17 (curated lead/particle lists, 1 545
sentences) found 93 windows whose phrase is a stored multi-word verb
headword against 6 stored hits: **62 blocked by the non-verb lead class**,
31 more where the verb-classed lead's headword is the inflected form
itself ("looked" is stored with word = "looked", not "look"), so the single
lemma hint missed too. The remaining ~68 unflagged windows had no lexicon
headword at all — literal verb+preposition uses the dictionary correctly
does not flag.

## Decision

The phrasal enricher becomes self-sufficient instead of trusting the single
class hint, still purely dictionary-driven (ADR 0052's ground rule):

- **Laravel** (`EnglishPhrasalVerbEnricher::tokenHints`): a new per-token
  hint `verb_lemmas` — every verb-class headword for the surface, from
  direct verb rows (`l_word = surface`) plus verb base words reached via
  the `forms` table, deduped, capped at 8. Only computed when the phrasal
  enricher runs.
- **Python** (`phrasal.find_phrasal_verbs`): a lead is a candidate when its
  class hint says verb (trying the linked lemma, or the surface) or when
  `verb_lemmas` is non-empty (trying those lemmas). Longest window wins
  first, candidate order breaks ties within a length; everything else
  (3/2-token windows, non-overlap, spans into `content`, `phrase` field)
  is unchanged. A non-verb lead with no `verb_lemmas` still never matches.
- `EnrichToken` gains `verb_lemmas: list[str] | None`; `tokenPayload()`
  ships it beside the other hint fields.

## Consequences

- Entity 17 re-enriched `en_phrasal`-only: **6 → 243 hits** (all carrying
  `phrase`), with the `en_stress` stamp untouched — the per-enricher
  staleness from ADR 0057 driving a targeted backfill in practice.
- Precision note: the lexicon is *all* multi-word verb headwords, so some
  hits are Wiktionary headwords rather than prototypical phrasal verbs
  ("be there", "fed up"). Tightening that is lexicon curation, not matcher
  logic.
- Coverage is now bounded by lexicon completeness, a data matter: "come
  forward" exists in the source dump and is importable, but the dev
  database's import predates it — re-running the dictionary import
  (playbook) widens detection with no code change. Enrichment is not
  re-staled by dictionary imports; a targeted `entities:enrich
  --enricher=en_phrasal` re-run applies a grown lexicon to the existing
  corpus.
- Separable verbs with an intervening object ("gave it up"), 4+ token
  idioms, and genuinely missing headwords remain out of scope — they need
  a different class of tooling (contiguous n-grams are ADR 0052's design).
