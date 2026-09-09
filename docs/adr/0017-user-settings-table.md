# User settings live in a dedicated table, not on `users`

User preferences are stored in a dedicated `user_settings` table (one row per
user) rather than as columns on `users`. Today it holds a single column —
`native_language_id` (nullable FK to `languages`, English default) — but the
table is the home for future per-user preferences (UI language, theme, target
language, …), keeping `users` focused on identity/auth and avoiding column
proliferation. Chosen at registration, changeable on the profile page, and
admin-managed in Filament.

**Status**: accepted

## Considered Options

- **Dedicated `user_settings` table (chosen).** One row per user, seeded at
  registration and backfilled to English for existing users. New preferences
  become columns on this table. Keeps `users` stable; the profile page already
  acts as the settings surface, so a row naturally belongs beside it.
- **Columns on `users`.** `native_language_id` etc. directly on the auth table.
  Rejected: muddies the identity/auth boundary with profile concerns, and every
  future preference keeps widening the auth table.

## Consequences

- `user_settings` is created at registration and for existing users (backfill to
  `en`) — a settings row always exists for every user.
- Native language is nullable at the schema level but effectively defaults to
  English via the registration flow and factory.
- Deleting a user cascades to their settings row; deleting a language nulls the
  reference (never cascades to deleting a user's settings).