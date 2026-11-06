// The word card's view-model (word-card ticket 01): one pure function turns
// the card's whole state into everything the card renders, so the derived
// logic sits inside vitest's behavioral reach (ADR 0066) instead of inside
// Components/WordPopup.jsx. `t` is injected (the lib/ankiTemplate.js
// uiLabels(t) precedent) — the module imports no React and stays Node-safe.
// Color and icon fields are TOKENS, never class strings: the component maps
// them to its full-literal Tailwind classes.

import {ankiConnectEndpoint} from './ankiConnect';
import {dimsEntry, entryUsageChips} from './usageLabelDisplay';
import {FAMILIARITY_MAX} from './wordFamiliarity';

// A payload entry belongs to the popup's own headword when its word matches
// it (case-insensitively) or is absent; everything else is a base-word
// section of the word family (ADR 0045). The ONE definition (word-card
// ticket 03): the card's entry list below and the Anki note builder
// (lib/ankiTemplate.js) both read it.
export function entryMatchesHeadword(entry, headword) {
    return !entry.word || entry.word.toLowerCase() === String(headword ?? '').toLowerCase();
}

// Section heading, one per popup section: "MELTED — verb" for the popup's
// own headword sections, "MELT — verb" for base-word sections (ADR 0045) —
// headword uppercase, POS lowercase, em dash. Raw text: React escapes it at
// render, and the Anki note builder HTML-escapes it for its field values.
export function sectionLabel(entry, headword) {
    const word = entryMatchesHeadword(entry, headword) ? headword : entry.word;

    return [String(word ?? '').toUpperCase(), entry.word_class?.toLowerCase()]
        .filter(Boolean)
        .join(' — ');
}

/**
 * Build the word card's view-model from one state object.
 *
 * State (every field but `status` optional, defaults = the profile
 * preview's quiet card):
 * - `status`: 'missing' (an unmapped token, data null) | 'loading' |
 *   'error' (the pre-payload card) | 'loaded' (a dictionary payload).
 * - `surface`, `data` (the payload), `error` (the load-error message).
 * - `familiarity` (the opening value) and `resolvedFamiliarity` (the
 *   popup-open penalty's response, which wins once it answers).
 * - `sections`: the ADR 0046 popup-visibility map (absent key = visible).
 * - `handlers`: the handler set the shell passes — read for PRESENCE only
 *   (`onProgressAction`, `onAnkiSend`, `onAnkiProbe`, `onAskAi`); an absent
 *   handler is an absent capability and disables its icon.
 * - Anki: `ankiSend` (the send outcome), `ankiExisting`
 *   ('checking' | 'found' | 'absent'), `ankiReachable`, `ankiSettings`,
 *   `ankiUnknown` (the quiet probe's has-heard-nothing state).
 * - Ask-AI: `canAskAi` (the approved-admin gate), `aiBusy`, `aiStarted`,
 *   `aiError`, `aiErrorStatus`, `languageCode` (the clicked column's).
 * - `progressBusy`, `explainProps`, `explainShown` (the toggle's pane),
 *   `resolving` (the missing card's on-open lookup in flight), `preview`.
 *
 * Output: `toolbar` (the descriptors that render, in row order — `kind`,
 * `label`/`title`, `color` and `icon` tokens, `disabled`, plus `pressed`,
 * `hoverProbe` or `hasPopup` where applicable), `header` (null on the
 * pre-payload card; each line object present exactly when it renders),
 * `entries`, `explain`, `askAi`, `body`, `notices` (the info popup's
 * messages, null where the card has no info popup), `familiarity` (the
 * effective value), `status`, `preview`.
 */
export function buildCardModel(state, t) {
    const {
        status,
        surface = '',
        data = null,
        error = null,
        familiarity = null,
        resolvedFamiliarity = null,
        sections = {},
        handlers = {},
        ankiSend = null,
        ankiExisting = 'absent',
        ankiReachable = false,
        ankiSettings = null,
        ankiUnknown = true,
        progressBusy = false,
        explainProps = null,
        explainShown = false,
        canAskAi = false,
        aiBusy = false,
        aiStarted = false,
        aiError = null,
        aiErrorStatus = null,
        languageCode = null,
        resolving = false,
        preview = false,
    } = state;
    const isLoaded = status === 'loaded' && data != null;
    // The missing card mirrors the loaded card's chrome (toolbar, header,
    // info popup); the pre-payload loading / error card is bare.
    const hasCard = isLoaded || status === 'missing';

    // The popup-open penalty's response (word-popup-overhaul ticket 01)
    // replaces the opening familiarity once it answers; nullish, not falsy,
    // so a resolved 0 sticks.
    const effectiveFamiliarity = resolvedFamiliarity ?? familiarity ?? null;

    // The provenance chips (popup-provenance ticket 04): the importing
    // writer plus the word-level ai marker when a succeeded ask exists. An
    // adopt-on-ask mint carries source 'ai' AND ai_asked true from the same
    // ask — the same fact twice — so the parts dedupe to a single "ai",
    // never "ai · ai". Only a loaded word carries provenance.
    const sourceBadge = isLoaded
        ? [...new Set([data.source, data.ai_asked ? 'ai' : ''].filter(Boolean))]
        : [];

    // The header lines exist on the loaded card only — the missing card's
    // header carries the raw clicked surface alone (there is no word behind
    // it). Each line object is present exactly when the line renders.
    const header = hasCard
        ? {
            title: isLoaded ? data.word : surface,
            familiarity: isLoaded && sections.familiarity !== false
                ? {text: t('word.familiarity', {value: effectiveFamiliarity ?? 0, max: FAMILIARITY_MAX})}
                : null,
            frequency: isLoaded && sections.frequency !== false && data.frequency != null
                ? {text: t('word.frequency', {rank: Number(data.frequency).toLocaleString()})}
                : null,
            formOf: isLoaded && sections.form_of !== false && (data.form_of?.length > 0 || data.is_form)
                ? {
                    surface,
                    label: t('word.form_of'),
                    headwords: data.form_of?.length > 0 ? data.form_of : [data.word],
                }
                : null,
            sourceBadge,
        }
        : null;

    // The word family's sections, the ADR 0046 `word_family: false` key
    // keeping only the headword's own; each carries its section label, the
    // word-level usage chips, and the whole-section dimming mark.
    const familyEntries = isLoaded
        ? (sections.word_family === false
            ? (data.entries ?? []).filter((entry) => entryMatchesHeadword(entry, data.word))
            : (data.entries ?? []))
        : [];
    const entries = familyEntries.map((entry) => ({
        entry,
        label: sectionLabel(entry, data.word),
        chips: entryUsageChips(entry, t),
        dimmed: dimsEntry(entry),
    }));

    // The Anki icon's label, shared by its title and aria-label: a send in
    // flight wins, then the reachability state — reachable picks between
    // the update and add forms; unknown (the session has heard nothing from
    // Anki yet) names the endpoint and offers the click-as-check; a
    // known-unreachable endpoint carries the start-Anki guidance, as does
    // the no-settings case.
    const ankiActionLabel = ankiSend?.status === 'sending'
        ? t('word.anki_sending')
        : (!ankiSettings
            ? t('word.anki_unreachable')
            : (ankiReachable
                ? (ankiExisting === 'found' ? t('word.anki_update') : t('word.anki_send'))
                : (ankiUnknown
                    ? t('word.anki_unknown', {endpoint: ankiConnectEndpoint(ankiSettings)})
                    : t('word.anki_unreachable'))));

    // The Explanation toggle and the robot ride the explain payload and the
    // ADR 0046 `explanation` key; a payload without sentence identity (the
    // AI-answer mount) can mount no Context explanation, so its toggle is
    // disabled while the robot stays live. Pressed while the pane is shown.
    // The missing card renders the toggle for toolbar parity but can never
    // explain — no word to attach the pane to — so it is disabled, never
    // pressed. The pre-payload card has neither.
    const explain = {
        visible: hasCard && explainProps != null && sections.explanation !== false,
        disabled: isLoaded ? explainProps != null && explainProps.sentenceId == null : true,
        pressed: isLoaded ? explainShown : false,
    };

    // The Ask-AI label, shared by title and aria-label: an already-enriched
    // word reads "ask again" (the re-ask variant), a request in flight reads
    // "sending", the default is the plain ask. Only a loaded word can have
    // been asked — the missing card's label is the same chain without it.
    const aiAsked = isLoaded && Boolean(data.ai_asked);
    const askAiLabel = aiAsked
        ? t('word.ask_ai_again')
        : (aiBusy ? t('word.ask_ai_sending') : t('word.ask_ai'));
    // Whether an ask can be dispatched at all (the retry in the info popup
    // binds to the same answer): admin-only (word-popup-overhaul ticket 06)
    // and the handler present; the missing card additionally needs a column
    // language to ask in (nothing valid to ask with otherwise) and the
    // on-open resolve settled — the icon holds while it is in flight, since
    // a hit swaps in the loaded card (whose ai_asked picks the re-ask form).
    const askAiAvailable = hasCard
        && canAskAi
        && handlers.onAskAi != null
        && (isLoaded || (languageCode != null && !resolving));

    const toolbar = [];
    if (hasCard) {
        // Know / remove mark on the left, behind the progress_actions toggle
        // (the only toolbar icons a visibility key hides). Know is moot once
        // the word is already max; remove mark needs a mark to remove (0 is
        // a mark). On the missing card both are wordless — disabled, never
        // hidden.
        if (sections.progress_actions !== false) {
            toolbar.push({
                kind: 'know',
                label: t('word.i_know_this'),
                title: t('word.i_know_this'),
                color: 'verdigris',
                icon: 'checkCircle',
                disabled: isLoaded
                    ? progressBusy || handlers.onProgressAction == null || effectiveFamiliarity === FAMILIARITY_MAX
                    : true,
            });
            toolbar.push({
                kind: 'remove-mark',
                label: t('word.remove_mark'),
                title: t('word.remove_mark'),
                color: 'vermilion',
                icon: 'minusCircle',
                disabled: isLoaded
                    ? progressBusy || handlers.onProgressAction == null || effectiveFamiliarity == null
                    : true,
            });
        }
        // Pushed right, in order: Anki, the Explanation toggle, Ask-AI, the
        // robot — these ignore the popup-visibility toggles. The Anki icon
        // carries the word's in-deck state (update glyph once the on-open
        // check found the note) and the probe's answer as COLOR only — amber
        // while reachable, neutral grey otherwise; since the quiet probe
        // (2026-10-24) the disabled gate ignores reachability so a cold
        // session can re-discover Anki by hovering (the probe) or clicking
        // (the send). The missing card's Anki icon is wordless: the plain add
        // form, always neutral, no probe.
        toolbar.push(isLoaded
            ? {
                kind: 'anki',
                label: ankiActionLabel,
                title: ankiActionLabel,
                color: ankiReachable ? 'amber' : 'neutral',
                icon: ankiExisting === 'found' ? 'ankiUpdate' : 'ankiAdd',
                disabled: handlers.onAnkiSend == null || ankiSend?.status === 'sending',
                hoverProbe: handlers.onAnkiProbe != null,
            }
            : {
                kind: 'anki',
                label: t('word.anki_send'),
                title: t('word.anki_send'),
                color: 'neutral',
                icon: 'ankiAdd',
                disabled: true,
                hoverProbe: false,
            });
        if (explain.visible) {
            toolbar.push({
                kind: 'explain',
                label: t('word.explain_toggle'),
                title: t('word.explain_toggle'),
                color: 'neutral',
                icon: 'chatBubble',
                disabled: explain.disabled,
                pressed: explain.pressed,
            });
        }
        // Admin-only (word-popup-overhaul ticket 06): hidden — not disabled
        // — for everyone else, matching the endpoint's 403.
        if (canAskAi) {
            toolbar.push({
                kind: 'ask-ai',
                label: askAiLabel,
                title: askAiLabel,
                color: 'neutral',
                icon: aiAsked ? 'sparklesRefresh' : 'sparkles',
                disabled: !askAiAvailable || aiBusy,
            });
        }
        // Not a toggle: opens the Models used popup. Rightmost, behind the
        // same gate as the Explanation toggle, and always live — the popup
        // needs no word.
        if (explain.visible) {
            toolbar.push({
                kind: 'models',
                label: t('ai.models_icon'),
                title: t('ai.models_icon'),
                color: 'info',
                icon: 'robot',
                disabled: false,
                hasPopup: 'dialog',
            });
        }
    }

    // The body line of a card without sections: the pre-payload card's
    // loading / error line, and the missing card's verdict — held as the
    // loading line while the on-open resolve is in flight (pronouncing "not
    // in the dictionary" before it answers reads as an empty popup that
    // later swaps itself, ADR 0096 amendment).
    let body = null;
    if (status === 'loading') {
        body = {kind: 'loading', text: t('word.loading')};
    } else if (status === 'error') {
        body = {kind: 'error', text: error};
    } else if (!isLoaded) {
        body = {kind: 'missing', resolving, text: resolving ? t('word.loading') : t('word.not_in_dictionary')};
    }

    // The info popup's transient messages (word-popup-overhaul ticket 04).
    // The missing card can send nothing to Anki, so it floats the ask-AI
    // messages only; the bare pre-payload card has no info popup at all.
    const notices = hasCard
        ? {
            ankiSend: isLoaded ? ankiSend : null,
            aiStarted,
            aiError,
            aiErrorStatus,
        }
        : null;

    return {
        status,
        preview,
        familiarity: effectiveFamiliarity,
        toolbar,
        header,
        entries,
        explain,
        askAi: {label: askAiLabel, available: askAiAvailable},
        body,
        notices,
    };
}
