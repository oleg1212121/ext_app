---
type: Feature
title: Profile
description: The /profile account surface — tabbed Account / Preferences / Popups / Anki / AI Models / Danger zone, per-user language and AI-model preferences, popup block visibility with a live preview, per-language Anki settings (a language selector over deck + card content) with template sync + sample card, provider API key management, account deletion.
tags: [profile, settings, popups, anki, ai, inertia, react]
status: stable
generated: { by: agent:zcode, at: 2026-10-24T13:10:00+03:00 }
verified: { by: agent:zcode, at: 2026-10-19T20:30:00+03:00 }
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
    title: AnkiSettings tab section (language selector + per-language card content, global connection)
  - id: anki-tab-state
    resource: laravel/resources/js/lib/ankiTabState.js
    title: ankiTabState.js (the tab's per-language editing state — whole-section shape, entry accessors, sync config, sample payload)
  - id: anki-settings
    resource: laravel/app/Support/AnkiSettings.php
    title: AnkiSettings (saved connection, per-language deck/fields over the legacy global base, resolved per enabled language + default)
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
| Anki | Anki connection: AnkiConnect URL · port · Test connection (global); per-language card content: language selector + deck name · 13 field toggles in two semantics-labeled groups + generated-template preview (Copy HTML/CSS); Send to Anki: template sync + sample card for the selected language | autosaved `PATCH /ui-settings` (auth-only — section `anki`); AnkiConnect itself is called from the browser only |
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
renders — familiarity line, progress actions, form-of line, word family
sections, frequency line, transcriptions, definitions, translations,
examples, etymology, the Explanation toggle (the `explanation` section —
its checkbox label still reads "Explanation tab"). Eleven checkboxes in display groups
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
preview cannot drift from the real popup; in preview mode the action
toolbar's icons render disabled (no handlers) and the Explanation
toggle's pane shows a canned answer when toggled on (the activation
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

**Per-language deck + fields** (anki-popup-refresh): the `anki` section may
additionally carry a sparse `languages` map keyed by **enabled language
code** — `{deck?, fields?}` per entry, absent keys meaning "fall through".
`UpdateUiSettingsRequest` builds its rules per enabled `Language::enabled()`
code, so `validated()` strips whole non-enabled entries and unknown keys
inside an entry (a language that stops being enabled silently loses its
overrides); the resolved `default` entry — for words with an unknown or
missing `language_code` — is computed server-side from the legacy global
base and is never persistable. `AnkiSettings::for()` resolves one
`{deck, fields}` entry per enabled language plus `default`, each walking the
chain per-language → legacy global `deck`/`fields` → all-on defaults, so a
user with only legacy settings resolves identically to before; the prop
carries that map under `ankiSettings.languages` (enabled codes in
`sort_order`, `default` last) beside the unchanged top-level legacy base
(`url`, `port`, `deck`, `fields`) that survives as the read-fallback for
a prop without a `languages` map (the popup resolves
`languages[code] ?? languages.default` first).

**The tab's language selector** (anki-popup-refresh ticket 04) edits those
per-language entries: it lists the enabled languages — the page's shared
`languages` prop, which carries `code` beside the names since this ticket —
and opens on the user's native language when it is enabled, else the first
enabled one. Switching swaps the deck input, the thirteen toggles, the
template preview, and the sync/sample targets to that language's entry; the
connection block above (URL/port/test) stays global. The deck input lives in
the card-content header beside the selector (it left the connection block,
where the global deck used to sit next to the port). The selection itself is
not persisted — a reload re-derives it — and the resolved `default` entry is
never selectable. The tab's editable state mirrors the persisted `anki`
section exactly (connection + the complete per-language map) through
`resources/js/lib/ankiTabState.js`, so every autosave PATCH carries the
WHOLE section, and a save for one language never disturbs another language
or the connection (pinned executable by `ankiTabState.test.js`, source-side
by `AnkiSettingsMirrorTest`). The legacy top-level base is deliberately
ABSENT from that payload (review fixes): the endpoint strips incoming
`deck`/`fields` — no rule accepts them — and preserves the previously saved
pair server-side, so a tab save can never rewrite the read-only base the
word-level fallback still reads; without a selection (no enabled languages)
nothing is writable — `ankiTabDefaultEntry` serves the resolved `default`
entry as a read-only view for the preview and the sync/sample targets while
the deck input and toggles render disabled.

**Connection** — AnkiConnect endpoint (URL + port), global for every
language, plus a **Test connection** button that calls AnkiConnect's `version` action
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

**Card content** — the selected language's deck name and thirteen field
toggles in two display groups, each
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
collapsed sections ("No definitions yet." when empty — a labelled
definition line carrying its plain-text usage-label prefix, both display
tiers alike), hairline
separators. A `fields` option (the resolved Anki settings map, passed by
both senders — the tab's `settings.fields`, the popup's shared
`ankiSettings` prop) shapes that Body at send time: the body section
toggles plus the `body` master drop their sections entire, placeholder
included — newly sent cards only. A `labels` option carries the eight
UI-language strings (summaries, expander, "form of", the two calm
placeholders, and the definition `label` resolver behind that prefix) —
the tab passes
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
`resources/js/lib/ankiSync.js` on top of `ankiConnect.js`, targeting the
**selected language**: its deck and its per-language note type
("ExtApp Vocabulary ⋅ <code>", `noteTypeForLanguage` — the unsuffixed
legacy base only when nothing is selected, which cannot happen with a
populated language list). **Create /
update template in Anki** runs a fresh ensure pass: deck create-if-missing,
that note type create-if-missing (all 13 fields in fixed
order, `Body` last, + the "Recognition" card generated from the current
scalar toggles) or —
when it already exists — field-set verification followed by a
templates + CSS push, which is what re-applies toggled-off `ef-hidden`
classes to all existing cards' scalar fields (the Body values are send-time
and untouched by it). A same-name note type whose field set (or
whose card naming, which AnkiConnect would silently skip) doesn't match the
app-owned thirteen raises `AnkiNoteTypeMismatchError` (carrying the
inspected model name since ticket 02), surfaced with the
teaching copy — which model mismatched, expected vs found, plus the one-time
fix: in Anki, Tools →
Manage Note Types, delete the named type (its notes are deleted with
it), then sync again and re-send the words; a pre-v2 twelve-field install
hits exactly this by design, since AnkiConnect cannot add a field to an
existing note type — it is never rewritten. **Send sample card** adds
a note built from the shared fixture — stamped with the selected language,
so the Language field AND the duplicate search (deck + Word + Language)
speak the selection — with a find-before-add duplicate check scoped to
deck + Word + Language (`sendAnkiNote`): a fresh sample is added
(`allowDuplicate` stays false as a race net), and a found duplicate of one
of the app's OWN note types is refreshed in place since ticket 02 — all
thirteen values and the tag set rebuilt, note id / deck / review scheduling
untouched (the anki-popup-refresh reversal; the ADR amendment lands in
ticket 06) — reported since the review fixes with its own
"Sample card updated in \":deck\"." line (the old "nothing was duplicated"
copy remains only for a foreign-model duplicate, the one outcome an update
cannot touch). The ensure is memoized
once per page session per endpoint+deck+note type, so subsequent sends skip
straight
to the duplicate check; changing the endpoint, deck, or language misses the
memo
naturally (field toggles don't invalidate it — values always carry every
field). Inline status lines report sync / sent / updated / duplicate
outcomes;
failures render by the error's kind — a note-type mismatch the expected-vs-
found warning, an Anki-side `AnkiConnectError` (kind `anki`, e.g. a
addNote duplicate race) the inline "Anki error: …" panel, and everything
unreachable the same connection-hint panel as the test probe, whose
**Request permission** step grants the origin approval and then replays
the blocked action in one click.

The word popup's per-word **Anki send** (see
[Interactive Words](/domains/interactive-words.md)) reuses this exact
path — the same `sendAnkiNote` and the same memoized ensure (module state
shared with the tab, so a tab sync and a popup send in one page session
don't redo each other's work) — with the word's per-language target
resolved by `ankiWordSyncConfig` (deck + fields from the word's language
entry, note type following the resolved entry) and the note built from
the popup's live payload instead of the fixture, with the popup's own
UI-language label strings passed, so a card sent from the reader carries
the sender's locale; on-open detection and the update-in-place semantics
live in [Interactive Words](/domains/interactive-words.md). The tab's
preview/sample card narrow
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
