# 0038 - The Practice menu revives the reader index and the simulator picker

The ADR 0036 amendment disposed of the reader's text library
(`GET /reader`, `GET /reader/{lang}`) and the simulator's text picker
(`GET /bilinguals/en/ru/simulator`) as "deep links only": both surfaces were
reachable solely from an alignment card, and the navbar items were removed.
In practice that made the app work-hub-gated — a learner looking for
something to read or train on had to know which work, which alignment, which
card. The owner reversed the disposal: reading and training are primary
activities and need standing navbar entries.

Decisions:

- **A "Practice" dropdown, first in the navbar.** Its entries are Reader
  (the revived text library) and Simulator (the standalone trainer). The
  navbar's dropdown state is generalized from a single hardcoded
  "puzzles" toggle to a per-label open/expanded menu, so any number of
  dropdown groups can coexist. The Blade nav mirror
  (`layouts/navigation.blade.php`) gets the same group for the non-Inertia
  auth pages.
- **Old-style short URLs, new route records.** `GET /reader/{lang?}` (the
  index; bare `/reader` derives the user's native enabled language,
  fallback en) and `GET /simulator` (the picker). Registration order and
  constraints keep the shapes disjoint: `whereNumber` on the reading route's
  `{entityId}` vs `[a-z]{2}` on the index's `{lang}`. The ADR 0037 reading
  route (`GET /reader/{entityId}`) and the ADR 0036 pinned simulator route
  (`GET /bilinguals/simulator/{entityMatch}`) are untouched.
- **Only the entry pages are revived; nothing regresses.** The reader index
  is restored from git and retargeted: clicking a text opens the current
  `GET /reader/{entityId}` page — the side rule, the language toggle, and
  the freeze fixes all stay. The old two-segment reading shape
  (`/reader/{lang}/{entityId}`) and the old picker URL
  (`/bilinguals/en/ru/simulator`) remain deleted outright per the ADR 0036
  house style; only the `/reader` and `/reader/{lang}` guards are lifted.
- **One simulator page, two entries.** The pinned entry renders exactly as
  before. The `/simulator` entry passes no pinned match, restores the
  Select + Load picker in the header, and preselects the last picker choice
  from the per-device position store; Load fetches the chosen match in
  place via `POST /text` (no reload). Because the language toggle needs the
  loaded match's sides, `POST /text`'s entity-match branch now also ships
  `languages` and `default_learning_side` — additive fields, computed by the
  same side rule the pinned route uses at render time.
- **The model line stays text + link.** The answer model renders as its
  label linking to the Profile's AI tab (`/profile?tab=ai`) on both
  entries; no model picker on the page (ADR 0035 unchanged).

Alternatives considered: a thin `/simulator` launcher that redirects into
the pinned route was rejected — it reloads the page on every pick and
would not be the restored behavior; a `/practice/*` URL prefix was rejected
in favor of the original short URLs the owner asked for; reverting the
reading page itself (route shape, toggle, freeze fixes) was explicitly
rejected — only the disposed entry points come back.
