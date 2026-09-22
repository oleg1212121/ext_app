# 0035 - Per-user AI model preferences, resolved server-side

The simulator's model picker was page-local state: it autosaved into
`ui_settings.simulator.model` as a `provider:model` string, and the same
client value was echoed back in every `/ai/question` and `/ai/word-explain`
payload. That gave answers and word explanations a single shared model with
no user-visible way to split them, and it made the request contract trust a
client-supplied model string. Decision: model choice becomes a **per-user
preference stored on `user_settings` and resolved server-side**. Two nullable
FK columns, `ai_model_id` and `explanation_model_id`, reference `ai_models`
rows; the profile page's new AI Models tab writes them; the simulator page
no longer carries a picker (it shows the effective model's label as a link to
the profile), and the AI endpoints take `model` out of their request
contracts entirely, resolving the answer model via
`AIModelResolver::resolveAnswerModel()` and the explanation model via
`resolveExplanationModel()`.

Two semantics are deliberate and surprising without this record:

- **Unset is not fallback.** A user with API keys but no chosen answer model
  is *blocked*: the UI offers "choose a model" and the endpoints answer with
  guidance instead of silently spending tokens on a model the user never
  picked. A *stored but unavailable* pick (model deleted by
  `ai:sync-models`, disabled by an admin, or its provider key removed),
  however, falls back silently to the cheapest available model — matching
  how the old picker treated a stale saved value. An unset explanation model
  follows the effective answer model.
- **FK with `nullOnDelete`, not a `provider:model` string.** The sync
  hard-deletes `ai_models` rows that vanish from a provider's catalog
  (`AiModelSync` mirrors the upstream catalog, deletions included), so a
  string could dangle invisibly and an FK needs to survive deletion by
  nulling — the same convention as `user_settings.native_language_id`. The
  one-time migration backfilled `ai_model_id` from the legacy
  `ui_settings.simulator.model` value and stripped that key.

A normalized `provider:model` string column was rejected because every cache
key and provider call would need parsing on read, and id-based FKs give the
deletion semantics for free. Keeping the client-supplied `model` field was
rejected once the profile became the single editor: one source of truth beats
request-payload overrides, and the word-popup's client cache now
discriminates on the resolved model id instead.

## Consequences

- `/ai/question`, `/ai/question/stream` and `/ai/word-explain` no longer
  accept `model`; validation and client payloads dropped the field. Old
  clients still work (the field is ignored), they just can't steer it.
- `ui_settings.simulator.model` is dead: backfilled, stripped, and removed
  from `UpdateUiSettingsRequest` and the simulator autosave.
- The Reader gained the explain tab for free (the model lives server-side):
  bilingual rows post `meaning_match_id`/`side`/`sentence_index`,
  single-language rows post `entity_sentence_id`. Explanations follow the
  "not your native language" rule everywhere, matching the highlight rule.
- Changing models never invalidates server state (there is none — the
  explanation cache is client-side and keyed with the model id).
