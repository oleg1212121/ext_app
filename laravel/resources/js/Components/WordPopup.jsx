import React, {useEffect, useRef, useState} from 'react';
import {createPortal} from 'react-dom';
import {Link, usePage} from '@inertiajs/react';
import ModelsUsedPopup, {RobotHelpIcon} from './ModelsUsedPopup.jsx';
import {useI18n} from '../i18n';
import {getCsrfToken} from '../lib/http';
import {renderMarkdown} from '../lib/markdown';
import {canAskExplanation} from '../lib/wordExplain';
import {FAMILIARITY_MAX} from '../lib/wordFamiliarity';
import {Icon} from './icons.jsx';
import {AnkiConnectError} from '../lib/ankiConnect';
import {buildNoteFields, uiLabels} from '../lib/ankiTemplate';
import {AnkiNoteTypeMismatchError, findExistingNote, sendAnkiNote} from '../lib/ankiSync';
import {ankiWordSyncConfig} from '../lib/ankiWordConfig';

const POPUP_MARGIN = 8;
const VIEWPORT_MARGIN = 12;
const MIN_POPUP_HEIGHT = 160;
const TRANSLATIONS_PREVIEW = 8;
const TRANSCRIPTIONS_PREVIEW = 1;

// Page-lifetime memo of Explanation-pane states, keyed by
// rowKind|rowId|side|sentenceIndex|surface|modelKey. Client-side only — the
// server keeps no cache, so re-opening the same word in the same sentence
// never re-spends tokens. Every state is memoized, not just answers: a
// pending entry suppresses the auto-fire (and duplicate POSTs) while a
// request is in flight, an errored one keeps the failure — with its Retry
// button — on re-activation instead of silently refiring. Writes notify
// subscribers, so a pane remounted mid-request adopts the outcome.
const explainCache = new Map();
const explainCacheSubscribers = new Set();

const cachedExplanation = (key) => explainCache.get(key) ?? null;

const storeExplanation = (key, explanation) => {
    explainCache.set(key, explanation);
    explainCacheSubscribers.forEach((onWrite) => onWrite(key, explanation));
};

const explainButtonClass = 'rounded-sm border border-[var(--color-verdigris)] px-2.5 py-1 text-[0.857em] hover:bg-[var(--color-verdigris)]/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] dark:border-[var(--color-verdigris-night)]';

// Surface styling of the popup box, shared by the real portal and the
// profile's Popups-tab preview (ADR 0046). Positioning classes stay with
// the portal wrapper.
export const popupContainerClass = 'flex flex-col overflow-hidden rounded-md border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[#FDFBF5] dark:bg-[#1C1915] shadow-lg shadow-black/20 font-sans text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]';

const tabButtonClass = (active) => [
    'word-popup-tab',
    '-mb-px border-b-2 py-1.5 text-[0.786em] uppercase tracking-wider transition-colors',
    active
        ? 'border-[var(--color-verdigris)] opacity-100 dark:border-[var(--color-verdigris-night)]'
        : 'border-transparent opacity-60 hover:opacity-100',
].join(' ');

// Action-toolbar icon buttons (the popup's first row). The glyphs carry no
// color of their own — these text classes drive the resting and hover states:
// "I know this word" keeps the verdigris accent the old footer button's
// border gave it, the other two rest at the tab strip's reduced opacity.
const toolbarButtonClass = (accent) => [
    'rounded-sm p-1 transition-[opacity,background-color]',
    accent
        ? 'text-[var(--color-verdigris)] hover:bg-[var(--color-verdigris)]/10 dark:text-[var(--color-verdigris-night)] dark:hover:bg-[var(--color-verdigris-night)]/10'
        : 'opacity-80 hover:opacity-100',
    'disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]',
].join(' ');

// Popup typography derives from the host page's font-size setting (ADR 0031):
// the page passes the size it computed with popupFontSizeFor(); this default
// only covers callers that don't (≈ today's look, slightly larger than the
// original fixed 14px text).
export const DEFAULT_POPUP_FONT_SIZE = 17;
const POPUP_BASE_WIDTH = 560;

/**
 * Derive the popup font from a page's reading font size: proportional at
 * POPUP_FONT_RATIO, floored so small reading fonts never shrink the popup
 * below its original fixed size, capped so huge fonts stay readable.
 */
export function popupFontSizeFor(pageFontSize, {
    ratio = 0.65,
    min = 14,
    max = 32,
} = {}) {
    return Math.min(max, Math.max(min, Math.round(pageFontSize * ratio)));
}

/**
 * Position the popover next to the clicked word, always inside the viewport:
 * it opens below the word unless there is more room above (then it flips up,
 * anchored to the word's top edge), and its height is capped to the larger
 * side so the top action toolbar stays reachable. Width scales with the popup
 * font (560px at the default 17px) so proportions hold as the user scales
 * text from the page's +/- controls.
 */
function popupStyle(rect, fontSize) {
    const width = Math.min(
        Math.round((POPUP_BASE_WIDTH * fontSize) / DEFAULT_POPUP_FONT_SIZE),
        window.innerWidth - VIEWPORT_MARGIN * 2,
    );
    const left = Math.min(
        Math.max(VIEWPORT_MARGIN, rect.left + window.scrollX),
        Math.max(VIEWPORT_MARGIN, window.innerWidth - width - VIEWPORT_MARGIN),
    );

    const spaceBelow = window.innerHeight - rect.bottom - POPUP_MARGIN - VIEWPORT_MARGIN;
    const spaceAbove = rect.top - POPUP_MARGIN - VIEWPORT_MARGIN;
    const flipUp = spaceAbove > spaceBelow;
    const maxHeight = Math.max(MIN_POPUP_HEIGHT, Math.max(spaceBelow, spaceAbove));
    const top = flipUp ? rect.top + window.scrollY : rect.bottom + window.scrollY + POPUP_MARGIN;

    return {
        left: `${left}px`,
        top: `${top}px`,
        width: `${width}px`,
        maxHeight: `${maxHeight}px`,
        ...(flipUp ? {transform: 'translateY(-100%)'} : {}),
    };
}

// A payload entry belongs to the popup's own headword when its word matches
// it; everything else is a base-word section of the word family (ADR 0045).
function entryMatchesHeadword(entry, headword) {
    return !entry.word || entry.word.toLowerCase() === String(headword ?? '').toLowerCase();
}

// Section heading, one per popup section: "MELTED — verb" for the popup's
// own headword sections, "MELT — verb" for base-word sections (ADR 0045) —
// headword uppercase, POS lowercase, em dash.
function sectionLabel(entry, headword) {
    const word = entryMatchesHeadword(entry, headword) ? headword : entry.word;

    return [String(word ?? '').toUpperCase(), entry.word_class?.toLowerCase()]
        .filter(Boolean)
        .join(' — ');
}

/**
 * One previewed popup line: the first `previewCount` items joined with
 * `separator`, then a "+N more…" expander for the rest (each line owns its
 * expanded state). `label` names the stable `word-popup-{label}` and
 * `word-popup-{label}-more` classes, `renderItem` maps an item to its text,
 * `decorate` wraps the joined text (transcriptions wear brackets), and
 * `empty` replaces the whole line when there is nothing to show.
 */
function CollapsibleLine({
    items,
    previewCount,
    separator,
    label,
    renderItem,
    decorate = null,
    empty = null,
    className,
}) {
    const {t} = useI18n();
    const [expanded, setExpanded] = useState(false);

    if (empty !== null && items.length === 0) {
        return empty;
    }

    const visible = expanded ? items : items.slice(0, previewCount);

    return (
        <p className={`word-popup-${label} ${className}`}>
            {decorate ? decorate(visible.map(renderItem).join(separator)) : visible.map(renderItem).join(separator)}
            {items.length > previewCount && !expanded && (
                <button
                    type="button"
                    onClick={() => setExpanded(true)}
                    className={`word-popup-${label}-more ml-1 underline underline-offset-2 opacity-70 hover:opacity-100`}
                >
                    {t('word.more', {count: items.length - previewCount})}
                </button>
            )}
        </p>
    );
}

/**
 * The Explanation pane's "can't ask yet" states: what the reader must do
 * first — add an API key (`word.explain_no_key`) or choose an explanation
 * model (`word.explain_choose_model`) — and the way there.
 */
function ExplainGuidance({messageKey}) {
    const {t} = useI18n();

    return (
        <div className="word-popup-explain-guidance py-3">
            <p className="text-[0.857em] opacity-70">{t(messageKey)}</p>
            <Link
                href="/profile?tab=ai"
                className="mt-2 inline-block text-[0.857em] text-[var(--color-verdigris)] underline underline-offset-2 hover:opacity-80 dark:text-[var(--color-verdigris-night)]"
            >
                {t('word.explain_go_to_settings')}
            </Link>
        </div>
    );
}

/**
 * The Word popup's second tab: asks the AI what the word means in its
 * sentence context (POST /ai/word-explain) the moment the tab activates —
 * activation mounts this pane, so a []-deps effect fires the request once
 * per activation and never on re-renders. Keyless users (enabled false) and
 * users without a chosen explanation model (modelKey null) see guidance
 * instead of a request; a hit in the page-lifetime explain cache renders
 * the memoized state instantly and skips the fire — a pending or errored
 * entry suppresses it exactly like an answer does, so re-activating the tab
 * never re-spends tokens or double-POSTs without a click. The model is the
 * user's stored explanation preference — the server resolves it; the popup
 * only carries `modelKey` to discriminate the client cache. `explainKey`
 * also guards against stale responses landing after the popup has moved to
 * another word.
 *
 * `preview` (the profile's Popups tab) skips the network entirely: it opens
 * on a canned answer and keeps the Ask-again button inert.
 */
function ExplainPane({explain, explainKey, preview = false}) {
    const {t} = useI18n();
    const [explanation, setExplanation] = useState(() => preview
        ? {status: 'done', answer: t('word.explain_preview')}
        : cachedExplanation(explainKey));
    const keyRef = useRef(explainKey);
    keyRef.current = explainKey;

    useEffect(() => {
        if (preview) {
            return;
        }
        setExplanation(cachedExplanation(explainKey));
    }, [explainKey, preview]);

    // Cache writes notify subscribers: a pane remounted — or re-keyed —
    // while a request is in flight adopts its outcome even though the fetch
    // was started by an earlier mount of this pane.
    useEffect(() => {
        if (preview) {
            return;
        }
        const onCacheWrite = (key, value) => {
            if (keyRef.current === key) {
                setExplanation(value);
            }
        };
        explainCacheSubscribers.add(onCacheWrite);
        return () => explainCacheSubscribers.delete(onCacheWrite);
    }, [preview]);

    const firedRef = useRef(false);

    // Tab activation == this pane mounting (PopupContent mounts ExplainPane
    // only while the Explanation tab is active), so this []-deps effect runs
    // exactly once per activation and never on re-renders. The guards make
    // the fire a no-op when there is nothing to ask — preview, no key or
    // model, no sentence to ask about — or when the cache already holds this
    // key's state, of any kind: an answer, a request still in flight, or an
    // error the Retry button owns (page-lifetime explainCache).
    const canAsk = canAskExplanation(explain);
    const autoAsks = !preview && canAsk && explain.sentenceId != null;

    useEffect(() => {
        if (firedRef.current || !autoAsks || explanation !== null) {
            return;
        }
        firedRef.current = true;
        request();
    }, []);

    const request = () => {
        if (preview) {
            return;
        }
        storeExplanation(keyRef.current, {status: 'loading'});
        fetch('/ai/word-explain', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(getCsrfToken() ? {'X-CSRF-TOKEN': getCsrfToken()} : {}),
            },
            body: JSON.stringify({
                entity_sentence_id: explain.sentenceId,
                word_id: explain.wordId,
                surface: explain.surface,
            }),
        })
            .then(async (res) => {
                const json = await res.json().catch(() => null);
                if (!res.ok) {
                    throw new Error(
                        json?.error
                            ?? json?.message
                            ?? t('word.explain_failed', {status: res.status}),
                    );
                }

                return json.data.answer;
            })
            .then((answer) => {
                if (keyRef.current !== explainKey) {
                    return;
                }
                storeExplanation(keyRef.current, {status: 'done', answer});
            })
            .catch((e) => {
                if (keyRef.current !== explainKey) {
                    return;
                }
                storeExplanation(keyRef.current, {
                    status: 'error',
                    message: e instanceof Error && e.message
                        ? e.message
                        : t('word.explain_failed', {status: '?'}),
                });
            });
    };

    return (
        <div className="word-popup-explain px-3.5 pb-1">
            {!canAsk && (
                <ExplainGuidance
                    messageKey={explain.enabled === false ? 'word.explain_no_key' : 'word.explain_choose_model'}
                />
            )}

            {/* The auto-request pane shows its loading line from the first
                paint — before the mount effect has actually fired — so the
                pane never draws blank while its request is on its way. */}
            {(explanation?.status === 'loading' || (explanation === null && autoAsks)) && (
                <p className="word-popup-explain-loading py-3 text-[0.857em] opacity-60">{t('word.explain_loading')}</p>
            )}

            {explanation?.status === 'error' && (
                <div className="word-popup-explain-error py-3">
                    <p className="text-[0.857em] text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">{explanation.message}</p>
                    <button type="button" onClick={request} className={`word-popup-explain-retry ${explainButtonClass} mt-2`}>
                        {t('word.explain_retry')}
                    </button>
                </div>
            )}

            {explanation?.status === 'done' && (
                <div className="word-popup-explain-answer py-2">
                    {explanation.answer
                        ? (
                            <div
                                className="space-y-2 text-[0.929em] leading-snug [&_li]:ml-4 [&_li]:list-disc [&_ol]:ml-4 [&_ol]:list-decimal [&_p]:mt-0 [&_ul]:ml-4 [&_ul]:list-disc"
                                dangerouslySetInnerHTML={{__html: renderMarkdown(explanation.answer)}}
                            />
                        )
                        : (
                            <p className="word-popup-explain-empty text-[0.857em] opacity-60">{t('word.explain_failed', {status: 200})}</p>
                        )}
                    <button
                        type="button"
                        onClick={request}
                        className="word-popup-explain-again mt-2 text-[0.786em] underline underline-offset-2 opacity-70 hover:opacity-100"
                    >
                        {t('word.explain_again')}
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * The word popup's body — everything inside the box, minus the box itself:
 * the action toolbar (the card's first row, above the headword), the
 * headword header (familiarity, frequency, form-of lines), the tab strip
 * with the Models-used icon, the dictionary sections of the word family and
 * the Explanation tab. Shared verbatim by the real portal popup and the
 * profile's Popups-tab preview, so the preview can never drift from the
 * real thing.
 *
 * The toolbar hosts the three actions as line-art icons — "I know this
 * word" / "Remove mark" on the left, "Save to Anki" on the right — with the
 * transient Anki status lines directly beneath. All three always render: an
 * inapplicable icon is disabled, not hidden (know: familiarity already max
 * or busy; remove mark: no mark or busy; anki: no handler or a send in
 * flight). Tooltips and aria-labels come from the existing word ui-strings.
 * The title shows the clicked word only — each section carries the POS in
 * its "WORD — pos" heading. Per section, top to bottom: translations as the
 * primary line (a calm no-translations placeholder when a section has none —
 * the background auto-fetch may still be pending), transcriptions (main
 * value, "+N more" expander for the rest), then three counted collapses —
 * definitions, examples, etymology. Every block inside the card carries a
 * stable `word-popup-*` class — the card's structure stays addressable from
 * outside CSS (page styles today; the profile preview reuses this component
 * and inherits the classes) without coupling to utility-class churn.
 *
 * `sections` (the user's Popup preferences, ADR 0046) hides blocks: a key
 * set to false drops that block, anything else renders. `explanation` false
 * removes the whole tab strip — the popup renders as it does for words
 * without an explain payload. `word_family` false filters the dictionary
 * sections down to the headword's own, dropping base-word sections. In the
 * toolbar, only the two progress icons answer to `progress_actions` — the
 * Anki icon ignores the popup-visibility toggles.
 *
 * `onProgressAction` (null in the preview) drives the progress icons; they
 * render disabled without it. `preview` additionally swaps the Explanation
 * tab's auto-request for a canned answer (no network).
 *
 * The toolbar also carries the "Save to Anki" icon (`onAnkiSend` /
 * `ankiSend`, both null in the preview — the icon renders disabled there):
 * it sits OUTSIDE the `progress_actions` gate, so it shows on every surface
 * no matter which popup blocks are hidden. The icon carries the word's
 * in-deck state (`ankiExisting`: 'checking' | 'found' | 'absent' — the
 * on-open duplicate check's outcome): 'found' flips it to the update glyph
 * with the "Update in Anki" tooltip, every other value keeps the add form —
 * including a silent detection failure. `ankiSend` holds the send's outcome
 * ({status: 'sending'|'added'|'updated'|'duplicate'|'error', error?}) for
 * the inline status line beneath the icon row; 'duplicate' is reachable
 * only for a FOREIGN-model note — a note of our own types updates in place
 * (lib/ankiSync.js) and reports 'updated' instead.
 */
export function PopupContent({
    surface,
    familiarity,
    data,
    explainProps,
    explainKey,
    sections,
    modelsOpen = false,
    onModelsOpenChange = null,
    onProgressAction = null,
    progressBusy = false,
    onAnkiSend = null,
    ankiSend = null,
    ankiExisting = 'absent',
    preview = false,
}) {
    const {t} = useI18n();
    const [tab, setTab] = useState('dictionary');

    // A fresh payload (the popup moved to another word) goes back to the
    // first tab, exactly like the portal popup used to reset on wordId.
    useEffect(() => {
        setTab('dictionary');
    }, [data]);

    const showTabs = explainProps != null && sections.explanation !== false;
    const showDictionary = !showTabs || tab === 'dictionary';
    const entries = sections.word_family === false
        ? (data.entries ?? []).filter((entry) => entryMatchesHeadword(entry, data.word))
        : data.entries;

    return (
        <>
            {/* The action toolbar — the popup's first row, above the word.
                "I know this word" / "Remove mark" on the left, "Save to
                Anki" pushed right. All three always render: an inapplicable
                icon is disabled, not hidden (know: familiarity already max
                or busy; remove mark: no mark or busy; anki: no handler or a
                send in flight). The `progress_actions` visibility gate hides
                only the two progress icons — the Anki icon ignores the
                popup-visibility toggles (spec). The Anki status lines render
                directly beneath the icon row. */}
            <div className="word-popup-actions shrink-0 border-b border-[var(--color-hairline)] px-3.5 dark:border-[var(--color-hairline-night)]">
                <div className="flex items-center gap-1 py-1.5">
                    {sections.progress_actions !== false && (
                        <button
                            type="button"
                            title={t('word.i_know_this')}
                            aria-label={t('word.i_know_this')}
                            disabled={progressBusy || onProgressAction == null || familiarity === FAMILIARITY_MAX}
                            onClick={() => onProgressAction?.('known')}
                            className={`word-popup-progress-known ${toolbarButtonClass(true)}`}
                        >
                            <Icon name="checkCircle" className="h-[1.25em] w-[1.25em]"/>
                        </button>
                    )}
                    {sections.progress_actions !== false && (
                        <button
                            type="button"
                            title={t('word.remove_mark')}
                            aria-label={t('word.remove_mark')}
                            disabled={progressBusy || onProgressAction == null || familiarity == null}
                            onClick={() => onProgressAction?.('reset')}
                            className={`word-popup-progress-reset ${toolbarButtonClass(false)}`}
                        >
                            <Icon name="minusCircle" className="h-[1.25em] w-[1.25em]"/>
                        </button>
                    )}
                    {/* The Anki icon carries the word's in-deck state:
                        'found' (the on-open check located the note) shows
                        the update glyph + "Update in Anki"; checking,
                        absent and a silent failure all keep the add form —
                        the send still runs find-before-add, so a misjudged
                        icon can never create a second card. */}
                    <button
                        type="button"
                        title={ankiSend?.status === 'sending'
                            ? t('word.anki_sending')
                            : (ankiExisting === 'found' ? t('word.anki_update') : t('word.anki_send'))}
                        aria-label={ankiSend?.status === 'sending'
                            ? t('word.anki_sending')
                            : (ankiExisting === 'found' ? t('word.anki_update') : t('word.anki_send'))}
                        disabled={onAnkiSend == null || ankiSend?.status === 'sending'}
                        onClick={onAnkiSend}
                        className={`word-popup-anki-send ${toolbarButtonClass(false)} ml-auto`}
                    >
                        <Icon name={ankiExisting === 'found' ? 'ankiUpdate' : 'ankiAdd'} className="h-[1.25em] w-[1.25em]"/>
                    </button>
                </div>

                {ankiSend?.status === 'added' && (
                    <p className="word-popup-anki-added pb-2 text-[0.857em] text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">
                        {t('word.anki_added')}
                    </p>
                )}
                {ankiSend?.status === 'updated' && (
                    <p className="word-popup-anki-updated pb-2 text-[0.857em] text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">
                        {t('word.anki_updated')}
                    </p>
                )}
                {/* Reachable only when a FOREIGN-model note matched deck +
                    Word + Language: a note of our own types updates in place
                    and reports 'updated' above (lib/ankiSync.js). */}
                {ankiSend?.status === 'duplicate' && (
                    <p className="word-popup-anki-duplicate pb-2 text-[0.857em] opacity-70">
                        {t('word.anki_already')}
                    </p>
                )}
                {ankiSend?.status === 'error' && (
                    <div className="word-popup-anki-error pb-2 text-[0.857em] text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">
                        <p>
                            {ankiSend.error instanceof AnkiNoteTypeMismatchError
                                ? t('word.anki_mismatch')
                                : (ankiSend.error instanceof AnkiConnectError && ankiSend.error.kind === 'anki'
                                    ? t('word.anki_failed', {message: ankiSend.error.message})
                                    : t('word.anki_unreachable'))}
                        </p>
                        <Link
                            href="/profile?tab=anki"
                            className="mt-1 inline-block underline underline-offset-2 hover:opacity-80"
                        >
                            {t('word.anki_go_to_settings')}
                        </Link>
                    </div>
                )}
            </div>

            <div className="word-popup-header shrink-0 px-3.5 pb-1 pt-3">
                <div className="flex items-baseline gap-2 flex-wrap">
                    {/* Reading font: RU headwords carry U+0301, which gaps when
                        base and mark come from different fonts (see --font-reading). */}
                    <span className="word-popup-title font-reading text-[1.286em] leading-tight">{data.word}</span>
                </div>

                {sections.familiarity !== false && (
                    <p className="word-popup-familiarity mt-0.5 text-[0.786em] tabular-nums opacity-60">
                        {t('word.familiarity', {value: familiarity ?? 0, max: FAMILIARITY_MAX})}
                    </p>
                )}

                {sections.frequency !== false && data.frequency != null && (
                    <p className="word-popup-frequency mt-0.5 text-[0.786em] tabular-nums opacity-60">
                        {t('word.frequency', {rank: Number(data.frequency).toLocaleString()})}
                    </p>
                )}

                {sections.form_of !== false && (data.form_of?.length > 0 || data.is_form) && (
                    <p className="word-popup-form-of mt-1 text-[0.857em] italic opacity-70">
                        «{surface}» — {t('word.form_of')}{' '}
                        {(data.form_of?.length > 0 ? data.form_of : [data.word]).map((headword, index) => (
                            <React.Fragment key={`${headword}-${index}`}>
                                {index > 0 && ', '}
                                «{headword}»
                            </React.Fragment>
                        ))}
                    </p>
                )}
            </div>

            {showTabs && (
                <div
                    role="tablist"
                    className="word-popup-tabs flex shrink-0 gap-3 border-b border-[var(--color-hairline)] px-3.5 dark:border-[var(--color-hairline-night)]"
                >
                    <button
                        type="button"
                        role="tab"
                        aria-selected={tab === 'dictionary'}
                        onClick={() => setTab('dictionary')}
                        className={tabButtonClass(tab === 'dictionary')}
                    >
                        {t('word.tab_dictionary')}
                    </button>
                    <button
                        type="button"
                        role="tab"
                        aria-selected={tab === 'explanation'}
                        onClick={() => setTab('explanation')}
                        className={tabButtonClass(tab === 'explanation')}
                    >
                        {t('word.tab_explanation')}
                    </button>
                    {/* Not a tab: opens the Models used popup. Kept out of the tab buttons so clicking it never switches tabs. */}
                    <button
                        type="button"
                        onClick={() => onModelsOpenChange?.(true)}
                        title={t('ai.models_icon')}
                        aria-label={t('ai.models_icon')}
                        aria-haspopup="dialog"
                        className="word-popup-models-button -mb-px ml-1 inline-flex items-center rounded-sm border-b-2 border-transparent py-1.5 opacity-60 transition-opacity hover:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]"
                    >
                        <RobotHelpIcon className="h-[1.1em] w-[1.1em]"/>
                    </button>
                </div>
            )}

            {showDictionary && (
                <div className="word-popup-dictionary min-h-0 flex-1 overflow-y-auto px-3.5 pb-2.5">
                    {entries?.map((entry, index) => (
                        <section
                            key={entry.id}
                            className={`word-popup-section${index > 0
                                ? ' mt-2.5 border-t border-[var(--color-hairline)] pt-2.5 dark:border-[var(--color-hairline-night)]'
                                : ''}`}
                        >
                            {sectionLabel(entry, data.word) && (
                                <h3 className="word-popup-section-label text-[0.786em] tracking-wider opacity-60">
                                    {sectionLabel(entry, data.word)}
                                </h3>
                            )}

                            {sections.translations !== false && (
                                // Empty is normal here: the background
                                // auto-fetch (Yandex → Google) queues silently
                                // and only lands on the next open, so the card
                                // says so calmly instead of showing an error.
                                <CollapsibleLine
                                    items={entry.translations ?? []}
                                    previewCount={TRANSLATIONS_PREVIEW}
                                    separator=", "
                                    label="translations"
                                    renderItem={(translation) => translation.word}
                                    className="mt-2 text-[0.929em] leading-snug text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]"
                                    empty={(
                                        <p className="word-popup-translations-placeholder mt-2 text-[0.857em] opacity-60">
                                            {t('word.no_translations')}
                                        </p>
                                    )}
                                />
                            )}

                            {sections.transcriptions !== false && entry.transcriptions?.length > 0 && (
                                <CollapsibleLine
                                    items={entry.transcriptions}
                                    previewCount={TRANSCRIPTIONS_PREVIEW}
                                    separator=" · "
                                    label="transcriptions"
                                    renderItem={(item) => item.value}
                                    decorate={(text) => `[${text}]`}
                                    className="mt-1 opacity-80"
                                />
                            )}

                            {sections.definitions !== false && (entry.definitions?.length > 0 ? (
                                <details className="word-popup-definitions mt-2 text-[0.857em]">
                                    <summary className="word-popup-summary cursor-pointer opacity-70">
                                        {t('word.definitions', {count: entry.definitions.length})}
                                    </summary>
                                    {/* list-inside keeps the markers in the card's
                                        inline box — `ml-4` + outside markers push
                                        wide ("100.") numbers past the card edge. */}
                                    <ol className="word-popup-definitions-list mt-1.5 list-decimal list-inside space-y-1 text-[0.929em] leading-snug">
                                        {entry.definitions.map((definition, definitionIndex) => (
                                            <li key={definitionIndex}>{definition}</li>
                                        ))}
                                    </ol>
                                </details>
                            ) : (
                                <p className="word-popup-no-definitions mt-1.5 text-[0.857em] opacity-60">{t('word.no_definitions')}</p>
                            ))}

                            {sections.examples !== false && entry.examples?.length > 0 && (
                                <details className="word-popup-examples mt-2 text-[0.857em]">
                                    <summary className="word-popup-summary cursor-pointer opacity-70">
                                        {t('word.examples', {count: entry.examples.length})}
                                    </summary>
                                    <ul className="word-popup-examples-list ml-4 mt-1 list-disc space-y-0.5 italic opacity-80">
                                        {entry.examples.map((example, exampleIndex) => (
                                            <li key={exampleIndex}>{example}</li>
                                        ))}
                                    </ul>
                                </details>
                            )}

                            {sections.etymologies !== false && entry.etymologies?.length > 0 && (
                                <details className="word-popup-etymologies mt-2 text-[0.857em]">
                                    <summary className="word-popup-summary cursor-pointer opacity-70">
                                        {t('word.etymology', {count: entry.etymologies.length})}
                                    </summary>
                                    {entry.etymologies.map((etymology, etymologyIndex) => (
                                        <p key={etymologyIndex} className="mt-1 text-[0.857em] italic leading-snug opacity-75">
                                            {etymology}
                                        </p>
                                    ))}
                                </details>
                            )}
                        </section>
                    ))}
                </div>
            )}

            {showTabs && tab === 'explanation' && (
                <div className="min-h-0 flex-1 overflow-y-auto pt-1">
                    <ExplainPane explain={explainProps} explainKey={explainKey} preview={preview}/>
                </div>
            )}

            {showTabs && (
                <ModelsUsedPopup
                    open={modelsOpen}
                    onClose={() => onModelsOpenChange?.(false)}
                    enabled={explainProps.enabled !== false}
                    answerModel={explainProps.answerLabel ? {label: explainProps.answerLabel} : null}
                    explanationModel={explainProps.modelLabel
                        ? {label: explainProps.modelLabel, followsAnswer: explainProps.followsAnswer === true}
                        : null}
                />
            )}
        </>
    );
}

/**
 * Word popover shown next to a Ctrl-clicked word: one section per part of
 * speech under the headword (details fetched lazily from GET /words/{id}),
 * plus the word progress actions and the Anki send in a top toolbar that
 * never scrolls away. `fontSize` (px) is the popup's root font, derived by
 * the host page from its reading font size (popupFontSizeFor); every inner
 * text size is em-relative to it, and the width scales with it.
 *
 * With an `explain` payload ({rowKind, rowId, side, sentenceIndex, modelKey,
 * enabled, modelLabel, followsAnswer, answerLabel}) the popup grows a tab
 * strip: the dictionary content stays on the first tab, and opening the
 * second tab immediately requests the AI context explanation. Without it
 * the popup renders exactly as before. `modelKey` (the user's explanation
 * model id) only discriminates the client cache and decides between the
 * auto-request and choose-a-model guidance — the server resolves the
 * actual model per user preference. The label fields (`modelLabel`,
 * `followsAnswer`, and the simulator-only `answerLabel`) exist for the tab
 * strip's robot icon, which opens the Models used popup; `enabled` false
 * (no API key) keeps the strip and shows add-key guidance instead of a
 * request.
 *
 * `sections` (the user's Popup preferences, ADR 0046) hides individual
 * blocks; it defaults to the popupVisibility prop shared to every page,
 * and an absent map means everything renders. The "Save to Anki" toolbar
 * icon ignores those toggles (always visible); it sends through
 * lib/ankiSync.js with the word's resolved config from the ankiSettings
 * shared prop (lib/ankiWordConfig.js: the language entry's deck + toggles,
 * per-language note type). On open a silent duplicate check
 * (lib/ankiSync.js's memoized findExistingNote — it never creates decks or
 * note types, and fails silently when Anki is off) drives the icon's add vs
 * update form; a send of an already-saved word updates the note in place.
 * The body itself lives in the exported PopupContent, which the profile's
 * Popups tab reuses for its live preview.
 */
export default function WordPopup({wordId, surface, familiarity, rect, onClose, onProgress, fontSize = DEFAULT_POPUP_FONT_SIZE, explain, sections}) {
    const {t} = useI18n();
    const {props} = usePage();
    const effectiveSections = sections ?? props.popupVisibility ?? {};
    const ankiSettings = props.ankiSettings ?? null;
    const nativeLanguage = props.auth?.user?.native_language ?? null;
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [ankiSend, setAnkiSend] = useState(null);
    // The on-open duplicate check's outcome for the current word:
    // 'checking' while the findNotes round-trip is in flight (the icon
    // stays in add form — no spinner), 'found' once the word sits in its
    // language's deck (the icon flips to the update glyph), 'absent' when
    // it does not — or when Anki was unreachable: the sync layer collapses
    // "not found" and "couldn't ask" into one null, and a send still runs
    // its own find-before-add, so a misjudged icon can never create a
    // second card.
    const [ankiExisting, setAnkiExisting] = useState('checking');
    // Mirrors `data` on every render; the send's completion paths compare
    // the data they started with against it, so a late result for a
    // previous word never lands on the current one (the explainKey
    // precedent in ExplainPane).
    const dataRef = useRef(null);
    dataRef.current = data;
    const [modelsOpen, setModelsOpen] = useState(false);
    // Read by the document-level close handlers below: while the nested
    // Models used popup is open, that modal owns Escape and outside-click
    // closing — the word popup underneath must stay put.
    const modelsOpenRef = useRef(false);
    modelsOpenRef.current = modelsOpen;
    const ref = useRef(null);

    const explainKey = explain
        ? [explain.sentenceId, surface, explain.modelKey].join('|')
        : null;

    useEffect(() => {
        setModelsOpen(false);
    }, [wordId, explainKey]);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        setError(null);
        setData(null);
        setAnkiSend(null);
        // The new word starts undetected — the previous word's 'found' must
        // never paint onto it (the detection effect below re-asserts
        // 'checking' when it runs for the loaded data).
        setAnkiExisting('checking');

        fetch(`/words/${wordId}?surface=${encodeURIComponent(surface)}`, {
            headers: {Accept: 'application/json'},
        })
            .then(async (res) => {
                if (!res.ok) {
                    throw new Error(t('word.load_failed', {status: res.status}));
                }
                return res.json();
            })
            .then((json) => {
                if (!cancelled) {
                    setData(json.data);
                }
            })
            .catch((e) => {
                if (!cancelled) {
                    setError(e instanceof Error ? e.message : t('word.load_failed', {status: '?'}));
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [wordId, surface, t]);

    // On-open duplicate detection (anki-popup-refresh): the popup silently
    // asks Anki whether this word is already saved — scoped to the word's
    // language deck + Word + Language through lib/ankiSync.js's
    // findExistingNote, which never runs the ensure (detection must not
    // create decks or note types), answers from its page-session memo on
    // re-opens and on send-seeded entries, and resolves null on ANY failure
    // — so an unreachable Anki quietly leaves the add-form icon. Keyed to
    // the loaded data like the fetch effect above; the cancelled guard
    // drops a late answer for a popup that has since moved to another word.
    useEffect(() => {
        if (!ankiSettings || !data) {
            return;
        }
        let cancelled = false;
        setAnkiExisting('checking');
        const language = data.language_code ?? null;
        findExistingNote(ankiWordSyncConfig(ankiSettings, language), {word: data.word, language}).then((noteId) => {
            if (!cancelled) {
                setAnkiExisting(noteId == null ? 'absent' : 'found');
            }
        });
        return () => {
            cancelled = true;
        };
    }, [ankiSettings, data]);

    useEffect(() => {
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                if (modelsOpenRef.current) {
                    return;
                }
                event.stopPropagation();
                onClose();
            }
        };
        const onMouseDown = (event) => {
            if (modelsOpenRef.current) {
                return;
            }
            if (ref.current && !ref.current.contains(event.target)) {
                onClose();
            }
        };

        document.addEventListener('keydown', onKeyDown, true);
        document.addEventListener('mousedown', onMouseDown);
        return () => {
            document.removeEventListener('keydown', onKeyDown, true);
            document.removeEventListener('mousedown', onMouseDown);
        };
    }, [onClose]);

    const changeProgress = (action) => {
        setBusy(true);
        const method = action === 'known' ? 'PATCH' : 'DELETE';
        fetch(`/words/${wordId}/progress`, {
            method,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(getCsrfToken() ? {'X-CSRF-TOKEN': getCsrfToken()} : {}),
            },
            body: method === 'PATCH' ? JSON.stringify({familiarity: FAMILIARITY_MAX}) : undefined,
        })
            .then(async (res) => {
                if (!res.ok) {
                    throw new Error(`${res.status}`);
                }
                onProgress(surface, action === 'known' ? FAMILIARITY_MAX : null);
            })
            .catch(() => setBusy(false));
    };

    // The per-word "Save to Anki" / "Update in Anki" send: the note is built
    // from the payload the popup already loaded — the same builder the Anki
    // tab's sample card uses, with the Translations field narrowed to the
    // user's native language (the shared auth prop carries its code), the
    // word's tags from the payload, and the popup's own UI-language strings
    // as the structured values' labels (counted summaries, "+N more…"
    // expander, "form of") — the same baking the tab does, so a card is
    // worded in the sender's language. The resolved fields map rides along
    // too: the body toggles (plus the `body` master toggle) shape the Body
    // value at SEND time — newly sent cards only (spec V2); the scalar
    // toggles stay template-side. The config is the WORD's, not the global
    // base: ankiWordSyncConfig resolves the language entry
    // (languages[code] ?? languages.default) — the same config the on-open
    // detection used, so send and check can never target different decks.
    // The send then goes find-before-add through lib/ankiSync.js: a fresh
    // word is added, a found note of OUR types (configured per-language or
    // legacy base) is refreshed in place — all thirteen values rebuilt, tags
    // replaced, id/deck/scheduling untouched — and only a foreign-model note
    // stops at "Already in Anki.". The ensure (deck / note type / template)
    // is memoized module state shared with the tab, so the first send of a
    // session auto-creates them and later sends skip straight to the
    // duplicate check. Failures land in `ankiSend` for the toolbar's inline
    // hint — never a silent no-op — and every completion first checks the
    // mirrored data ref: a popup that moved to another word never adopts the
    // previous word's result.
    const sendToAnki = () => {
        if (!ankiSettings || !data || ankiSend?.status === 'sending') {
            return;
        }
        const language = data.language_code ?? null;
        const config = ankiWordSyncConfig(ankiSettings, language);
        setAnkiSend({status: 'sending'});
        const tags = data.tags ?? [];
        const noteFields = buildNoteFields(data, {
            familiarity,
            tags,
            nativeLanguage,
            labels: uiLabels(t),
            fields: config.fields,
        });
        sendAnkiNote(config, noteFields, {tags, word: data.word}).then(
            (result) => {
                if (dataRef.current !== data) {
                    return;
                }
                const status = result.added ? 'added' : result.updated ? 'updated' : 'duplicate';
                setAnkiSend({status});
                if (result.added || result.updated) {
                    // Our note is in the deck now: flip the icon to its
                    // update form without a re-query — the sync layer also
                    // seeded the detection memo, so the next open answers
                    // from the memo too.
                    setAnkiExisting('found');
                }
            },
            (error) => {
                if (dataRef.current !== data) {
                    return;
                }
                setAnkiSend({status: 'error', error});
            },
        );
    };

    const explainProps = explain && explainKey
        ? {...explain, wordId, surface}
        : null;

    return createPortal(
        <div
            ref={ref}
            role="dialog"
            aria-label={data?.word ?? surface}
            style={{...popupStyle(rect, fontSize), fontSize: `${fontSize}px`}}
            className={`word-popup absolute z-50 ${popupContainerClass}`}
        >
            {(loading || error) && (
                <div className="p-3.5">
                    {loading && <p className="word-popup-loading text-[0.857em] opacity-60">{t('word.loading')}</p>}
                    {error && <p className="word-popup-error text-[0.857em] text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">{error}</p>}
                </div>
            )}

            {!loading && data && (
                <PopupContent
                    surface={surface}
                    familiarity={familiarity}
                    data={data}
                    explainProps={explainProps}
                    explainKey={explainKey}
                    sections={effectiveSections}
                    modelsOpen={modelsOpen}
                    onModelsOpenChange={setModelsOpen}
                    onProgressAction={changeProgress}
                    progressBusy={busy}
                    onAnkiSend={sendToAnki}
                    ankiSend={ankiSend}
                    ankiExisting={ankiExisting}
                />
            )}
        </div>,
        document.body,
    );
}
