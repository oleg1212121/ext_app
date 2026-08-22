# DB-backed AI model catalog replaces hardcoded lists

The OpenRouter model catalog moved from a hardcoded PHP array
(`OpenRouter::$models`) to a database table (`ai_models`) populated by an
`openrouter:sync-models` artisan command that fetches `GET /api/v1/models`.

**Context.** The hardcoded array was hand-maintained (~44 models with pricing
baked into display strings) while the API exposes 400+ models that change
frequently. Keeping it in sync was error-prone and gave no admin control over
which models the simulator exposed.

**Decision.** Store models in `ai_models` (one row per model, `external_id`
unique with `provider`, `is_enabled` flag), fetch them with the Laravel `Http`
facade in `OpenRouterModelSync`, and surface enable/disable control in a
Filament resource. `OpenRouter::getModels()` reads the table in its constructor
and reconstructs the legacy `key => "Name ($X.XX/$Y.YY)"` display format.

**Why this shape.** The `provider` column and surrogate key make the table
reusable for other providers later without a schema change, while keeping the
existing `provider:model` addressing and `AIModelResolver` entry point intact.
Upsert + delete-missing keeps the catalog faithful to the API; the `is_enabled`
flag (default false after a sync) gives admins curatorial control the hardcoded
array never had.
