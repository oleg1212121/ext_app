# ADR 0053: English stress marking v2 — syllable-aligned placement + CMUdict

Date: 2026-09-29
Status: Accepted

## Context

ADR 0052 shipped English stress marks derived from Wiktionary IPA with a
proportional vowel-letter mapping. Real-text review (entity 17) exposed three
defect classes:

- **Misplaced acutes (~8% of all marks).** The proportional map saturates
  onto the word-final silent "e" whenever stress falls on the last nucleus:
  *advancé, becausé, believé, aroúnd, afraí­d* (411 tokens, 53 distinct
  words). Digraph nuclei (aʊ, eɪ) land on the letter *after* the digraph.
- **Inflected forms unmarked.** Wiktionary's plural/past "form-of" pages
  import as word rows without IPA (*gamblers*, and headword *gambler* too);
  the forms table is only consulted for Russian.
- **Hyphenated compounds unmarked.** No dictionary row for *seven-sided* and
  no part-splitting fallback (55 tokens, 54 distinct in entity 17).
- Additionally, monosyllables were unmarked by design (their Wiktionary IPA
  carries no ˈ), which read as inconsistency against Russian, where Silero
  marks every word.

There is no direct Silero equivalent for English. Options researched:
CMUdict (BSD, ~135k entries, ARPAbet stress digits, includes inflected forms
and monosyllable stress), cmudict-ipa (no licence file), espeak-ng+phonemizer
(GPLv3 G2P, new system package), gruut (MIT, dormant), pyphen (tri-licensed
hyphenation).

## Decision

**1. Replace the vowel-letter guess with structure-aware placement** in
`en_stress.py`:

- The ˈ still gives the stressed-syllable index (vowel nuclei before it).
- Primary path: **pyphen** orthographic hyphenation (new dependency,
  GPL/LGPL/MPL tri-licence); when the syllable count matches the IPA nucleus
  count, the acute goes on the first vowel letter of the stressed syllable.
- Fallback: proportional mapping over vowel letters **excluding a word-final
  silent "e"** (while ≥2 other vowel letters remain — *café* keeps its final
  e), and **last-nucleus stress anchors on the last vowel-letter run**
  (fixes digraphs: *afráid, aróund, becáuse*).

**2. Import CMUdict as a second English stress source**
(`dictionary:import-cmudict`, file `laravel/kaikki/cmudict.dict`, BSD):
ARPAbet converted to Wiktionary-style IPA at import (AH0→ə, ER0→ɚ, ˈ/ˌ from
stress digits). Insert rules:

- Word rows already carrying a **ˈ-marked** IPA transcription are skipped —
  Kaikki wins where it has stress data. Rows with only unstressed IPA
  (*turned* /tɜːnd/) gain the CMUdict variant; the existing stress-first hint
  ordering picks it.
- **All class rows** of an l_word without a ˈ variant gain the variants —
  the entity link may point at any of them (die noun vs verb).
- Missing words are created under the `unknown` word class.
- Closed-class function words ("of AH1 V", "the(2) DH AH1") have their
  citation-form stressed variants **dropped**, so they never carry a mark.

**3. Hyphenated compounds** resolve per part: Laravel sends a `parts` hint
([{surface, ipa}] per hyphen segment) when the whole token has no IPA;
python marks each part from its own variants (*SÉVEN-SÍDED*).

**4. Monosyllables are marked** when a ˈ-carrying variant exists
(*cát, túrned, twó*) — consistent with Russian Silero behavior. Unstressed
function words (*the /ðə/*) stay plain because no variant carries ˈ.

## Consequences

- Entity-17 marks-per-token: 32.7% → **59.4%**; the three reported defects
  (GAMBLERS / SEVEN-SIDED / advancé-becausé) are all fixed and regression-
  tested.
- CMUdict has no POS: homograph variants are stored in file order, first ˈ
  variant wins (*record* always verb-stressed). Noun/verb stress-shift
  disambiguation would need the POS-tagged kaikki IPA — deferred.
- CMUdict is GenAm; British-only shifts stay sourced from RP-tagged
  Wiktionary IPA. A handful of proportional-mapping edge cases remain
  (*idea* → ideá).
- `pyphen>=0.18` joins `docker-compose/python/requirements.txt` — a
  container-definition change: rebuild the python image and
  `./deploy.sh --stamp` on deploy.
- Out-of-dictionary proper nouns (RUDY, STEINER) stay unmarked; an
  espeak-ng G2P fallback was considered and deliberately deferred.
- Re-enrichment stays free and idempotent (ADR 0052): reset `enriched_at`
  and let the sweep or the Filament action rebuild.
