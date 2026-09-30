# ADR 0054: Phoneme reference — parametric CC0-lineage diagrams, no audio, no external media

Date: 2026-09-30
Status: Accepted

## Context

The pronunciation guide (navbar icon → modal) shows every English and Russian
sound with a mid-sagittal articulation diagram. The obvious content sources
were researched and all but one fail licensing:

- **Sounds of Speech (University of Iowa)** — the gold standard per-phoneme
  animations, © UIRF, no reuse grant. Link/permission only.
- **Seeing Speech / Dynamic Dialects / Speech Star / Syracuse articulatory
  GIFs / логопед sites** — either no stated license, "research use only", or
  CC BY-NC-ND. Not bundleable.
- **Wikimedia Commons** — has per-phoneme audio (CC BY-SA 3.0, needs
  attribution and share-alike on derivatives) and a handful of per-file
  diagrams of wildly varying style; no complete open set.
- **Wright & McCloy mid-sagittal SVG set** (`drammock/phonetics-teaching-assets`,
  also on Commons) — **CC0 1.0**, covers ~24/44 English and ~14/40 Russian
  phonemes, explicitly allows derivatives.

Even the CC0 set cannot cover both inventories (English diphthongs/affricates,
the Russian palatalized series, ы, ц, ч, щ need original drawings), so any
complete feature requires authoring new artwork regardless.

## Decision

1. **Diagrams are generated parametrically, not drawn as image files.**
   `resources/js/data/phonemes/art.js` renders an articulation state (tongue
   tip/blade/dorsum/root, jaw, lips, velum) to SVG paths with `currentColor`,
   so every diagram is theme-correct in light and dark mode from one source.
   Proportions descend from the CC0 Wright & McCloy set (credited in the
   modal), which makes the whole feature — including the ~60 sounds the CC0
   set does not cover — CC0-clean without attribution obligations.
2. **Sounds are static data** in `resources/js/data/phonemes/phonemes.js`:
   IPA, spellings, bilingual description, cross-language hint, example words,
   optional RP note. Reference content ships with the app; it is not a DB
   table — it changes only with a release, has no admin surface, and no
   per-user state.
3. **No audio in v1.** Commons audio is CC BY-SA, which would introduce the
   app's first copyleft obligation and need a credits surface. The card shape
   reserves `anim: null` for a future animation phase; diagrams interpolate
   between existing states when that phase comes.
4. **English is taught as General American** to agree with the CMUdict-derived
   IPA users already see in word popups; RP differences are per-card notes,
   not a second chart. Russian follows the practical hard/soft pairing.
5. **IPA glyphs render in self-hosted Gentium Plus (OFL)** — the app families
   (Source Serif 4, Figtree, Fraunces, IBM Plex) lack the core IPA vowel and
   sibilant glyphs (verified against the shipped files' cmaps); Google's
   unicode-range subsets of Gentium omit ʲ ˈ ː, so the full font ships as one
   woff2.

## Consequences

- Zero third-party license obligations for the diagrams (CC0); OFL for the
  font (license file kept in `public/fonts/GentiumPlus-OFL.txt`).
- New sounds cost a parameter set, not artwork; consistency across all ~80
  diagrams is guaranteed by construction.
- The schematic style is deliberately less anatomically rich than licensed
  photographic/MRI alternatives; it favors legibility of the constriction
  places at card size.
- If audio is ever wanted, the CC BY-SA obligations (credits surface,
  share-alike on derived audio) are accepted consciously, not by accident.
