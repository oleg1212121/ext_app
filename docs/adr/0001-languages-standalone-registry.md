# Languages table is a standalone admin registry

The `languages` table and `Language` model are an admin-managed lookup registry
created to support a CRUD UI on the Filament `/admin` panel, with an `is_enabled`
toggle and seed rows for `en` and `ru`. The decision was to ship it as a
*standalone* registry: it does not yet wire into any other table or code path,
and the ~100+ hardcoded `'en'`/`'ru'` string literals throughout the app remain
untouched.

This was a deliberate trade-off against making languages dynamic now (other
tables would gain a `language_id` FK and literals would be replaced by lookups).
Dynamic support was deferred because it is a large refactor independent of the
immediate ask, and a registry is safe and reversible to extend later.

Consequence worth flagging to future readers: the `is_enabled` flag is a stored
value only — nothing in the application enforces it yet. The table currently
stands alone until something references it.
