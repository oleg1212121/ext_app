# 0036 - Alignments live under their work

The alignment browse/create surface was global: `/alignments` listed every
readable `EntityMatch` across all works, `/alignments/create` picked a work
from a dropdown and then two of that work's entities. An entity match,
however, is already work-scoped by construction — it pairs two entities of
the same work, and creation enforced that. A global list duplicates a scope
the data does not have, and the work picker existed only to serve the global
entry point. Decision: **the work page becomes the single home for
alignments**. `/library/{work}` grows an Alignments tab (`?tab=alignments`,
next to the existing Entities tab) listing the work's readable matches with
the information the old global table carried (both sides with language tags,
entity similarity, confirmed/linked progress, per-side sentence counts,
status, created date). "Add alignment" leads to
`/library/{work}/alignments/create` + `POST /library/{work}/alignments` —
the old create form minus the work picker, since the route names the work.
The global `GET/POST /alignments` and `GET /alignments/create` routes, the
`Alignments/Index` and `Alignments/Create` pages, and the navbar
"Alignments" item are deleted.

Deliberate and worth recording:

- **The editor stays global.** `GET /alignments/{entityMatch}` and its JSON
  endpoints keep their paths: the editor is reached from the match's card
  (the whole card links to it), from the duplicate-pair guard, and from
  bookmarks; moving it under `/library/{work}/alignments/{id}` would drag
  the twelve JSON endpoints along for zero functional gain.
- **The card's reader button is per-user, resolved server-side.** An
  alignment has two entities and the reader route takes one. The card's
  "Read · {LANG}" opens the side whose language is **not the user's native
  language** (the native side renders as the translation column), with
  tiebreaks: the work's original side (`EntityMatch::originalSide()`), then
  the A-side. The payload ships a server-computed `reader_target` so the
  rule lives in one place.
- **Per-alignment simulator entry.** Cards link to a new pinned route,
  `GET /bilinguals/simulator/{entityMatch}`, which renders the simulator
  with the text selector hidden and the match fixed by the URL — the en/ru
  path segment was dropped because the match itself carries both languages.
  The old dropdown page `/bilinguals/en/ru/simulator` stays as the navbar
  "Bilinguals" landing.
- **Search is by entity name.** The tab's `?q=` matches either side's entity
  name — the two names are what a card displays. Language-code or label
  search can be added later without changing the URL contract.

Per-work scoping keeps the access story unchanged: `readableMatchQuery`
still filters to matches with both sides readable, so a work's tab can only
widen what leaks if the constraint itself is wrong. The same-work invariant
moves from "both entities agree with each other" to "both entities belong to
the route work" — stronger, since the URL now asserts the scope.

Alternatives considered: nested tab routes (`/library/{work}/alignments`)
were rejected because the page already paginates and searches via query
params and a second URL level would fork every link for no data difference;
keeping the global list in parallel was rejected because two creation entry
points and two lists for one work-scoped concept is the confusion this
rework removes.
