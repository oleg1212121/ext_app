---
type: Domain
title: "Phoneme Reference"
description: "Navbar pronunciation guide: grouped sound cards with parametric articulation diagrams for English and Russian"
tags: [frontend, phonetics, reference]
status: stable
generated: { by: agent:zcode, at: 2026-09-30T22:40:00Z }
---

# Phoneme Reference

The navbar has a pronunciation-guide icon (beside the theme toggle) that opens
a modal chart of every English and Russian sound. Each sound is a card with an
IPA symbol, common spellings, a mid-sagittal articulation diagram, a bilingual
production description, example words and a cross-language hint. Clicking a
card opens the enlarged detail view.

For the domain language see `CONTEXT.md` → *Phoneme Reference Context*
(**Phoneme card**, **Articulation diagram**, **Sound group**, **Cross-language
hint**, **RP note**). Decision record: `docs/adr/0054-cc0-sagittal-phoneme-diagrams.md`.

## Surfaces & flow

- Entry: `NavBar.jsx` — icon button after `<DarkThemeToggle/>`, visible to
  guests and authenticated users alike.
- Modal: `resources/js/Components/Phonemes/PronunciationReferenceModal.jsx`
  (portal, Escape/backdrop close, scroll lock — same shell as
  `ModelsUsedPopup.jsx`). Two tabs (English / Russian); the default tab is the
  user's **learning target**: native English speakers land on Russian,
  everyone else on English. The user's native language code reaches the
  client via the shared `auth.user.native_language` prop
  (`HandleInertiaRequests.php`).
- Cards → detail view inside the same modal (back button, no routing).

## Content model

`resources/js/data/phonemes/phonemes.js` — static data, no DB tables:

- `PHONEME_CHART.en` — General American: vowels, diphthongs, stops,
  affricates, fricatives, nasals, approximants (~40 sounds). RP differences
  are per-card `rp` notes.
- `PHONEME_CHART.ru` — six vowels, 15 hard/soft **pairs** (rendered as
  two-card units), always-hard (ж ш ц), always-soft (ч щ й).
- Card shape: `{ipa, spell, art, desc, hint, examples, rp?, anim}` where
  `anim` is a reserved slot (always `null`) for a future animation phase.

## Diagrams (parametric, no image assets)

`resources/js/data/phonemes/art.js` renders a state — tongue
tip/blade/dorsum/root, jaw, lips (close/round/spread/dental), velum
(raised/lowered) — to SVG paths using `currentColor`, so diagrams theme
themselves. Russian soft consonants are the `palatalize()` transform of the
hard state; diphthongs/affricates are two states rendered start → end.
Proportions descend from the CC0 Wright & McCloy mid-sagittal set
(`drammock/phonetics-teaching-assets`), credited in the modal; the ~60 sounds
that set does not cover were authored as derivatives in the same parametric
style.

## Typography

IPA symbols use self-hosted **Gentium Plus** (OFL,
`public/fonts/GentiumPlus-Regular.woff2`,
`fonts/GentiumPlus-OFL.txt`): the app families lack the core IPA vowel and
sibilant glyphs, and Google's unicode-range subsets of Gentium omit ʲ ˈ ː.
Only the symbols use it (`font-['Gentium_Plus']`); body text stays in the
page fonts. Note for the reader: this font is scoped to the modal, it does
not touch `--font-reading` (see `wiki/domains/reader.md`).

## i18n

Modal chrome (title, group names, pair labels, phase labels) lives in
`database/seeders/ui-strings/sounds.php` and `nav.pronunciation_reference` in
`nav.php` (see `wiki/domains/localization.md`). Per-sound descriptions and
hints are keyed `en`/`ru` inside `phonemes.js` — they are content, not chrome.

## Invariants

- `anim` stays `null` until the animation phase; diagrams are plain state
  data so animation interpolates between states rather than replacing them.
- English stays General American; a second variant must stay a per-card note.
- Diagrams never reference external URLs — the CC0/CDT posture of ADR 0054
  (no hotlinking of licensed animation sites) must hold.

## Tests

`composer run test:tia` covers the suite; the feature adds no routes, models
or commands (no `wiki:sync` artifacts changed).
