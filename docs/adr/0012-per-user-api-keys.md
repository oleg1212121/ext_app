# Per-user API keys replace the env-only key model

API keys are now stored per user in the `user_api_keys` table (encrypted via the
`encrypt:` cast). Each user supplies their own key for a provider; every
user-facing AI request uses that user's key — there is no fallback to the
System key. The admin's `.env` key (the **System key**) is retained only for
non-user paths: model sync, CLI, and admin tooling. This supersedes
[ADR 0010](./0010-api-keys-in-env-not-db.md), which had decided all keys stay
in `.env`.

**Status**: accepted — supersedes ADR 0010

## Considered Options

- **Per-user keys, no fallback (chosen).** Users store their own keys; requests
  use the user's key exclusively. The System key remains for sync/CLI only.
- **Per-user keys with System-key fallback.** If a user had no key, fall back to
  the admin's `.env` key. Rejected: the user explicitly did not want their
  account charged for others' usage, and the simulator already hides providers a
  user hasn't keyed (so fallback would only matter for tampering/deleted-key
  edge cases, which a 403 handles cleanly).
- **Admin-managed keys in `ai_providers`.** Rejected when originally considered
  in ADR 0010 (secrets in Postgres dumps / Filament HTML). Per-user keys inherit
  the same risk, so they use the `encrypt:` cast that ADR 0010 itself mandated
  for any future DB-stored keys.

## Consequences

- `AIModelResolver::getGroupedModels()` is now user-scoped: it lists only
  providers the authenticated user has a User key for, sorted by price.
- `AIModelResolver::ask()` injects the user's key onto a cloned provider
  instance (`AiProvider::withApiKey()`) and returns 403 when the user has no key
  for the requested provider.
- The `isConfigured()` method (env-key probe) is kept but no longer gates
  visibility; its meaning is now "a System key exists", not "available".
- `user_api_keys.api_key` is encrypted at rest.
