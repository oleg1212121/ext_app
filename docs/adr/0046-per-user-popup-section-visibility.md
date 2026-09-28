# ADR 0046: Per-user popup section visibility

Date: 2026-09-27
Status: Accepted

## Context

The Word popup's block set has been fixed since ADR 0045: the headword
header (familiarity line, form-of pointer), the word family's dictionary
sections with their satellites (transcriptions, definitions, translations,
examples, etymology), the Explanation tab with the AI context explanation,
and the progress footer. Users who don't need a block — learners who find
the familiarity number noisy, readers with no interest in etymology, users
without an API key for whom the Explanation tab is permanent guidance text —
could not turn anything off. The frequency rank (`words.frequency`) was not
displayed at all, although it orders the word family behind the scenes
(ADR 0045).

## Decisions

### 1. Visibility lives in `ui_settings.popup`; absent means visible

Eleven boolean keys — familiarity, progress_actions, form_of, word_family,
frequency, transcriptions, definitions, translations, examples, etymologies,
explanation — stored in the existing `user_settings.ui_settings` JSONB as a
third section next to simulator/reader: same `PATCH /ui-settings` endpoint,
same section-replace merge in `UiSettingsController`, same FormRequest
boolean rules. A key absent from the saved map means visible;
`App\Support\PopupVisibility::for()` resolves the user's saved opt-outs over
all-visible defaults and travels to every page as the shared Inertia prop
`popupVisibility` — the same pattern as `uiStrings`/`locale`, chosen over
per-controller prop threading, which would have to cross three component
levels on both surfaces. One setting set drives both the simulator and the
reader: they render the same `WordPopup` component.

### 2. The profile's Popups tab previews with the real component

A fifth profile tab ("Popups", between Preferences and AI Models) renders
the checkboxes as display groups (Knowledge / Word family / Dictionary
details / Tabs & info), autosaved per flick through the existing
`useUiSettingsAutosave` hook (debounced 800 ms, no save button —
instant-preview toggles do not suit a form submit), and below them a live
preview: the popup body is extracted into an exported `PopupContent`
component (the portal, viewport positioning and word fetching stay with
`WordPopup`) and rendered inline with a fixture-style sample word family,
bound to the checkbox state. Because the preview is the real body component,
it cannot drift from the real popup. In preview mode the progress buttons
render disabled and the Explanation tab opens on a canned answer instead of
spending tokens.

`PATCH /ui-settings` moved from the approved-only route group to the
auth-only profile group: the Popups tab is reachable pre-approval like every
other profile tab, and the endpoint only ever touches the caller's own
settings.

### 3. Frequency line, and no enforced minimum

The popup payload gains the bound headword's integer `frequency` rank (null
for unranked words — the 1,100,000 sentinel included), rendered as a
"Frequency: #N" line under the familiarity line. The headword header itself
is never toggleable — an all-off user still sees what word they clicked.
Everything below can go off: `explanation` false drops the whole tab strip
including the Models-used icon (the popup then renders exactly as it does
for words without an explain payload), and `word_family` false filters the
dictionary sections down to the headword's own, using the same discriminator
the section labels use. No minimum is enforced: a body emptied by choice is
legitimate.

## Consequences

The preview re-renders on every checkbox flick but never issues network
calls — the sample payload is static. The saved map is sparse by design, so
new sections default to visible without backfill; server-side consumers of
`ui_settings` other than `PopupVisibility` must not assume the popup keys'
presence. The frequency line shows the raw rank, whose number is only a
comparative signal — acceptable, and one checkbox away from gone.
