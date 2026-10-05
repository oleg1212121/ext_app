# ADR 0068: Reading-surface chrome kit

Date: 2026-10-05
Status: Accepted

## Context

ADR 0060 unified the reading surfaces' *payload* (Reading rows, word maps,
eligibility flags, one presenter), and ADR 0067 unified the annotation
*preferences* — but the toolbar mechanics stayed hand-wired per surface.
The language side radiogroup, the annotation toggles (with the same
"always-grey icon" rule commented in both files), the font stepper, the
page-number input, and the word-progress recolor existed twice — once in
the simulator, once in the reader — differing only in CSS token palette,
type sizing, and value conventions. Every new Working-state control re-paid
the tax on both surfaces (the stress and phrasal toggles each landed twice).

Two forces made a naive unification wrong: the surfaces' palettes are
deliberate design choices (the simulator's workbench `--wbench-*` family vs
the reader's reading-room `--color-*` family), and several behaviors are
deliberately different (font ranges 12–48/26 vs 16–38/20, mirrored in the
controllers' saved-value clamps; page commits via POST fetch vs Inertia
partial reload per ADR 0032; font application via injected stylesheet vs
prop threading).

## Decision

**One kit, parameterized by surface variant — application, transport, and
persistence stay per surface.**

- `Components/ReadingSideRadiogroup.jsx` — the side radiogroup as a purely
  presentational module: `options/value/onChange` plus `variant: 'sim' |
  'reader'` carrying the token palette and typography. Value semantics stay
  with each surface: side letters and `useSideFlip.toggleTo` on the
  simulator; language codes plus the same-code no-op guard on the reader.
- `Components/AnnotationToggle.jsx` — the persistent, autosaved
  annotation toggle (the grey-icon rule stated once). The simulator's
  panel-visibility tabs share the file's tab primitives via the exported
  `PanelToggleTab`; the reader keeps its tinted `ToggleButton` for momentary
  view toggles.
- `hooks/useFontSize.js` — clamp/step state with numeric deltas; autosave
  stays in each page's single `useUiSettingsAutosave` call (one writer per
  settings section), and the simulator's `'+'`/`'-'` string-direction
  convention is gone.
- `Components/PageInput.jsx` — input mirroring, commit policy (the
  simulator commits on Enter, the reader on blur, with the reader's
  type=text/inputMode rationale), NaN handling, and clamping via the shared
  `lib/pagination.mjs::clampPage`; the commit action is the surface's.
- Pure primitives one-homed: `patchWordStatus` (word-progress recolor) and
  `clampPage` in `lib/`, `loadJson`/`saveJson` under the three
  Working-state localStorage stores (whose shapes stay separate), and
  `SpinnerSvg` for the reader's restore placeholder.

**The descriptor contract becomes shared.** `TextContent` takes the
reader's display-column descriptors — `{side, language, wordMap,
highlightable, explainable}`, with `language` added for the simulator's
column headers — replacing the six exploded `target*`/`base*` props.
Descriptors are memoized on raw state (the reader pattern); WordText's
`React.memo` depends on it.

Deliberately *not* unified: font ranges, paging transport, autosave key
names (`highlight` vs `highlight_words` — renaming would discard saved user
settings), i18n key namespaces, palettes.

## Consequences

- The next Working-state control (or annotation toggle, via the ADR 0067
  descriptor) lands once and renders on both surfaces; the grey-icon rule
  and the clamp/commit mechanics have one home each.
- The variants preserve each surface's look exactly; no visual change is
  intended or tested for. Per-surface differences live in the variant maps
  and the pages, not in divergent copies.
- Descriptors must stay memoized — re-deriving them per render silently
  degrades WordText's memoization (the load-bearing freeze fix). This is
  now stated at the contract, not discovered per surface.
- ADR 0067's deferral is closed: the chrome kit exists; the wire never
  moved.
