# 0030 - Opening the word popup requires Ctrl+click

On every interactive reading surface (bilinguals simulator, reader), an
Interactive word opens the Word popup only on **Ctrl+click**. A plain click
does nothing. The gesture is deliberately Ctrl-only — Cmd/⌘ is not accepted —
so the contract is one modifier on every platform.

Before this change a plain click opened the popup, which meant reading with a
pointer in the text constantly fired accidental lookups: every stray click
cost a **Lookup event** (−2 Word familiarity) and tinted the word one tier
closer to "unknown". Ctrl+click separates the reading gesture from the
dictionary gesture, so the tint reflects words the reader actually looked up.

The alternative of keeping plain-click and treating Ctrl+click as a synonym
was rejected: it leaves the mis-credit problem untouched. The components are
shared by both surfaces, so the reader gets the same gesture; forking
the interaction model per surface was not worth the divergence. Known cost:
on macOS Ctrl+click opens the browser context menu, so the gesture targets
Windows/Linux users (macOS support would need a different modifier and is
deferred until wanted).

## Consequences

- `WordText` gates popup opening on `event.ctrlKey` but still calls
  `event.stopPropagation()` on every click, so a plain click can never toggle
  the reader row underneath.
- Hover affordance on Interactive words is an underline (no color change), so
  the knowledge tint stays legible on hover.
- The popup payload now covers every Word under the Headword (all parts of
  speech, plus etymology) — see `wiki/domains/interactive-words.md`.
