# Reader pagination and reading position

The reader pages its rows server-side: `/reader-react/{lang}/{entityId}`
serves a fixed 50 rows per page under `?page=N` (clamped into range — junk
and out-of-range values land on the nearest valid page, never a validation
error), and the payload carries only the page's rows, row keys, and word
maps scoped to those rows' tokens, plus a flat `meta`
(`current_page`/`per_page`/`total`/`last_page`). The last page reached per
text — the **Reading position** — is a Working-state kind (ADR 0024's
per-device tier, extended from the simulator to the reader): stored in
localStorage under `ext_app.reader.position.v1` as `{positionKey: page}`,
written on every page turn, and restored on open by history-replacing to the
saved page, clamped to the text's current last page. The position key is
`mm:{entityMatchId}` for matched texts — both reading sides share it, since
they page through the same rows — and `ent:{entityId}` for single-language
texts; `es:` is deliberately not used, because that prefix already names a
single entity sentence in row-key vocabulary.

**Status**: accepted

## Considered Options

- **Server-side pagination (chosen).** A book-length text stops shipping in
  one payload — rows, keys, and word maps all scale with the page, not the
  text. `?page` makes pages linkable and Back/refresh-safe, and mirrors the
  simulator's paginated `POST /text` endpoint.
- **Client-side slicing.** Rejected: page turns would be instant but the
  initial payload stays full-size — navigation polish, not a fix; whole
  books are the reader's normal case.
- **Server-side position in `ui_settings`.** Rejected: makes every page
  turn a PATCH — the churn ADR 0024 explicitly rejected for position data
  — and implies cross-device position continuity nobody asked for.
- **Row-level restore (deferred).** The simulator also remembers the last
  opened row and scrolls it into view; the reader restores pages only. The
  per-text store can hold a row later without a format change.
- **Selectable page size.** Rejected: fixed at 50, the simulator's default;
  `per_page` persistence is a known deliberate gap even there.
