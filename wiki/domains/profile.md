---
type: Feature
title: Profile
description: The /profile account surface — tabbed Account / Preferences / Popups / Anki / AI Models / Danger zone, per-user language and AI-model preferences, popup block visibility with a live preview, Anki connection settings with template sync + sample card, provider API key management, account deletion.
tags: [profile, settings, popups, anki, ai, inertia, react]
status: stable
generated: { by: agent:zcode, at: 2026-10-15T18:20:00+03:00 }
verified: { by: agent:zcode, at: 2026-10-15T18:20:00+03:00 }
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
    title: ankiTemplate.js (field-key → Anki-field-name map, deterministic popup-styled Front/Back/CSS generator, structured buildNoteFields)
  - id: anki-sync
    resource: laravel/resources/js/lib/ankiSync.js
    title: ankiSync.js (ensure deck/note-type/template, memoized per session; duplicate-checked note send)
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
  - id: adr-anki
    resource: docs/adr/0077-browser-side-ankiconnect.md
    title: ADR 0077 — Anki export is browser-side; values-always-sent + hide-class template semantics
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
| Anki | Anki connection: AnkiConnect URL · port · deck name · Test connection; card content: 13 field toggles in two semantics-labeled groups + generated-template preview (Copy HTML/CSS); Send to Anki: template sync + sample card | autosaved `PATCH /ui-settings` (auth-only — section `anki`); AnkiConnect itself is called from the browser only |
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
page as the `popupVisibility` Inertia prop. The tab's module-local
`POPUP_SECTIONS` constant (display-only grouping in `POPUP_GROUPS`
notwithstanding) hand-mirrors `PopupVisibility::SECTIONS` — the same
keys in the same order, unexported — a lockstep pinned by
`tests/Unit/PopupSectionsMirrorTest.php`, so a section added, renamed
or re-ordered on either side fails until both agree.

# Anki tab

Per-user **Anki settings** (the Anki-export feature, ADR 0077, spec
`.scratch/anki-connection/spec.md`; the flow's front door is
[Anki Export](/domains/anki-export.md)): connection settings and card content,
autosaved together through `useUiSettingsAutosave('anki', …)` into
`ui_settings.anki` — one state object, because the endpoint replaces the
section wholesale (a fields-only save would wipe the connection and vice
versa). The section is fully separate from `popup`/`simulator`/`reader`; the
server resolves saved values over the defaults in
`App\Support\AnkiSettings::for()` (URL default `http://127.0.0.1`, port
`8765`, deck `ExtApp`, all thirteen fields on) and shares them to every page as
the `ankiSettings` Inertia prop.

**Connection** — AnkiConnect endpoint (URL + port) and target deck name,
plus a **Test connection** button that calls AnkiConnect's `version` action
**directly from the browser** — the browser-side client module
`resources/js/lib/ankiConnect.js` (thin wrappers over `invoke` for
version / requestPermission and the deck/model/template/note actions) is
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

**Card content** — thirteen field toggles in two display groups, each
group's description stating its toggles' semantics (ADR 0077 addendum v2):
**Template fields** (Word, Familiarity, Frequency, Word family, Part of
speech, Tags, Language — the Back's header/footer scalars in the card's
visual order; "re-syncing the template restyles ALL cards") and
**Dictionary body** (the `body` master — "Body (whole dictionary)" — plus
Translations, Transcription, Definitions, Examples, Etymology in the
popup's per-entry order; "shapes newly sent cards only"). Absent key = on,
the Popups tab's convention. `AnkiSettings::FIELDS` is the canonical
server-side list, whose
one JS mirror `resources/js/lib/ankiTemplate.js` also owns the field-key →
Anki-field-name mapping (Word, Transcription, PartOfSpeech, …, Body — the
note type's fixed order), the "ExtApp Vocabulary" note-type name and the
connection defaults — a lockstep pinned by
`tests/Unit/AnkiSettingsMirrorTest.php`.
`generateCardTemplate(fields)` deterministically emits the Front/Back HTML +
CSS in the reading popup's image: the **Front is the word alone** (never
hidden — the recognition cue and the duplicate-search key), the **Back**
is the popup's three parts — the word title plus the Familiarity /
Frequency / form-of / part-of-speech meta lines in an `ef-header`, the
`{{#Body}}` slot (dropped whole when the Body value is empty), a muted
Tags · Language footer. The scalar toggles bake the `ef-hidden` hide class
into their wrappers (`<div class="ef-field ef-<key>[ ef-hidden]">` —
values always carry every field, so re-syncing the template restyles all
existing cards' scalar fields instead of dropping data); the body toggles
are not template concerns. Meta lines whose value carries
literal decoration sit inside Anki-native `{{#Field}}…{{/Field}}`
conditionals, so an empty field drops the whole line; the card surface is
the popup's palette (vellum/ink/hairline/verdigris, light + Anki night
mode), 560px study width centered, serif headword, local font stacks only.
`buildNoteFields()` builds the full 13-field note from a word payload —
what the sample card and the word popup put on the wire (the flat
Translations field narrows to the user's native language when it matches,
falling back to all; the Body's per-entry translations are not narrowed —
the popup renders them raw, native-first sorted by the backend) — with the
popup's dictionary structure baked into the values: per-entry "WORD — pos"
labels for 2+-entry words in the flat fields, counted `<details>`
summaries for Definitions/Examples/Etymology, the translations preview
behind a "+N more…" expander, " · "-joined transcriptions, and the
localized "form of" prefix inside the WordFamily value. The 13th **Body**
value is the whole dictionary rendered per entry in the popup's order —
"WORD — pos" label on every entry (single-entry words included, exactly
the popup's pane), the translations line (cap 8 + expander, or the calm
"No translations yet" placeholder), bracketed transcriptions, the counted
collapsed sections ("No definitions yet." when empty), hairline
separators. A `fields` option (the resolved Anki settings map, passed by
both senders — the tab's `settings.fields`, the popup's shared
`ankiSettings` prop) shapes that Body at send time: the body section
toggles plus the `body` master drop their sections entire, placeholder
included — newly sent cards only. A `labels` option carries the seven
UI-language strings (summaries, expander, "form of", the two calm
placeholders) — the tab passes
its locale's via `useI18n` (both senders share `ankiTemplate.js`'s
`uiLabels(t)` builder over the app's `word.*` strings), so the preview
shows the real wording; the wording is frozen into the note at send time.

**Template preview** — the tab renders the exact generated artifacts in a
sandboxed iframe (`srcDoc` = generated CSS + HTML with the sample values
substituted for the `{{Field}}` placeholders and the
`{{#Field}}…{{/Field}}` conditionals resolved the way Anki resolves them —
an empty field drops its whole meta line — i.e. exactly what Anki renders
at review time: the popup-styled card) as Front/Back tabs, with read-only
Copy HTML (the selected face) and Copy CSS buttons. The note comes from the
shared sample fixture `Pages/Profile/sampleWordData.js` — the same
"melted → melt" fixture the Popups tab's preview renders, so both previews
have one source.

**Send to Anki** — the tab's write path, orchestrated by
`resources/js/lib/ankiSync.js` on top of `ankiConnect.js`. **Create /
update template in Anki** runs a fresh ensure pass: deck create-if-missing,
the "ExtApp Vocabulary" note type create-if-missing (all 13 fields in fixed
order, `Body` last, + the "Recognition" card generated from the current
scalar toggles) or —
when it already exists — field-set verification followed by a
templates + CSS push, which is what re-applies toggled-off `ef-hidden`
classes to all existing cards' scalar fields (the Body values are send-time
and untouched by it). A same-name note type whose field set (or
whose card naming, which AnkiConnect would silently skip) doesn't match the
app-owned thirteen raises `AnkiNoteTypeMismatchError`, surfaced with the
teaching copy — expected vs found plus the one-time fix: in Anki, Tools →
Manage Note Types, delete "ExtApp Vocabulary" (its notes are deleted with
it), then sync again and re-send the words; a pre-v2 twelve-field install
hits exactly this by design, since AnkiConnect cannot add a field to an
existing note type — it is never rewritten. **Send sample card** adds
a note built from the shared fixture with a find-before-add duplicate check
scoped to deck + Word + Language (`sendAnkiNote`): a duplicate shows
"Already in Anki" and nothing is re-added (`allowDuplicate` stays false as
a race net), and no existing note is ever modified. The ensure is memoized
once per page session per endpoint+deck, so subsequent sends skip straight
to the duplicate check; changing the endpoint or deck misses the memo
naturally (field toggles don't invalidate it — values always carry every
field). Inline status lines report sync / sent / duplicate outcomes;
failures render by the error's kind — a note-type mismatch the expected-vs-
found warning, an Anki-side `AnkiConnectError` (kind `anki`, e.g. a
addNote duplicate race) the inline "Anki error: …" panel, and everything
unreachable the same connection-hint panel as the test probe, whose
**Request permission** step grants the origin approval and then replays
the blocked action in one click.

The word popup's per-word **Save to Anki** send (see
[Interactive Words](/domains/interactive-words.md)) reuses this exact
path — the same `sendAnkiNote` and the same memoized ensure (module state
shared with the tab, so a tab sync and a popup send in one page session
don't redo each other's work) — with the note built from the popup's live
payload instead of the fixture and the popup's own UI-language label
strings passed, so a card sent from the reader carries the sender's
locale. The tab's preview/sample card narrow
Translations with the native language **code** from the shared
`auth.user.native_language` prop (an early version passed the numeric
`native_language_id`, which never matched a `language_code` and silently
kept all translations).

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
