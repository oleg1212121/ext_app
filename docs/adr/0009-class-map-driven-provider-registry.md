# Provider registry is class-map-driven, not DB-driven

The set of AI providers is hardcoded in `App\Classes\AIModelResolver::$providerClasses`
and `App\Services\AiModelSyncRegistry::$syncerClasses`. The new `ai_providers`
table is an *enable-flag overlay* — it stores `key`, `name`, `is_enabled`, and
`description` for each provider, but does **not** store the provider class FQCN.

Rejected alternative: a DB-driven registry that persisted the provider/syncer
class name and instantiated `new $row->provider_class`. Because a provider's
`askForContext()` implementation lives in code (and a new provider always
requires writing that class), a DB-driven registry would add a failure mode —
a row pointing at a deleted or renamed class → boot error or silent skip —
without enabling any genuine runtime "create provider" capability. The class
map stays the single source of truth for *which providers exist*; the DB is the
single source of truth for *which are turned on*.
