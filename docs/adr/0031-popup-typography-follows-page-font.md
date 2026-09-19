# 0031 - Word-popup typography follows the host page's font setting

The Word popup is shared by the bilinguals simulator and the reader, and both
pages let the user scale their reading text with toolbar `+`/`−` buttons —
but the popup itself had a hardcoded 460px width and fixed 14px body text, so
a user who reads large saw the lookup surface stay small. Decision: the
**popup's typography derives from the host page's font-size setting** at
render time. Each page computes `popupFontSizeFor(pageFontSize)` (65% of the
page font, floored at 14px so small fonts never shrink the popup below its
original size, capped at 32px) and passes it down; the popup's width scales
proportionally (560px at the default 17px, still clamped to the viewport) and
every inner text size is em-relative to the root font.

A separate persisted `popup_font_size` with its own `+`/`−` control was
rejected: the popup is always read alongside the page text, so one scale
keeps them coherent, and it avoids a second knob in `ui_settings` and the
toolbar. A fixed larger width was also rejected — at maximum page font the
popup would look cramped again. Callers that pass no size get the 17px
default, which keeps `WordPopup` usable standalone.

## Consequences

- No new persistence: the popup font rides on the existing
  `simulator.font_size` / `reader.font_size` (validator bounds unchanged).
- The derivation lives in one place (`popupFontSizeFor`, exported from
  `WordPopup.jsx`) next to the component it governs; pages only pass numbers.
- Changing the page font re-renders only future popups (the popup is mounted
  per lookup); an open popup keeps its size until closed.
