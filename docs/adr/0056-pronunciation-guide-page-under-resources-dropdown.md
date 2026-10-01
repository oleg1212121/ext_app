# ADR 0056: Pronunciation guide becomes a page under a Resources dropdown

Date: 2026-10-01
Status: Accepted

## Context

The phoneme reference (ADR 0054) shipped as a modal opened from an unlabeled
icon beside the theme toggle in the navbar. The affordance was weak — an
icon-only button that guests could also see — the chart was not linkable or
shareable, and the feature sat outside the app's information architecture,
where every other surface lives under a labeled menu (Practice, Library,
Puzzles). The single-child Puzzles dropdown already established that a menu
may host exactly one page.

## Decision

1. **The modal and its navbar icon are removed.** The guide becomes an
   Inertia page at `/resources/pronunciation-guide`, reached from a new
   top-level **Resources** dropdown placed after Puzzles — in both
   `NavBar.jsx` and the Blade mirror `layouts/navigation.blade.php`.
2. **Resources is the umbrella for reference surfaces** — content that
   supports study but is not itself a practice flow. The pronunciation guide
   is its first child; more may follow, at which point the menu simply grows
   children (the navbar renders from data).
3. **Access moves to the standard `auth` + `approved` route group**, like
   every other dropdown child. The guide is no longer reachable by guests —
   accepted as the cost of consistent navigation; the icon's guest visibility
   was incidental, not a requirement.
4. **No URL state for tabs or the selected sound.** The English/Russian tab
   and the open card stay client-side state, exactly as in the modal; the
   page URL is the deep-linkable unit. Per-sound permalinks can be added
   later without changing this decision.

## Consequences

- The guide is discoverable by label and behaves like every other surface:
  full-page scroll, standard layout, browser back works.
- The modal's a11y machinery (focus move, scroll lock, Escape/backdrop
  close) disappears with the modal; state resets on navigation instead.
- Guests lose access to the chart until they log in.
- `nav.resources` is a new seeded UI string; the child item reuses the
  existing `nav.pronunciation_reference` string ("Pronunciation guide" /
  "Справочник произношения"), which reads correctly under "Ресурсы" without
  redundancy.
