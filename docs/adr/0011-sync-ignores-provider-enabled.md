# Sync ignores the provider enabled flag by design

`ai:sync-models` and `SyncAiModelsJob` iterate the class-map registry and never
consult `ai_providers.is_enabled`. Sync is a *catalog mirror*: its job is to keep
`ai_models` faithful to each provider's upstream model list, regardless of
whether the provider is enabled in the admin panel.

Availability is enforced separately — in `App\Classes\AIModelResolver`, which
excludes a provider from the simulator picker when it is not *available* (i.e.
configured AND enabled). A future reader might assume "disabled provider = don't
sync", but that would let an upstream catalog drift undetected for a provider
that is merely paused in the UI. The invariant is locked by a unit test that
syncs a provider whose `ai_providers.is_enabled = false` and asserts its models
are still written.
