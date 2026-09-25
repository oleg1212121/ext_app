# 0037 - Reading side defaults to the native language; the reader route drops its language segment

Both reading surfaces hardcoded a side choice. The reader's
`GET /reader/{lang}/{entityId}` treated the URL's entity as "the text you
read" — the `{lang}` segment merely 404'd on mismatch and fed a header badge,
since entity ids are globally unique and each entity already carries its
language. The simulator (`GET /bilinguals/simulator/{entityMatch}`) hardcoded
EN-left / RU-right: column headers said "English"/"Russian", reveal
checkboxes and read-credit were glued to the EN side, and the Open/Ask
actions sat on the RU cell regardless of what languages the match actually
held.

Decisions:

- **One side rule, shared.** `EntityMatch::readingSideFor(?int
  $nativeLanguageId)` returns the side a reading surface opens as the
  learning text: the side in the user's **Native language** becomes the
  translation and the other side is read; when neither (or both) sides are
  native, the work's **original** side is read; with no original side, the
  canonical A-side. This is exactly the tiebreak chain ADR 0036 gave the
  alignment card's `reader_target`, and `LibraryController::readerTarget()`
  is refactored onto the shared method so the card's Read button and the
  page it opens always agree.
- **The reader route drops `{lang}`**: `GET /reader/{entityId}`. The URL
  entity only *anchors* the match (and serves as the fallback single-language
  text); the side rule picks which language is read and which is shown as
  translation, so the segment could contradict the actual columns. Following
  the ADR 0036 amendment precedent, the old two-segment shape is deleted
  outright (404, guarded in tests) rather than redirected.
- **The URL is not the toggle.** Both surfaces get a language radio in the
  header that swaps the sides client-side around the server-computed default
  (`primaryLang`/`translationLang` props on the reader; a
  `defaultLearningSide` prop plus per-side `languages` on the simulator).
  Swapping is pure display state: rows and word maps exchange columns, and
  the word-explain payloads keep travelling with the *actual* match side.
- **Simulator semantics are positional, not linguistic.** Columns are
  renamed to the display roles *target* (left, hidden until revealed,
  read-credited) and *base* (right, carries Open/Ask and pairs with the
  workplace) — `hide_target/hide_base`, `check_target/check_base`,
  `all_target/all_base`. Whatever languages play those roles, the learning
  mechanics stay on the learning column. The hardcoded "English"/"Russian"
  headers become the sides' actual language names, and the toolbar's
  `en ↔ ru` badge shows the match's real codes.
- **The default question becomes a template.** `DEFAULT_QUESTION` carries a
  `:base` placeholder; the client substitutes the base side's language name
  and regenerates on toggle. A saved custom question still ships verbatim and
  is never rewritten; a saved copy of the pre-template default (the hardcoded
  "Compare Russian original…" text) counts as *not* customized so existing
  users keep tracking the toggle. Only actual edits to the question textarea
  persist (`question` saves `null` while untouched), which is what keeps the
  template from freezing on first autosave.
- **The flip is Working state, per device.** The reader persists it under
  `ext_app.reader.side-flip.v1` keyed by `positionKey`; the simulator stores
  `flipped` in the existing per-match position store. Nothing server-side
  changes ownership — the default always recomputes from the user's native
  language.

Performance work shipped alongside (same surface, same complaint): reader
rows render with `content-visibility: auto`, `ReaderRow` and `WordText` are
memoized with stable prop identities, and the per-row hover `setState` is
replaced by the CSS `:hover` rules that already existed — scrolling had been
re-rendering every token span of every row crossed.

Alternatives considered: keeping `{lang}` as a redirect was rejected (the
segment never disambiguated anything — ids are unique — and the house style
from ADR 0036 is 404-for-dead-shapes); putting the swap behind a URL param
was rejected because the flip is device-local view state, not a shareable
address; server-side persistence of the flip was rejected as it is per-device
Working state by the ADR 0024 split.
