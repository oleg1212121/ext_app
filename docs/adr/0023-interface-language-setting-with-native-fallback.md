# Interface language is a user setting with native-language fallback

Each user picks the language the interface renders in via a nullable
`user_settings.interface_language_id` (ADR 0017's table, extending its purpose).
When null it follows the user's native language, so "the UI is in my native
language" remains the default behavior with zero data migration; resolution is
interface language → native language → English, considering only
interface-enabled languages (`languages.is_interface_enabled`), so a catalog
language with no translated UI strings can never serve as the interface.
Guests always see English in v1.

**Status**: accepted

## Considered Options

- **Dedicated setting with native fallback (chosen).** A French-native user
  learning English can keep an English interface; the default reproduces the
  native-language behavior.
- **Locale = native language.** Rejected: conflates "language I speak" with
  "language I want the chrome in"; adding the separate setting later would cost
  another migration and UI pass anyway.
- **Browser Accept-Language detection for guests.** Deferred: adds header
  parsing and nondeterminism for the least-used pages; can be added later with
  no schema change.
