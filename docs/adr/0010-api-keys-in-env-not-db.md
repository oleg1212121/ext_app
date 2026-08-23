# API keys stay in .env, not the ai_providers table

> **Status:** superseded by ADR-0012 — per-user keys are now stored (encrypted) in `user_api_keys`, and the `.env` System key is reserved for non-user paths (sync/CLI).

API keys remain in `.env` (read via `config/services.php`), and the
`ai_providers` table stores no secrets. The provider classes read their key
with `config('services.<provider>.key')` exactly as before.

Rejected alternative: storing keys on `ai_providers` so admins could manage them
through the Filament UI. Storing secrets in Postgres exposes them in database
dumps, renders them in Filament HTML, and makes them readable by anyone with
admin access — and the table has no encryption layer. The admin `AiProvider`
form therefore edits only non-secret fields (`key`, `name`, `is_enabled`,
`description`); key rotation stays a deploy. Should admin-managed keys ever be
wanted, that is a separate, encryption-backed (`encrypt:` cast) feature.
