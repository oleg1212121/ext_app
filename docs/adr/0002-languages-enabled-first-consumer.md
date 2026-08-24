# Language::enabled() is wired into the front-end entities surface

The new `/entities` front-end surface (entity picker, per-language entity lists,
entity create form, entity detail) is the first production consumer of the
`Language` model and its `enabled()` scope. ADR 0001 shipped the `languages`
table as a standalone registry "until something references it"; this surface is
that reference.

The picker at `/entities` queries `Language::enabled()` to build one link per
enabled language, and every `/entities/{lang}/*` route validates its `{lang}`
segment against the set of enabled language codes (404 otherwise) instead of the
hardcoded `en|ru` regex other surfaces still use. Real language objects
(`code`, `name`, `native_name`) are passed to the frontend, not string literals.

The decision reversed only the *standalone* stance of ADR 0001, not its
*no-outbound-FK* stance: the `en_entities` / `ru_entities` tables still have no
`language_id` column, and the per-language model split (`EnEntity` / `RuEntity`)
remains the bridge between a `Language` code and its entities. Other surfaces
(`/reader-react`, `/crossword-react`) may migrate to `Language::enabled()`
incrementally; they are not forced to.

Consequences worth flagging to future readers: enabling a `Language` code that
has no corresponding entity model (`en`/`ru`) will surface a link that 404s on
the list page, because the entity tables are still split per known language.
