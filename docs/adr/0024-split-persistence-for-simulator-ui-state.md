# Split persistence for simulator UI state

The bilinguals simulator's UI state is persisted in two places by write
frequency. Stable settings — font size, panel visibility, AI model, the
assessment question, panel drag sizes — live in a `user_settings.ui_settings`
JSONB column (ADR 0017's table, extending its purpose), seeded into Inertia
props on page load and written back by a debounced (~800 ms) autosave PATCH to
`/ui-settings`, section-merged so a simulator save never wipes the reader
section. Working state — the selected entity match, the current page per
alignment, and the last opened row (with which of its EN/RU halves were
revealed) — lives in localStorage per device under
`ext_app.simulator.position.v1`, written immediately on every change, and is
never sent to the server. The simulator auto-loads the saved alignment at its
saved page on mount and scrolls the saved row into view with its checkboxes
re-checked; switching alignments restores each alignment's own position.

**Status**: accepted

## Considered Options

- **Split by write frequency (chosen).** Settings change rarely and benefit
  from cross-device continuity (they follow ADR 0017/0023's server-side
  settings direction); position changes on every page turn, where a DB write
  per turn is needless churn and per-device is the honest scope.
- **All localStorage.** Rejected: a cache clear or a second device loses the
  tuned assessment question; also contradicts the established
  `user_settings` pattern.
- **All database.** Rejected: makes every page turn a PATCH, needs validation
  and merge logic for volatile data, and implies cross-device position
  continuity nobody asked for.
