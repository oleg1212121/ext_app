---
type: Domain
title: "Phoneme Reference"
description: "Resources-menu pronunciation guide: grouped sound cards with parametric articulation diagrams for English and Russian"
tags: [frontend, phonetics, reference]
status: stable
generated: { by: agent:zcode, at: 2026-10-01T17:51:53Z }
---

# Phoneme Reference

The navbar's **Resources** menu (after Puzzles) holds a pronunciation-guide
page charting every English and Russian sound. Each sound is a card with an
IPA symbol, common spellings, a mid-sagittal articulation diagram, a
bilingual production description, example words and a cross-language hint.
Clicking a card opens the enlarged detail view.

For the domain language see `CONTEXT.md` → *Phoneme Reference Context*
(**Resources menu**, **Phoneme card**, **Articulation diagram**, **Sound
group**, **Cross-language hint**, **RP note**). Decision records:
`docs/adr/0054-cc0-sagittal-phoneme-diagrams.md` (content + diagrams),
`docs/adr/0056-pronunciation-guide-page-under-resources-dropdown.md`
(page + menu placement).

## Surfaces & flow

- Entry: the **Resources** dropdown in the navbar, after Puzzles — rendered
  from the data-driven `navLinks` array in `NavBar.jsx` and mirrored in
  `layouts/navigation.blade.php` (Alpine). Route:
  `resources.pronunciation-guide` → `/resources/pronunciation-guide`, inside
  the `auth` + `approved` group; the page replaced the ADR 0054 icon+modal
  (both removed) and is no longer reachable by guests.
- Page: `resources/js/Pages/Resources/PronunciationGuide.jsx` — the former
  modal's tab bar and card grid without the modal shell (no portal, focus
  trap, scroll lock; navigation resets state). Two tabs (English / Russian)
  keep client-side state — no URL state (ADR 0056). The default tab is the
  user's **learning target**: native English speakers land on Russian,
  everyone else on English, recomputed when the shared prop changes
  (login/logout without reload). The user's native language code reaches the
  client via the shared `auth.user.native_language` prop
  (`HandleInertiaRequests.php` — note the method call, not property access:
  `nativeLanguage()` is not a relation).
- Cards → detail view swaps in place inside the page (back button, no
  routing).

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
(`drammock/phonetics-teaching-assets`), credited on the page; the ~60 sounds
that set does not cover were authored as derivatives in the same parametric
style.

## Typography

IPA symbols use self-hosted **Gentium Plus** (OFL,
`public/fonts/GentiumPlus-Regular.woff2`,
`fonts/GentiumPlus-OFL.txt`): the app families lack the core IPA vowel and
sibilant glyphs, and Google's unicode-range subsets of Gentium omit ʲ ˈ ː.
Only the symbols use it (`font-['Gentium_Plus']`); body text stays in the
page fonts. Note for the reader: this font is scoped to the guide page, it
does not touch `--font-reading` (see `wiki/domains/reader.md`).

Comments in `resources/css/fonts.css` must stay `/* */` block comments: the
Tailwind/Lightning parser silently drops the rule that follows a `//` line
comment — a `//` note before the Gentium `@font-face` shipped the feature
with the font missing from the built CSS.

## i18n

Page chrome (title, group names, pair labels, phase labels) lives in
`database/seeders/ui-strings/sounds.php`; the menu name is `nav.resources`
and the child item `nav.pronunciation_reference`, both in `nav.php` (see
`wiki/domains/localization.md`). Per-sound descriptions and hints are keyed
`en`/`ru` inside `phonemes.js` — they are content, not chrome.

## Invariants

- `anim` stays `null` until the animation phase; diagrams are plain state
  data so animation interpolates between states rather than replacing them.
- English stays General American; a second variant must stay a per-card note.
- Diagrams never reference external URLs — the CC0/CDT posture of ADR 0054
  (no hotlinking of licensed animation sites) must hold.
- Tabs and the open card stay client-side state (ADR 0056); per-sound
  permalinks, if added later, do not reintroduce modal shell state.

## Tests

`tests/Feature/PhonemeReferenceTest.php` pins the Laravel-side contract: the
`auth.user.native_language` shared prop (set, and null); page access
(approved user gets `Resources/PronunciationGuide`, guests redirect to
login, unapproved users to pending-approval); and the seeded `sounds.*`,
`nav.pronunciation_reference` and `nav.resources` UI strings in both
locales. The page itself is frontend-only (no JS test runner in the repo);
the feature adds one route (reference artifacts regenerated by `wiki:sync`)
and no models or commands.
