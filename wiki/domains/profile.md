---
type: Feature
title: Profile
description: The /profile account surface — tabbed Account / Preferences / Popups / Anki / AI Models / Danger zone, per-user language and AI-model preferences, popup block visibility with a live preview, Anki connection settings, provider API key management, account deletion.
tags: [profile, settings, popups, anki, ai, inertia, react]
status: stable
generated: { by: agent:zcode, at: 2026-10-14T22:10:00+03:00 }
verified: { by: agent:zcode, at: 2026-10-14T22:10:00+03:00 }
sources:
  - id: controller
    resource: laravel/app/Http/Controllers/ProfileController.php
    title: ProfileController (edit/update/settings/ai-models/api-keys/destroy)
  - id: page
    resource: laravel/resources/js/Pages/Profile/Edit.jsx
    title: Tabbed page shell (Edit.jsx)
  - id: popups-tab
    resource: laravel/resources/js/Pages/Profile/PopupsSettings.jsx
    title: PopupsSettings tab section (checkboxes + live preview)
  - id: popup-visibility
    resource: laravel/app/Support/PopupVisibility.php
    title: PopupVisibility (saved opt-outs over all-visible defaults)
  - id: anki-tab
    resource: laravel/resources/js/Pages/Profile/AnkiSettings.jsx
    title: AnkiSettings tab section (connection settings + test connection)
  - id: anki-settings
    resource: laravel/app/Support/AnkiSettings.php
    title: AnkiSettings (saved connection + field toggles resolved over defaults)
  - id: anki-template
    resource: laravel/resources/js/lib/ankiTemplate.js
    title: ankiTemplate.js (field-key → Anki-field-name map, deterministic Front/Back/CSS generator)
  - id: sample-word-data
    resource: laravel/resources/js/Pages/Profile/sampleWordData.js
    title: sampleWordData.js (sample fixture shared by the Popups and Anki previews)
  - id: ai-models
    resource: laravel/resources/js/Pages/Profile/AiModels.jsx
    title: AiModels tab section
  - id: prefs-request
    resource: laravel/app/Http/Requests/UpdateAiModelPreferencesRequest.php
    title: UpdateAiModelPreferencesRequest
  - id: settings-request
    resource: laravel/app/Http/Requests/UpdateUserSettingsRequest.php
    title: UpdateUserSettingsRequest
  - id: adr-preferences
    resource: docs/adr/0035-per-user-ai-model-preferences.md
    title: ADR 0035 — Per-user AI model preferences, resolved server-side
  - id: adr-popup-visibility
    resource: docs/adr/0046-per-user-popup-section-visibility.md
    title: ADR 0046 — Per-user popup section visibility
  - id: routes
    resource: laravel/routes/web.php
    title: Routes (auth group)
---

# What it does

The single account surface, reachable from the NavBar user dropdown at
`/profile` for every authenticated user (approved or not). The page is a
**tab bar of six sections** over the legacy vellum/vermilion palette, each
section rendered as a bordered card; the active tab is reflected in the URL
as `?tab=` (deep-linkable, `history.replaceState` on switch), and only the
active tab's forms stay mounted (Crossword-style unmount panels).

The page frame spans **nearly the full window on large screens** — soft cap
`max-w-[1800px]` (swapped for the old `max-w-3xl` column), side padding
`px-4 sm:px-6 lg:px-10` kept. Every tab's cards stay capped at the **panel
level** (`max-w-3xl` on the `TabPanel` wrapper, via its `width` prop —
`'panel'` by default, `'wide'` lifts the cap), so the widened frame does not
stretch the regular forms, whose content remains capped inside each card
(`max-w-xl` on the `Card` inner wrapper). **The Popups and Anki tabs are the
exceptions**: their panels and cards pass `width="wide"`, lifting both caps
so the tabs' layouts span the full profile frame. The two layers share
one width variant, `profileWidthClass(width, cappedClass)` in `ui.jsx`.

| Tab | Sections | Endpoint |
|-----|----------|----------|
| Account | Profile Information (name/email) · Update Password | `PATCH /profile` (`profile.update`), `PUT /password` (`password.update`, auth routes) |
| Preferences | Native + interface language | `PATCH /profile/settings` (`profile.settings.update`) |
| Popups | Word popup block visibility (checkbox groups) + live preview | autosaved `PATCH /ui-settings` (`ui-settings.update`, auth-only — section `popup`) |
| Anki | Anki connection: AnkiConnect URL · port · deck name · Test connection; card content: 12 field toggles + generated-template preview (Copy HTML/CSS) | autosaved `PATCH /ui-settings` (auth-only — section `anki`); AnkiConnect itself is called from the browser only |
| AI Models | Answer + explanation model selects · AI Provider API Keys | `PATCH /profile/ai-models` (`profile.ai-models.update`), `POST /profile/api-keys` + `DELETE /profile/api-keys/{providerKey}` |
| Danger zone | Delete Account (password modal) | `DELETE /profile` (`profile.destroy`) |

Every form keeps its own Inertia `useForm` state. Only the Preferences and
AI-model saves redirect back to their tab
(`Redirect::route('profile.edit', ['tab' => ...])`); the Account and both
API-key forms redirect to plain `profile.edit`, which resolves the active tab
solely from `?tab=` — so saving or removing an API key lands on the Account
tab.

# Popups tab

Per-user **Popup preferences** (ADR 0046): which blocks the Word popup
renders — familiarity line, progress buttons, form-of line, word family
sections, frequency line, transcriptions, definitions, translations,
examples, etymology, Explanation tab. Eleven checkboxes in display groups
(Knowledge / Word family / Dictionary details / Tabs & info), each bound to
one key of `ui_settings.popup`; an absent key means visible, so a fresh user
matches the classic popup exactly. Saving is automatic per flick through
`useUiSettingsAutosave('popup', …)` — debounced 800 ms, no submit button.

**Layout — the wide tab shape, shared with Anki.** At `lg` and up the tab
is a two-column grid (`lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]`,
`lg:gap-10`): the checkbox groups take the left ~⅓, the live preview the
right ~⅔; below `lg` it stacks checkboxes-above-preview (the original
order). The Popups and Anki tabs are the two whose panels *and* cards pass
`width="wide"`, so their grids span the full profile frame instead of the
regular `max-w-3xl` panel / `max-w-xl` card caps — at 576px a ⅓/⅔ split
would crush the preview to ~345px.

Right of the checkbox groups at `lg`+ (stacked below them on smaller
screens), a **live preview** renders the popup's real body — the exported
`PopupContent` from `Components/WordPopup.jsx` with a fixture-style
"melted → melt" sample family — bound to the current checkbox state, so the
preview cannot drift from the real popup; in preview mode progress buttons
are inert and the Explanation tab shows a canned answer (the activation
auto-request is suppressed — no tokens spent).
The preview wrapper keeps a realistic inline `width: min(560px, 100%)` at
the default popup font size and carries the same `word-popup` structural
class as the real portal wrapper (`WordPopup.jsx`) — minus the portal's
`absolute z-50` positioning, which stays portal-only — so user styles and
tests can target both identically. The server resolves saved state over
defaults in `App\Support\PopupVisibility::for()` and shares it to every
page as the `popupVisibility` Inertia prop.

# Anki tab

Per-user **Anki settings** (the Anki-export feature, spec
`.scratch/anki-connection/spec.md`): connection settings and card content,
autosaved together through `useUiSettingsAutosave('anki', …)` into
`ui_settings.anki` — one state object, because the endpoint replaces the
section wholesale (a fields-only save would wipe the connection and vice
versa). The section is fully separate from `popup`/`simulator`/`reader`; the
server resolves saved values over the defaults in
`App\Support\AnkiSettings::for()` (URL default `http://127.0.0.1`, port
`8765`, deck `ExtApp`, all twelve fields on) and shares them to every page as
the `ankiSettings` Inertia prop.

**Connection** — AnkiConnect endpoint (URL + port) and target deck name,
plus a **Test connection** button that calls AnkiConnect's `version` action
**directly from the browser** — the browser-side client module
`resources/js/lib/ankiConnect.js` (invoke / version / requestPermission) is
the only AnkiConnect caller in the codebase; Laravel never calls Anki, since
dev runs in Docker/WSL2 and prod is remote, so only the user's own browser
can reach their desktop Anki. Success reports the AnkiConnect version;
failure renders actionable fix instructions — check Anki is running with the
AnkiConnect add-on, click **Request permission** (the `requestPermission`
action makes Anki show its approval popup for this origin), or add the
app's origin (shown live from `window.location.origin`) to
`webCorsOriginList` in Anki's AnkiConnect config and restart Anki. The
module normalizes both failure shapes — fetch rejection (CORS/refused) and
AnkiConnect's error string inside HTTP 200 — into `AnkiConnectError`.

**Card content** — twelve field toggles (Word, Transcription, Part of
speech, Frequency, Definitions, Translations, Examples, Etymology, Word
family, Tags, Familiarity, Language) in three display groups (Word details /
Dictionary details / Tags & progress); absent key = on, the Popups tab's
convention. `AnkiSettings::FIELDS` is the canonical server-side list, whose
one JS mirror `resources/js/lib/ankiTemplate.js` also owns the field-key →
Anki-field-name mapping (Word, Transcription, PartOfSpeech, … — the note
type's fixed order) and the "ExtApp Vocabulary" note-type name.
`generateCardTemplate(fields)` deterministically emits the Front/Back HTML +
CSS: every field wrapped `<div class="ef-field ef-<key>">{{Field}}</div>`,
a toggled-off field additionally carrying the `ef-hidden` class (values
always carry every field — hide-class semantics, so re-syncing the template
restyles existing cards instead of dropping data); Front shows Word +
Transcription, the Back all twelve; the CSS defines the `.card` base plus
`.ef-hidden { display: none }`. `buildNoteFields()` builds the full
12-field note from a word payload (what the send-to-Anki tickets put on the
wire; Translations narrow to the user's native language when it matches,
falling back to all).

**Template preview** — the tab renders the exact generated artifacts in a
sandboxed iframe (`srcDoc` = generated CSS + HTML with the sample values
substituted for the `{{Field}}` placeholders, i.e. what Anki renders at
review time) as Front/Back tabs, with read-only Copy HTML (the selected
face) and Copy CSS buttons. The note comes from the shared sample fixture
`Pages/Profile/sampleWordData.js` — the same "melted → melt" fixture the
Popups tab's preview renders, so both previews have one source.

# AI Models tab

* Both selects list the models **available to this user** — provider
  admin-enabled AND holding the user's **User key** — grouped by provider,
  cheapest first, valued by `ai_models.id` (`AIModelResolver::
  getGroupedModelChoices()`); option labels reuse `AiModel::displayLabel()`.
* **Answer model** (`user_settings.ai_model_id`) powers AI answers;
  **explanation model** (`explanation_model_id`) powers word popups, and an
  unset explanation model **follows the answer model**. Semantics of unset
  vs unavailable are ADR 0035 — the page shows raw stored ids (possibly
  stale); availability is resolved at request time, not at save time.
* Without any stored API key (`apiKeyProviders.some(p => p.has_key)` is
  false) the selects render disabled with a hint pointing at the API keys
  card below in the same tab.
* API keys are managed per enabled provider as two-state rows (password
  input + Save, or masked-key badge + remove; replace = remove-then-re-add;
  the full key is never echoed) — see
  [AI Providers](/domains/ai-providers.md).

# Persistence

All preferences live on the one-row-per-user `user_settings`
(`App\Models\UserSettings`): `native_language_id` /
`interface_language_id` (typed FKs to `languages`), `ai_model_id` /
`explanation_model_id` (typed FKs to `ai_models`, `nullOnDelete` — the model
sync hard-deletes catalog rows, ADR 0035), and the `ui_settings` JSONB blob
for per-surface UI state (simulator/reader sections via `PATCH /ui-settings`
from the reading surfaces, the popup visibility and Anki connection
sections via the same endpoint from this page — `UpdateUiSettingsRequest`
carries the rules for all four sections; unknown keys are stripped by
`validated()`).
The model preferences were backfilled from the legacy
`ui_settings.simulator.model` string, which no longer exists.

# Language & validation

Validation is FormRequest-based: `UpdateUserSettingsRequest`
(enabled/interface-enabled `Rule::exists` on languages),
`UpdateAiModelPreferencesRequest` (nullable `Rule::exists('ai_models')->where('is_enabled')`),
`StoreApiKeyRequest`, `ProfileUpdateRequest`. Locale resolution from the
language settings is described in
[Localization](/domains/localization.md); delete-account guards the
last-admin invariant on the `User` model.
