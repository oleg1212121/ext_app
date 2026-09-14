# UI strings live in the database behind a group translation loader

Interface text must be editable in the Filament admin panel and take effect on
the next request with no deploy, and adding an interface language must remain a
row insert, not DDL (the ADR 0018 principle). UI strings are therefore stored in
`ui_string_keys` (dotted key, group = first segment) + `ui_strings` (one value
per interface-enabled language), and served to Laravel's translator through a
`FileLoader` subclass that overlays the DB rows onto each string group,
cache-backed per locale with caches flushed by model observers. The frontend
consumes the same data as a flat Inertia shared prop with a tiny `t()` helper.

**Status**: accepted

## Considered Options

- **DB tables + group translation loader (chosen).** Filament-editable,
  immediately effective, new languages need no schema change.
- **`lang/` files.** Rejected: editing them requires a deploy, contradicting
  the admin-panel requirement.
- **laravel-lang package for framework lines.** Rejected for v1: introduces a
  second source of localization truth outside the database (the
  `validation.*`/`auth.*` groups can be seeded into the same tables later with
  no architecture change).
- **Wide table (`text_en`, `text_ru`, …).** Rejected: adding a language becomes
  an `ALTER TABLE`, violating "INSERT, never DDL".

## Consequences

- Missing strings fall back to English silently (the EN map is merged first,
  locale rows overwrite it); unknown keys render as the key itself.
- Filament saves flush per-locale caches via observers, so changes apply on the
  next request.
- Framework-generated messages (validation errors, built-in notifications) stay
  English in v1.
- The word "translation" stays reserved for the Dictionary domain; these are
  **UI strings**.
