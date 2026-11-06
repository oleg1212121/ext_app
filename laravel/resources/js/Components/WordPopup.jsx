import React, {useEffect, useLayoutEffect, useRef, useState} from 'react';
import {createPortal} from 'react-dom';
import {Link, usePage} from '@inertiajs/react';
import ModelsUsedPopup, {RobotHelpIcon} from './ModelsUsedPopup.jsx';
import {useI18n} from '../i18n';
import {isApprovedAdmin} from '../lib/authUser';
import {getCsrfToken} from '../lib/http';
import {renderMarkdown} from '../lib/markdown';
import {canAskExplanation} from '../lib/wordExplain';
import {dimsDefinition, dimsEntry, entryUsageChips, usageLabelChips} from '../lib/usageLabelDisplay';
import {FAMILIARITY_MAX, recordWordOpens} from '../lib/wordFamiliarity';
import {resolveWordId} from '../lib/wordLookup';
import {Icon} from './icons.jsx';
import {AnkiConnectError, ankiConnectEndpoint, markAnkiReachable, probeReachable, unmarkAnkiReachable} from '../lib/ankiConnect';
import {buildNoteFields, uiLabels} from '../lib/ankiTemplate';
import {AnkiNoteTypeMismatchError, findExistingNote, sendAnkiNote} from '../lib/ankiSync';
import {ankiWordSyncConfig} from '../lib/ankiWordConfig';
// The pure style helpers live in lib (word-card ticket 01) — the portal
// positions with popupStyle, the card surfaces wear popupContainerClass,
// and the info popup's viewport clamp shares the two margins.
import {DEFAULT_POPUP_FONT_SIZE, POPUP_MARGIN, VIEWPORT_MARGIN, popupContainerClass, popupStyle} from '../lib/popupStyle';

const TRANSLATIONS_PREVIEW = 8;
const TRANSCRIPTIONS_PREVIEW = 1;
// The info popup's viewport-clamp floor (word-popup-overhaul ticket 04):
// the space between the viewport's top edge and the card's top edge bounds
// the layer; this floor keeps a usable strip when a flipped card sits hard
// against the top (the layer scrolls internally from there).
const MIN_INFO_HEIGHT = 96;
// The Anki success notices' lifetime (word-popup-overhaul ticket 04): the
// info popup's only transient items — added / updated / duplicate dismiss
// themselves after ~4 seconds; errors stay sticky.
const ANKI_NOTICE_LIFETIME_MS = 4000;

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

// Action-toolbar icon buttons (the popup's first row). The glyphs carry no
// color of their own — these text classes drive the resting and hover states:
// each button passes a color token (verdigris / vermilion / amber / info, or
// the neutral default). An accent token paints the icon in that house hue
// with a matching faint hover wash, day and night; neutral rests at the old
// tab strip's reduced opacity. The class strings are full literals on
// purpose — Tailwind's scanner reads this file's text, so names built by
// concatenation would never compile.
const TOOLBAR_ACCENTS = {
    verdigris: 'text-[var(--color-verdigris)] hover:bg-[var(--color-verdigris)]/10 dark:text-[var(--color-verdigris-night)] dark:hover:bg-[var(--color-verdigris-night)]/10',
    vermilion: 'text-[var(--color-vermilion)] hover:bg-[var(--color-vermilion)]/10 dark:text-[var(--color-vermilion-night)] dark:hover:bg-[var(--color-vermilion-night)]/10',
    amber: 'text-[var(--color-amber)] hover:bg-[var(--color-amber)]/10 dark:text-[var(--color-amber-night)] dark:hover:bg-[var(--color-amber-night)]/10',
    info: 'text-[var(--color-info)] hover:bg-[var(--color-info)]/10 dark:text-[var(--color-info-night)] dark:hover:bg-[var(--color-info-night)]/10',
    neutral: 'opacity-80 hover:opacity-100',
};
// `pressed` is the Explanation toggle's shown state: the verdigris accent
// plus a persistent tint, so the icon reads as active while its pane is
// mounted — verdigris whatever the passed token (only the toggle presses,
// and it passes neutral).
const toolbarButtonClass = (color = 'neutral', pressed = false) => [
    'rounded-sm p-1 transition-[opacity,background-color]',
    pressed
        ? `${TOOLBAR_ACCENTS.verdigris} bg-[var(--color-verdigris)]/10 dark:bg-[var(--color-verdigris-night)]/10`
        : TOOLBAR_ACCENTS[color],
    'disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]',
].join(' ');

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
 * `empty` replaces the whole line when there is nothing to show — null
 * included, so a line without a fallback renders nothing at all.
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

    if (items.length === 0) {
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
 * The word popup's Context explanation pane, mounted on demand: asks the AI
 * what the word means in its sentence context (POST /ai/word-explain) the
 * moment the toolbar's Explanation icon first shows it — PopupContent mounts
 * this pane only while the toggle is on, so a []-deps effect fires the
 * request once per activation and never on re-renders. Keyless users
 * (enabled false) and users without a chosen explanation model (modelKey
 * null) see guidance instead of a request; a hit in the page-lifetime
 * explain cache renders the memoized state instantly and skips the fire — a
 * pending or errored entry suppresses it exactly like an answer does, so
 * re-showing the pane (after hiding it, or re-opening the same word) never
 * re-spends tokens or double-POSTs without a click. The model is the user's
 * stored explanation preference — the server resolves it; the popup only
 * carries `modelKey` to discriminate the client cache. `explainKey` also
 * guards against stale responses landing after the popup has moved to
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

    // Toggle-on == this pane mounting (PopupContent mounts ExplainPane only
    // while the Explanation icon shows it), so this []-deps effect runs
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
        <div className="word-popup-explain pb-1">
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
 * The ask-AI error state shared by both popup bodies — the dictionary card
 * and the missing card run the same runEnrich, so one render carries the
 * failure for both: the server's guidance verbatim, the explain pane's
 * go-to-settings link for the no-model (400) / no-key (403) answers, and
 * the Retry control the four-state contract requires of an error state —
 * it re-sends the ask through the same guarded callback the toolbar icon
 * uses. Rendered inside the info popup above the card (word-popup-overhaul
 * ticket 04) — sticky until dismissed, so it takes the shared per-item
 * dismiss control when a dismiss callback is passed.
 */
function AskAiError({message, status, onRetry = null, onDismiss = null}) {
    const {t} = useI18n();

    if (!message) {
        return null;
    }

    return (
        <div className="word-popup-ask-ai-error flex items-start gap-2 py-1.5 text-[0.857em] text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">
            <div className="min-w-0 flex-1">
                <p>{message}</p>
                {(status === 400 || status === 403) && (
                    <Link
                        href="/profile?tab=ai"
                        className="mt-1 inline-block underline underline-offset-2 hover:opacity-80"
                    >
                        {t('word.explain_go_to_settings')}
                    </Link>
                )}
                {onRetry != null && (
                    <button
                        type="button"
                        onClick={onRetry}
                        className={`word-popup-ask-ai-retry ${explainButtonClass} mt-1`}
                    >
                        {t('word.ask_ai_retry')}
                    </button>
                )}
            </div>
            {onDismiss != null && (
                <InfoDismissButton onClick={onDismiss}/>
            )}
        </div>
    );
}

/**
 * The info popup's per-item dismiss control (word-popup-overhaul ticket
 * 04): a small grey x in the toolbar's own button style. The sticky items —
 * the Anki error and the ask-AI error — each carry one; the success notices
 * auto-dismiss instead and take none.
 */
function InfoDismissButton({onClick}) {
    const {t} = useI18n();

    return (
        <button
            type="button"
            title={t('word.info_dismiss')}
            aria-label={t('word.info_dismiss')}
            onClick={onClick}
            className={`word-popup-info-dismiss ${toolbarButtonClass()} shrink-0`}
        >
            <Icon name="xMark" className="h-[1.25em] w-[1.25em]"/>
        </button>
    );
}

/**
 * The info popup (word-popup-overhaul ticket 04): every transient message
 * the word popup has to deliver — the Anki send outcomes, the ask-AI error,
 * the AI Context explanation while its toolbar toggle is on — floats in ONE
 * absolutely positioned layer ABOVE the card, so the card's content never
 * shifts when anything appears or disappears. The card surface is
 * overflow-hidden, so the layer cannot nest inside it: it renders as the
 * positioned wrapper's other child (the card surface's sibling),
 * bottom-anchored to the card's top edge with a small gap, spanning its
 * width, styled as the same card surface, viewport-clamped (a popup opened
 * near the top keeps it visible) and internally scrollable when tall.
 * Items stack in the ticket's listed order: the Anki notices (added /
 * updated / duplicate — auto-dismissed by the outer WordPopup after ~4s),
 * the sticky Anki error, the sticky ask-AI error (both carrying the shared
 * per-item dismiss control), the queued ask's transient "started" notice
 * (word-popup-overhaul ticket 06 — auto-dismissed like the Anki notices),
 * and the explanation slot PopupContent passes in behind its toggle gate.
 * With nothing to show the layer renders null — no box, no layout, no
 * shadow.
 */
function InfoPopup({ankiSend = null, onDismissAnki = null, aiStarted = false, aiError = null, aiErrorStatus = null, onRetryAskAi = null, onDismissAskAi = null, explainSlot = null}) {
    const {t} = useI18n();
    const layerRef = useRef(null);
    const [maxHeight, setMaxHeight] = useState(null);

    // The viewport clamp: the layer hangs above the card, so the space
    // between the viewport's top edge and the card's top edge bounds it.
    // parentElement is the positioned wrapper in both mounts (the portal's
    // absolute card wrapper and the profile preview's relative one — the
    // layer is always their direct child), and the rect's top already
    // accounts for the flip transform. Measured before paint (no unclamped
    // flash), re-measured on resize; the listener dies with the layer.
    useLayoutEffect(() => {
        const measure = () => {
            const parent = layerRef.current?.parentElement;
            if (!parent) {
                return;
            }
            const available = Math.floor(parent.getBoundingClientRect().top - POPUP_MARGIN - VIEWPORT_MARGIN);
            setMaxHeight((current) => {
                const next = Math.max(MIN_INFO_HEIGHT, available);
                return next === current ? current : next;
            });
        };
        measure();
        window.addEventListener('resize', measure);
        return () => window.removeEventListener('resize', measure);
    }, []);

    if (!explainSlot && aiError == null && !aiStarted
        && ankiSend?.status !== 'added'
        && ankiSend?.status !== 'updated'
        && ankiSend?.status !== 'duplicate'
        && ankiSend?.status !== 'error') {
        return null;
    }

    return (
        <div
            ref={layerRef}
            className={`word-popup-info absolute bottom-full left-0 right-0 z-10 mb-2 overflow-hidden rounded-md border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[#FDFBF5] dark:bg-[#1C1915] shadow-lg shadow-black/20 font-sans text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]`}
            style={maxHeight != null ? {maxHeight: `${maxHeight}px`} : undefined}
        >
            <div className="word-popup-info-items divide-y divide-[var(--color-hairline)] overflow-y-auto px-3.5 py-1.5 dark:divide-[var(--color-hairline-night)]">
                {ankiSend?.status === 'added' && (
                    <p className="word-popup-anki-added py-1.5 text-[0.857em] text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">
                        {t('word.anki_added')}
                    </p>
                )}
                {ankiSend?.status === 'updated' && (
                    <p className="word-popup-anki-updated py-1.5 text-[0.857em] text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">
                        {t('word.anki_updated')}
                    </p>
                )}
                {/* Reachable only when a FOREIGN-model note matched deck +
                    Word + Language: a note of our own types updates in place
                    and reports 'updated' above (lib/ankiSync.js). */}
                {ankiSend?.status === 'duplicate' && (
                    <p className="word-popup-anki-duplicate py-1.5 text-[0.857em] opacity-70">
                        {t('word.anki_already')}
                    </p>
                )}
                {ankiSend?.status === 'error' && (
                    <div className="word-popup-anki-error flex items-start gap-2 py-1.5 text-[0.857em] text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">
                        <div className="min-w-0 flex-1">
                            <p>
                                {ankiSend.error instanceof AnkiNoteTypeMismatchError
                                    ? t('word.anki_mismatch', {model: ankiSend.error.modelName})
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
                        {onDismissAnki != null && (
                            <InfoDismissButton onClick={onDismissAnki}/>
                        )}
                    </div>
                )}
                {/* A failed Ask-AI attempt: the server's guidance (no key,
                    no model, already asked, throttled, provider error) lands
                    verbatim — with the go-to-settings link for 400/403 and
                    the four-state contract's Retry control re-sending the
                    ask; sticky until dismissed, the icon stays retryable. */}
                <AskAiError message={aiError} status={aiErrorStatus} onRetry={onRetryAskAi} onDismiss={onDismissAskAi}/>
                {/* A QUEUED ask's dispatch notice (word-popup-overhaul
                    ticket 06): the enrichment job is off the HTTP request —
                    the content arrives on the next open, and the notice
                    says exactly that. Transient like the Anki success
                    notices: auto-dismissed by the outer WordPopup after
                    ~4s, no dismiss control; mutually exclusive with the
                    error above (a dispatch either queued or failed). */}
                {aiStarted && (
                    <p className="word-popup-ai-started py-1.5 text-[0.857em] text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">
                        {t('word.ask_ai_started')}
                    </p>
                )}
                {explainSlot && (
                    <div className="word-popup-explain-row py-1.5">
                        {explainSlot}
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * The missing-word card (popup-ai-enrich tickets 03 + 05; word-popup-overhaul
 * ticket 05): what an unmapped token opens — the raw clicked surface as the
 * title and an honest "not in the dictionary yet" note. There is no word id
 * behind it: nothing is fetched, no open penalty fires (a word that stays
 * missing costs nothing), and no familiarity / frequency / form-of lines
 * exist. The toolbar mirrors the real card's row in full — know / remove
 * mark, then pushed right: Anki, the Explanation toggle, Ask-AI, the robot —
 * with every inapplicable icon DISABLED, never hidden: there is no word
 * behind the card yet, so the progress and Anki handlers are structurally
 * absent (the outer WordPopup passes none — the same null-handler gates the
 * real card reads disable through them) and the Explanation toggle can
 * never enable (no word to attach an explanation to; the working pane lives
 * on the real card the ask swaps in). Two icons stay live: the robot (the
 * Models used popup needs no word) and Ask-AI wherever its existing gating
 * decides a valid language makes asking possible. The moment a word exists
 * — resolved on open, or adopted by an ask — this card is replaced by the
 * real `PopupContent` through the existing swap, and the icons go live
 * there; this component never gains the handlers itself. The Ask-AI ask
 * resolves the raw surface server-side (a lookup-key hit adopts onto the
 * existing row, a miss mints one word) and dispatches the queued
 * enrichment job — the card stays as it is and the minted word appears on
 * the next open (the on-open resolve finds it), so ask-AI remains the only
 * path from missing to populated without a live swap
 * (word-popup-overhaul ticket 06). A failed ask floats the server's guidance verbatim in the info
 * popup above the card (the shared InfoPopup layer, word-popup-overhaul
 * ticket 04) — the no-model and no-key cases carry the explain pane's
 * go-to-settings link, the Retry control re-sends the ask, and the sticky
 * line takes the shared per-item dismiss control. The structure mirrors the
 * real card (info popup / toolbar / header / dictionary, same hairlines and
 * stable `word-popup-*` hooks) so the card stays addressable from outside
 * styles the same way.
 */
export function MissingPopupContent({
    surface,
    sections = {},
    explainProps = null,
    modelsOpen = false,
    onModelsOpenChange = null,
    onProgressAction = null,
    onAnkiSend = null,
    // Ask-AI is admin-only (word-popup-overhaul ticket 06): false keeps
    // the sparkles icon out of the toolbar — hidden, not disabled — for
    // everyone but approved admins.
    canAskAi = false,
    onAskAi = null,
    aiBusy = false,
    // The queued ask's dispatch outcome (ticket 06): floats the transient
    // "AI enrichment started" notice — the missing card too stays as it is
    // after an ask; the minted word appears on the next open.
    aiStarted = false,
    aiError = null,
    aiErrorStatus = null,
    onDismissAskAi = null,
    resolving = false,
}) {
    const {t} = useI18n();

    // The same shared gate as the real card: the Explanation toggle and the
    // robot ride the explain payload + the ADR 0046 `explanation` key.
    const showExplain = explainProps != null && sections.explanation !== false;

    const askAiLabel = aiBusy ? t('word.ask_ai_sending') : t('word.ask_ai');

    return (
        <>
            {/* The info popup above the card: the missing card's only
                transient message is the ask-AI error — the Anki notices and
                the explanation pane cannot occur here (the card can send
                nothing and its toggle never enables). */}
            <InfoPopup
                aiStarted={aiStarted}
                aiError={aiError}
                aiErrorStatus={aiErrorStatus}
                onRetryAskAi={onAskAi}
                onDismissAskAi={onDismissAskAi}
            />

            <div className={`word-popup-card ${popupContainerClass} min-h-0 flex-1`}>
                {/* The action toolbar — the real card's row, mirrored in full:
                    know / remove mark on the left; pushed right, in order:
                    Anki, the Explanation toggle, the live Ask-AI icon, the
                    robot rightmost. Disabled, never hidden: no word behind
                    the card means no progress / Anki handler to act with
                    (the icons render inert through the same null-handler
                    gates the real card reads) and no explanation target —
                    but the robot stays live (the Models used popup needs no
                    word) and Ask-AI stays live wherever its gating decides
                    a valid language makes asking possible. */}
                <div className="word-popup-actions shrink-0 border-b border-[var(--color-hairline)] px-3.5 dark:border-[var(--color-hairline-night)]">
                    <div className="flex items-center gap-1 py-1.5">
                        {sections.progress_actions !== false && (
                            <button
                                type="button"
                                title={t('word.i_know_this')}
                                aria-label={t('word.i_know_this')}
                                disabled={onProgressAction == null}
                                className={`word-popup-progress-known ${toolbarButtonClass('verdigris')}`}
                            >
                                <Icon name="checkCircle" className="h-[1.25em] w-[1.25em]"/>
                            </button>
                        )}
                        {sections.progress_actions !== false && (
                            <button
                                type="button"
                                title={t('word.remove_mark')}
                                aria-label={t('word.remove_mark')}
                                disabled={onProgressAction == null}
                                className={`word-popup-progress-reset ${toolbarButtonClass('vermilion')}`}
                            >
                                <Icon name="minusCircle" className="h-[1.25em] w-[1.25em]"/>
                            </button>
                        )}
                        {/* The add form — no in-deck state exists to flip it
                            (there is no word to check the deck for), and no
                            hover probe: a send can never fire from this icon
                            until the real card replaces it. The margin-auto
                            push mirrors the real row's right-hand group. */}
                        <button
                            type="button"
                            title={t('word.anki_send')}
                            aria-label={t('word.anki_send')}
                            disabled={onAnkiSend == null}
                            className={`word-popup-anki-send ${toolbarButtonClass('neutral')} ml-auto`}
                        >
                            <Icon name="ankiAdd" className="h-[1.25em] w-[1.25em]"/>
                        </button>
                        {/* The Explanation toggle renders for toolbar parity
                            whenever an explain payload exists, but the missing
                            card can never explain — there is no word to attach
                            the pane to — so it is a plain disabled, never
                            pressed, never wired button. */}
                        {showExplain && (
                            <button
                                type="button"
                                title={t('word.explain_toggle')}
                                aria-label={t('word.explain_toggle')}
                                aria-pressed={false}
                                disabled
                                className={`word-popup-explain-toggle ${toolbarButtonClass('neutral')}`}
                            >
                                <Icon name="chatBubble" className="h-[1.25em] w-[1.25em]"/>
                            </button>
                        )}
                        {/* The live Ask-AI icon — the only path from missing
                            to populated. Disables while inapplicable: no
                            handler (a column without a language descriptor,
                            or the on-open resolve still in flight) or an ask
                            in flight; failed attempts stay retryable.
                            Admin-only (word-popup-overhaul ticket 06):
                            hidden — not disabled — for everyone but approved
                            admins, matching the endpoint's 403. */}
                        {canAskAi && (
                            <button
                                type="button"
                                title={askAiLabel}
                                aria-label={askAiLabel}
                                disabled={onAskAi == null || aiBusy}
                                onClick={onAskAi}
                                className={`word-popup-ask-ai ${toolbarButtonClass()}`}
                            >
                                <Icon name="sparkles" className="h-[1.25em] w-[1.25em]"/>
                            </button>
                        )}
                        {/* Not a toggle: opens the Models used popup. Rightmost
                            on the row and LIVE — the popup needs no word,
                            reading its models off the explain payload exactly
                            like the real card's robot. */}
                        {showExplain && (
                            <button
                                type="button"
                                onClick={() => onModelsOpenChange?.(true)}
                                title={t('ai.models_icon')}
                                aria-label={t('ai.models_icon')}
                                aria-haspopup="dialog"
                                className={`word-popup-models-button ${toolbarButtonClass('info')}`}
                            >
                                <RobotHelpIcon className="h-[1.25em] w-[1.25em]"/>
                            </button>
                        )}
                    </div>
                </div>

                {/* The header carries the raw clicked surface only — no
                    familiarity / frequency / form-of lines (there is no word
                    behind the card). */}
                <div className="word-popup-header shrink-0 border-b border-[var(--color-hairline)] px-3.5 pb-2.5 pt-3 dark:border-[var(--color-hairline-night)]">
                    <div className="flex items-baseline gap-2 flex-wrap">
                        <span className="word-popup-title font-reading text-[1.286em] leading-tight">{surface}</span>
                    </div>
                </div>

                <div className="word-popup-dictionary min-h-0 flex-1 overflow-y-auto px-3.5 pb-2.5">
                    {/* While the on-open resolve is in flight the body holds the
                        loading line instead of the not-in-dictionary note — the
                        note is a verdict, and pronouncing it before the resolve
                        answers reads as an empty popup that later swaps itself
                        (ADR 0096 amendment). A miss settles the note back in. */}
                    {resolving ? (
                        <p className="word-popup-loading mt-1 text-[0.929em] leading-snug opacity-70">{t('word.loading')}</p>
                    ) : (
                        <p className="word-popup-missing mt-1 text-[0.929em] leading-snug opacity-70">
                            {t('word.not_in_dictionary')}
                        </p>
                    )}
                </div>

                {/* The Models used popup — the robot's target, mounted behind
                    the same gate as the robot itself, fed from the explain
                    payload exactly like the real card's. While it is open it
                    owns Escape and outside-click closing; the word popup
                    underneath stays put. */}
                {showExplain && (
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
            </div>
        </>
    );
}

/**
 * The word popup's body — everything inside the box, minus the box itself:
 * the action toolbar (the card's first row, above the headword), the
 * headword header (familiarity, frequency, form-of lines) closed by a
 * hairline, and the dictionary sections of the word family — always
 * rendered, with no tab strip (popup-ai-enrich ticket 01). Shared verbatim
 * by the real portal popup and the profile's Popups-tab preview, so the
 * preview can never drift from the real thing.
 *
 * The toolbar hosts the actions as line-art icons — "I know this word" /
 * "Remove mark" on the left; pushed right, in order: "Save to Anki", the
 * on-demand Explanation toggle, the live Ask-AI icon (popup-ai-enrich
 * ticket 04 — one ask per word, ledger-locked on the server), and the robot
 * (Models used popup)
 * rightmost — with the transient Anki status lines and the ask-AI error
 * line (verbatim guidance + go-to-settings for 400/403 + the Retry
 * control, the shared AskAiError render) directly beneath. All
 * icons always render: an inapplicable icon is disabled, not hidden (know:
 * familiarity already max or busy; remove mark: no mark or busy; anki: no
 * handler or a send in flight; ask-ai: no handler, a request in flight, or
 * the word already asked) — except the Explanation toggle and the
 * robot, which only exist for popups with an explain payload (and vanish
 * with the ADR 0046 `explanation: false` key, as the old tab strip did).
 * Tooltips and aria-labels come from the existing word/ai ui-strings. The
 * title shows the clicked word only — each section carries the POS in its
 * "WORD — pos" heading. Per section, top to bottom: translations as the
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
 * removes the Explanation toggle, the robot and the Models-used popup —
 * the popup renders as it does for words without an explain payload.
 * `word_family` false filters the dictionary sections down to the
 * headword's own, dropping base-word sections. In the toolbar, only the two
 * progress icons answer to `progress_actions` — the right-hand icons ignore
 * the popup-visibility toggles.
 *
 * The Explanation toggle mounts the ExplainPane as the FIRST row of the
 * dictionary view (above all POS sections, behind the same hairline):
 * nothing loads until the icon's first click — pane mount is the auto-ask
 * effect's fire — and hiding keeps the page-lifetime cache, so re-showing
 * never refetches. While shown, the icon carries its pressed state.
 *
 * `onProgressAction` (null in the preview) drives the progress icons; they
 * render disabled without it. `preview` additionally swaps the Explanation
 * pane's auto-request for a canned answer (no network).
 *
 * The toolbar also carries the "Save to Anki" icon (`onAnkiSend` /
 * `ankiSend`, both null in the preview — the icon renders disabled there):
 * it sits OUTSIDE the `progress_actions` gate, so it shows on every surface
 * no matter which popup blocks are hidden. The icon carries the word's
 * in-deck state (`ankiExisting`: 'checking' | 'found' | 'absent' — the
 * on-open duplicate check's outcome, which only runs once the probe has
 * answered reachable): 'found' flips it to the update glyph with the
 * "Update in Anki" tooltip, every other value keeps the add form —
 * including a silent detection failure. Its color reads Anki's
 * reachability (`ankiReachable`, the outer WordPopup's on-open version
 * probe's outcome — amber while AnkiConnect answers, neutral grey while
 * pending / unreachable / the quiet unknown state) but its enabledness
 * does NOT: since the quiet-probe change (2026-10-24) the icon stays
 * clickable whatever the grey — a cold page session (no successful Anki
 * answer on record) keeps the on-open probe silent, and the reader's own
 * hover (`onAnkiProbe`) or click is how a running Anki gets
 * re-discovered. `ankiSend` holds the send's outcome
 * ({status: 'sending'|'added'|'updated'|'duplicate'|'error', error?}) for
 * the info popup's status line above the card (word-popup-overhaul ticket
 * 04); 'duplicate' is reachable only for a FOREIGN-model note — a note of
 * our own types updates in place (lib/ankiSync.js) and reports 'updated'
 * instead.
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
    // The info popup's dismiss controls (word-popup-overhaul ticket 04),
    // wired by the outer WordPopup to the message state it owns: the sticky
    // Anki error's button clears the send outcome, the sticky ask-AI
    // error's clears the failure. Null in the preview (message-free there,
    // so the controls never render anyway).
    onDismissAnki = null,
    onDismissAskAi = null,
    // The on-open reachability probe's outcome (popup-provenance ticket 05),
    // owned by the outer WordPopup — it needs the ankiSettings prop and the
    // loaded data to probe, so PopupContent only reads the answer: amber and
    // enabled while Anki is reachable; neutral grey otherwise (pending,
    // unreachable, or the quiet unknown state). Grey is COLOR only since the
    // quiet-probe change (2026-10-24) — the icon stays enabled, because a
    // cold session must be able to re-discover a running Anki through the
    // reader's own hover/click (onAnkiProbe). The default keeps the profile
    // preview's icon grey and probe-free (onAnkiProbe arrives null there).
    ankiReachable = false,
    // The resolved Anki settings, passed through from the outer WordPopup
    // (it owns the probe wiring; PopupContent only reads): the label's
    // no-settings case takes the start-Anki guidance, and the unknown
    // state's tooltip names the settings' endpoint. The null default keeps
    // the profile preview in the no-settings branch, same as before the
    // quiet-probe change.
    ankiSettings = null,
    // The quiet-probe fourth state's flag (the outer WordPopup's mirror of
    // lib/ankiConnect.js's session ledger): true while the page session has
    // heard nothing from Anki — read only when settings exist and Anki is
    // not reachable, so the preview (no settings) never touches it.
    ankiUnknown = true,
    // The reader-engaged probe (the outer WordPopup's probeAnkiNow): fires
    // ungated on the icon's hover so a cold session lights up in place the
    // moment the reader mouses over it; null in the preview — no probe.
    onAnkiProbe = null,
    // Ask-AI is admin-only (word-popup-overhaul ticket 06): the outer
    // WordPopup reads the shared auth user; false keeps the sparkles icon
    // (plain or re-ask) out of the toolbar entirely — hidden, not
    // disabled — for everyone else.
    canAskAi = false,
    onAskAi = null,
    aiBusy = false,
    // The queued ask's dispatch outcome (ticket 06): true for the ~4s
    // after a successful dispatch, floating the transient "AI enrichment
    // started" notice in the info popup — the payload itself is never
    // swapped on an ask anymore.
    aiStarted = false,
    aiError = null,
    aiErrorStatus = null,
    preview = false,
}) {
    const {t} = useI18n();
    // The Explanation pane starts hidden — nothing loads until the toolbar
    // icon's first click (pane mount == the auto-ask effect's fire).
    const [explainShown, setExplainShown] = useState(false);

    // A fresh payload (the popup moved to another word) hides the pane
    // again — the page-lifetime explain cache keeps its answer, so
    // re-opening the word and re-toggling never refetches.
    useEffect(() => {
        setExplainShown(false);
    }, [data]);

    // The Explanation toggle and the robot exist only for popups with an
    // explain payload, and the ADR 0046 `explanation: false` key removes
    // both (exactly as it once dropped the whole tab strip, Models-used
    // icon included).
    const showExplain = explainProps != null && sections.explanation !== false;
    // A payload without sentence identity (the AI-answer mount, ADR 0096
    // amendment) can mount no Context explanation — there is no sentence to
    // ask about — so its toggle renders disabled (grey, non-responsive) for
    // toolbar parity while the robot beside it stays live. ExplainPane's own
    // auto-ask already refuses such a payload, so this only formalizes it.
    const explainDisabled = explainProps != null && explainProps.sentenceId == null;
    const entries = sections.word_family === false
        ? (data.entries ?? []).filter((entry) => entryMatchesHeadword(entry, data.word))
        : data.entries;

    // The Anki icon's label, shared by its title and aria-label (written
    // once): a send in flight wins, then the reachability state — reachable
    // picks between the update and add forms; unknown (the session has
    // heard nothing from Anki yet — no successful probe or send on record)
    // names the endpoint and offers the click-as-check; a known-unreachable
    // endpoint (a real probe or send just failed) carries the start-Anki
    // guidance, as does the no-settings case.
    const ankiActionLabel = ankiSend?.status === 'sending'
        ? t('word.anki_sending')
        : (!ankiSettings
            ? t('word.anki_unreachable')
            : (ankiReachable
                ? (ankiExisting === 'found' ? t('word.anki_update') : t('word.anki_send'))
                : (ankiUnknown
                    ? t('word.anki_unknown', {endpoint: ankiConnectEndpoint(ankiSettings)})
                    : t('word.anki_unreachable'))));

    // The Ask-AI icon's label, shared by its title and aria-label: a word
    // that has already been enriched once reads "ask again" (the re-ask
    // variant's hint), a request in flight reads "sending", the default is
    // the plain Ask AI.
    const askAiLabel = data.ai_asked
        ? t('word.ask_ai_again')
        : (aiBusy ? t('word.ask_ai_sending') : t('word.ask_ai'));

    // The provenance chips (popup-provenance ticket 04; moved from the header
    // to the end of every section label, 2026-10-24; one bordered chip per
    // part since the same day's review): the importing writer plus the
    // word-level ai marker when a succeeded ask exists — a "wiktionary" chip
    // and an "ai" chip; "ai" alone for an enriched word no importer ever
    // stamped; no chips at all when neither is known. An
    // adopt-on-ask mint carries source 'ai' (ADR 0084) AND ai_asked true
    // from the same ask — the same fact twice — so the parts dedupe and a
    // single "ai" chip renders, never "ai · ai". Each importer string
    // renders verbatim in its own chip: the closed words.source vocabulary
    // is already human-readable, and a foreign value stays honest instead
    // of being mapped or dropped (ADR 0082's no-marker ruling is superseded
    // at word level only — per-row content stays unmarked).
    const sourceBadge = [...new Set([data.source, data.ai_asked ? 'ai' : ''].filter(Boolean))];

    return (
        <>
            {/* The info popup (word-popup-overhaul ticket 04): every
                transient message — the Anki send outcomes, the ask-AI
                error, the Explanation pane while its toggle is on — floats
                in ONE layer above the card, absolutely positioned inside
                the positioned wrapper (the card surface below is
                overflow-hidden, so the layer must be its sibling), so the
                card's content never shifts when anything appears or
                disappears. The wrapper is the layer's measuring context:
                parentElement in InfoPopup is this wrapper in both mounts
                (the portal and the profile preview). */}
            <InfoPopup
                ankiSend={ankiSend}
                onDismissAnki={onDismissAnki}
                aiStarted={aiStarted}
                aiError={aiError}
                aiErrorStatus={aiErrorStatus}
                onRetryAskAi={onAskAi}
                onDismissAskAi={onDismissAskAi}
                explainSlot={showExplain && explainShown && (
                    <ExplainPane explain={explainProps} explainKey={explainKey} preview={preview}/>
                )}
            />

            {/* The card surface — the overflow-hidden box every visible
                block lives in (word-popup-overhaul ticket 04 split the
                positioned wrapper from this surface so the info popup can
                hang above it unclipped). min-h-0 flex-1 lets the wrapper's
                maxHeight cap drive the dictionary's internal scroll; both
                are inert in the profile preview, whose wrapper is
                positioning-only and unconstrained. */}
            <div className={`word-popup-card ${popupContainerClass} min-h-0 flex-1`}>
                {/* The action toolbar — the card's first row, above the word:
                    ICONS ONLY. "I know this word" / "Remove mark" on the left;
                    pushed right, in order: "Save to Anki", the Explanation
                    toggle, the live Ask-AI icon, the robot (Models used popup)
                    rightmost. Icons always render: an inapplicable icon is
                    disabled, not hidden (know: familiarity already max or busy;
                    remove mark: no mark or busy; anki: no handler or a send in
                    flight; ask-ai: no handler or a request in flight — a word
                    already asked keeps the icon live as the re-ask variant).
                    The `progress_actions` visibility gate hides only the two
                    progress icons — the right-hand icons ignore the
                    popup-visibility toggles (spec). Everything the icons
                    trigger renders in the info popup ABOVE the card — the Anki
                    status lines, the ask-AI error, the Explanation pane — never
                    inside this strip (word-popup-overhaul ticket 04). */}
                <div className="word-popup-actions shrink-0 border-b border-[var(--color-hairline)] px-3.5 dark:border-[var(--color-hairline-night)]">
                    <div className="flex items-center gap-1 py-1.5">
                        {sections.progress_actions !== false && (
                            <button
                                type="button"
                                title={t('word.i_know_this')}
                                aria-label={t('word.i_know_this')}
                                disabled={progressBusy || onProgressAction == null || familiarity === FAMILIARITY_MAX}
                                onClick={() => onProgressAction?.('known')}
                                className={`word-popup-progress-known ${toolbarButtonClass('verdigris')}`}
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
                                className={`word-popup-progress-reset ${toolbarButtonClass('vermilion')}`}
                            >
                                <Icon name="minusCircle" className="h-[1.25em] w-[1.25em]"/>
                            </button>
                        )}
                        {/* The Anki icon carries the word's in-deck state:
                            'found' (the on-open check located the note) shows
                            the update glyph + "Update in Anki"; checking,
                            absent and a silent failure all keep the add form —
                            the send still runs find-before-add, so a misjudged
                            icon can never create a second card. Its color is
                            the reachability probe's answer (amber while
                            AnkiConnect answered, neutral grey otherwise) but
                            since 2026-10-24 the DISABLED gate no longer reads
                            it: with the on-open probe quiet until the session
                            has heard from Anki, a cold session's icon must
                            stay clickable or Anki could never be re-discovered
                            — hovering or clicking fires the ungated probe
                            (probeAnkiNow), and the click's send IS the check
                            (its find-before-add talks to AnkiConnect for real;
                            a network failure lands in the info popup above the
                            card). Disabled only by its own state: no handler
                            or a send in flight. The margin-auto push on this
                            first right-hand icon shoves it and the icons after
                            it to the row's right end. */}
                        <button
                            type="button"
                            title={ankiActionLabel}
                            aria-label={ankiActionLabel}
                            disabled={onAnkiSend == null || ankiSend?.status === 'sending'}
                            onClick={onAnkiSend}
                            onMouseEnter={onAnkiProbe ?? undefined}
                            className={`word-popup-anki-send ${toolbarButtonClass(ankiReachable ? 'amber' : 'neutral')} ml-auto`}
                        >
                            <Icon name={ankiExisting === 'found' ? 'ankiUpdate' : 'ankiAdd'} className="h-[1.25em] w-[1.25em]"/>
                        </button>
                        {/* The Explanation toggle (popup-ai-enrich ticket 01):
                            on demand — the first click mounts the ExplainPane in
                            the info popup above the card (word-popup-overhaul
                            ticket 04), a second click hides it (the page-lifetime
                            cache survives, so re-showing never refetches). While
                            shown, the icon reads as pressed. Exists only with an
                            explain payload and vanishes with the ADR 0046
                            `explanation: false` key, like the old tab strip. A
                            payload without sentence identity (the AI-answer
                            mount, ADR 0096 amendment) disables it — grey,
                            non-responsive — so toolbar parity never implies an
                            askable context. */}
                        {showExplain && (
                            <button
                                type="button"
                                title={t('word.explain_toggle')}
                                aria-label={t('word.explain_toggle')}
                                aria-pressed={explainShown}
                                disabled={explainDisabled}
                                onClick={() => setExplainShown((shown) => !shown)}
                                className={`word-popup-explain-toggle ${toolbarButtonClass('neutral', explainShown)}`}
                            >
                                <Icon name="chatBubble" className="h-[1.25em] w-[1.25em]"/>
                            </button>
                        )}
                        {/* The live Ask-AI icon (popup-ai-enrich ticket 04,
                            re-ask per the ADR 0082 amendment): one in-flight ask
                            at a time — a request in flight disables the icon,
                            failed attempts stay retryable (the error shows in
                            the info popup above the card, sticky until
                            dismissed). A word the AI already enriched once shows
                            the re-ask variant (sparkles inside the refresh
                            arrows) with the "ask again" hint and stays
                            clickable: the re-ask replaces the previous AI
                            rows. Admin-only (word-popup-overhaul ticket 06):
                            the icon renders for approved admins only —
                            hidden, not disabled, for everyone else, matching
                            the endpoint's 403. */}
                        {canAskAi && (
                            <button
                                type="button"
                                title={askAiLabel}
                                aria-label={askAiLabel}
                                disabled={onAskAi == null || aiBusy}
                                onClick={onAskAi}
                                className={`word-popup-ask-ai ${toolbarButtonClass()}`}
                            >
                                <Icon name={data.ai_asked ? 'sparklesRefresh' : 'sparkles'} className="h-[1.25em] w-[1.25em]"/>
                            </button>
                        )}
                        {/* Not a toggle: opens the Models used popup. Rightmost
                            on the row, behind the same gate as the Explanation
                            toggle (ADR 0046 `explanation: false` removes it,
                            exactly as it once dropped the tab strip's robot). */}
                        {showExplain && (
                            <button
                                type="button"
                                onClick={() => onModelsOpenChange?.(true)}
                                title={t('ai.models_icon')}
                                aria-label={t('ai.models_icon')}
                                aria-haspopup="dialog"
                                className={`word-popup-models-button ${toolbarButtonClass('info')}`}
                            >
                                <RobotHelpIcon className="h-[1.25em] w-[1.25em]"/>
                            </button>
                        )}
                    </div>
                </div>

                {/* The header block (word / familiarity / frequency / form-of),
                    closed by the hairline the POS sections separate themselves
                    with — the old tab strip's rule now belongs to the header
                    (popup-ai-enrich ticket 01). */}
                <div className="word-popup-header shrink-0 border-b border-[var(--color-hairline)] px-3.5 pb-2.5 pt-3 dark:border-[var(--color-hairline-night)]">
                    {/* Reading font: RU headwords carry U+0301, which gaps when
                        base and mark come from different fonts (see --font-reading). */}
                    <span className="word-popup-title font-reading text-[1.286em] leading-tight">{data.word}</span>

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

                {/* The dictionary view — always rendered (no tab strip), and
                    since word-popup-overhaul ticket 04 holding ONLY the word
                    family's sections: the Explanation pane moved up into the
                    info popup above the card. */}
                <div className="word-popup-dictionary min-h-0 flex-1 overflow-y-auto px-3.5 pb-2.5">
                    {entries?.map((entry, index) => (
                        <section
                            key={entry.id}
                            className={`word-popup-section${index > 0
                                ? ' mt-2.5 border-t border-[var(--color-hairline)] pt-2.5 dark:border-[var(--color-hairline-night)]'
                                : ''}${dimsEntry(entry)
                                ? ' word-popup-section-dimmed opacity-50 transition-opacity hover:opacity-100'
                                : ''}`}
                        >
                            {sectionLabel(entry, data.word) && (
                                // The word-level mark (popup-usage-labels
                                // follow-up) dims the WHOLE <section> — the dim
                                // branch in the section's className above carries
                                // word-popup-section-dimmed at half opacity over
                                // every block, hover restoring full opacity —
                                // while the header keeps its own base 60% and
                                // chips the mark after the text in the same tier
                                // styling as a sense's.
                                <h3 className="word-popup-section-label text-[0.786em] tracking-wider opacity-60">
                                    {sectionLabel(entry, data.word)}
                                    {entryUsageChips(entry, t).map((chip, chipIndex) => (
                                        <span
                                            key={chipIndex}
                                            className={chip.dimming
                                                ? 'word-popup-section-label-chip ml-1.5 inline-block rounded-sm border border-[var(--color-vermilion)]/40 px-1 text-[0.786em] leading-tight text-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)]/40 dark:text-[var(--color-vermilion-night)]'
                                                : 'word-popup-section-label-chip ml-1.5 text-[0.786em] opacity-60'}
                                        >
                                            {chip.text}
                                        </span>
                                    ))}
                                    {/* The provenance chips
                                        (popup-provenance ticket 04) — the
                                        header badge's new home: one small
                                        bordered label per source part trails
                                        every section label's very end
                                        ("DECIMATED — verb [wiktionary] [ai]"),
                                        where each reads as the form's data
                                        origin without crowding the headword.
                                        Hidden entirely when neither the
                                        importer nor a succeeded ask-AI is
                                        known — pre-provenance rows stay
                                        honest. */}
                                    {sourceBadge.map((part) => (
                                        <span
                                            key={part}
                                            className="word-popup-section-source ml-1.5 inline-block whitespace-nowrap rounded-sm border border-[var(--color-hairline)] px-1 text-[0.786em] leading-tight opacity-60 dark:border-[var(--color-hairline-night)]"
                                        >
                                            {part}
                                        </span>
                                    ))}
                                </h3>
                            )}

                            {sections.translations !== false && (
                                // Empty is normal here: the background
                                // auto-fetch (Yandex → Google) queues silently
                                // and only lands on the next open, so the card
                                // says so calmly instead of showing an error —
                                // but only while the raw line has nothing to
                                // show either (ticket 02): a word carrying
                                // staged candidates never reads as empty.
                                <React.Fragment>
                                    <CollapsibleLine
                                        items={entry.translations ?? []}
                                        previewCount={TRANSLATIONS_PREVIEW}
                                        separator=", "
                                        label="translations"
                                        renderItem={(translation) => translation.word}
                                        className="mt-2 text-[0.929em] leading-snug text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]"
                                        empty={(entry.staged_translations?.length ?? 0) > 0 ? null : (
                                            <p className="word-popup-translations-placeholder mt-2 text-[0.857em] opacity-60">
                                                {t('word.no_translations')}
                                            </p>
                                        )}
                                    />
                                    {(entry.staged_translations?.length ?? 0) > 0 && (
                                        // The staged raw candidates (the
                                        // words.translations JSON —
                                        // popup-provenance ticket 02, ADR 0085):
                                        // language-less as-is, amber, with the
                                        // muted raw: label and the line's own
                                        // "+N more…" expander. Popup-only — the
                                        // Anki fields keep the linked
                                        // translations.
                                        <CollapsibleLine
                                            items={entry.staged_translations}
                                            previewCount={TRANSLATIONS_PREVIEW}
                                            separator=", "
                                            label="raw-translations"
                                            renderItem={(candidate) => candidate}
                                            decorate={(text) => (
                                                <>
                                                    <span className="opacity-60">{t('word.raw_translations')}</span>
                                                    {' '}
                                                    {text}
                                                </>
                                            )}
                                            className="mt-1 text-[0.857em] leading-snug text-[var(--color-amber)] dark:text-[var(--color-amber-night)]"
                                        />
                                    )}
                                </React.Fragment>
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
                                        {/* Usage-label chips (popup-usage-labels):
                                            one per label before the sense text —
                                            vermilion for the dimming tier, muted
                                            plain text (the raw:-label precedent)
                                            for the register one; a live section's
                                            sense carrying any dimming label dims
                                            whole, hover restoring the line — but a
                                            sense inside an already-dimmed section
                                            skips the extra opacity, or the static
                                            stack would read 0.25 and hover would
                                            restore only the sense, not the section.
                                            Full literal class strings — Tailwind's
                                            scanner reads this file's text. */}
                                        {entry.definitions.map((definition, definitionIndex) => (
                                            <li
                                                key={definitionIndex}
                                                className={!dimsEntry(entry) && dimsDefinition(definition.labels)
                                                    ? 'word-popup-definition-dimmed opacity-50 transition-opacity hover:opacity-100'
                                                    : undefined}
                                            >
                                                {usageLabelChips(definition.labels, t).map((chip, chipIndex) => (
                                                    <span
                                                        key={chipIndex}
                                                        className={chip.dimming
                                                            ? 'word-popup-definition-label mr-1 inline-block rounded-sm border border-[var(--color-vermilion)]/40 px-1 text-[0.786em] leading-tight text-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)]/40 dark:text-[var(--color-vermilion-night)]'
                                                            : 'word-popup-definition-label mr-1 text-[0.786em] opacity-60'}
                                                    >
                                                        {chip.text}
                                                    </span>
                                                ))}
                                                {definition.text}
                                            </li>
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

                {/* The Models used popup — the robot's target, mounted behind the
                    same gate as the robot itself. While it is open it owns Escape
                    and outside-click closing; the word popup underneath stays
                    put. */}
                {showExplain && (
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
            </div>
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
 * A `wordId` of null is MISSING mode (popup-ai-enrich tickets 03 + 05): the
 * token had no word-map entry, so nothing is fetched and no word-events fire
 * — the card renders from the raw `surface` alone via MissingPopupContent
 * (honest not-in-the-dictionary note, the full toolbar with every wordless
 * icon disabled — word-popup-overhaul ticket 05 — and the live Ask-AI icon).
 * Its ask posts `{surface, language_code}` from the `language` prop — the
 * clicked column's descriptor language ({code, name}|null), or the AI-answer
 * panel's ask language ({code}|null, derived learning-first from the
 * resolution snapshot, `lib/aiAnswerTokens.mjs`'s `answerAskLanguage` —
 * ADR 0096's adopt-less rule reversed for that surface); a mount without a
 * code keeps the icon hidden for non-admins and disabled for admins. The
 * server derives the lookup key, adopts onto the existing row or mints one
 * unknown-class word, and dispatches the queued enrichment
 * (word-popup-overhaul ticket 06 — no payload comes back to swap): the
 * card stays missing until the popup reopens, when the on-open resolve
 * finds the minted/adopted word and the fetch effect loads its payload
 * (the handlers then act on the adopted `data.id` through resolvedWordId;
 * the page's word map learns the new word on its next refresh).
 *
 * With an `explain` payload ({rowKind, rowId, side, sentenceIndex, modelKey,
 * enabled, modelLabel, followsAnswer, answerLabel}) the popup grows the
 * toolbar's Explanation toggle: the first click mounts the AI context
 * explanation pane as the dictionary view's first row and immediately
 * requests it; without the payload the toggle (and the robot) do not render.
 * `modelKey` (the user's explanation model id) only discriminates the client
 * cache and decides between the auto-request and choose-a-model guidance —
 * the server resolves the actual model per user preference. The label fields
 * (`modelLabel`, `followsAnswer`, and the simulator-only `answerLabel`)
 * exist for the toolbar's robot icon, which opens the Models used popup;
 * `enabled` false (no API key) keeps the toggle and shows add-key guidance
 * instead of a request.
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
 * Beside it, an on-open reachability probe (the cheap AnkiConnect version
 * action, popup-provenance ticket 05) paints the icon's amber token while
 * Anki answers and keeps it grey while the probe is pending, when Anki is
 * unreachable, or when no settings exist — send outcomes refresh the
 * answer without a re-probe. The probe is QUIET for a cold page session
 * (2026-10-24): while no successful Anki answer is on record — a fresh
 * reload, Anki never up — opens ask the lib's session ledger instead of
 * the network, because the browser logs every refused fetch as a console
 * error; the reader's hover or click of the icon probes ungated and is
 * how a running Anki gets re-discovered mid-session.
 * The body itself lives in the exported PopupContent, which the profile's
 * Popups tab reuses for its live preview.
 */

// The missing-mode re-resolution POST (popup-ai-enrich ticket 07) lives in
// lib/wordLookup.js since ai-answer-words ticket 02: the sequential
// `resolveWordId` over an ordered language list — a single-code list
// ([language.code]) is the exact old postLookup contract — unit-tested in
// Node (the review pass unexported the single-code wrapper; no caller of
// its own).

export default function WordPopup({wordId, surface, familiarity, rect, onClose, onProgress, fontSize = DEFAULT_POPUP_FONT_SIZE, explain, sections, language = null, languageCodes = null}) {
    const {t} = useI18n();
    const {props} = usePage();
    const effectiveSections = sections ?? props.popupVisibility ?? {};
    const ankiSettings = props.ankiSettings ?? null;
    const nativeLanguage = props.auth?.user?.native_language ?? null;
    // Ask-AI is an approved-admin capability (word-popup-overhaul ticket
    // 06): the sparkles icon — plain and re-ask variants, dictionary and
    // missing cards alike — renders ONLY for approved admins (hidden, not
    // disabled, for everyone else; the endpoint answers the same gate with
    // a 403). The gate itself lives in the shared lib helper — the
    // profile's Popups-tab preview reads the same one.
    const canAskAi = isApprovedAdmin(props.auth?.user);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [ankiSend, setAnkiSend] = useState(null);
    // The ask-AI request state for the current word: `aiBusy` while the
    // enrichment POST is in flight (the icon disables), `aiError` the latest
    // failure's message (shown in the info popup above the card, sticky
    // until dismissed — the icon stays retryable), `aiErrorStatus` its HTTP
    // status (400/403 — no model / no key — switch the error line to the
    // explain pane's guidance treatment). A success answers the minimal
    // {queued: true} marker — the provider call runs in the queue now
    // (word-popup-overhaul ticket 06) — so the payload is NEVER swapped:
    // `aiStarted` floats the transient "enrichment started" notice instead,
    // and the enriched content appears the next time the popup opens.
    const [aiBusy, setAiBusy] = useState(false);
    const [aiError, setAiError] = useState(null);
    const [aiErrorStatus, setAiErrorStatus] = useState(null);
    const [aiStarted, setAiStarted] = useState(false);
    // The on-open re-resolution (popup-ai-enrich ticket 07): a missing-mode
    // popup with a column language descriptor resolves its RAW surface
    // server-side before the missing card settles — a hit means the word
    // exists (a previous adopt-on-ask minted it; the page's word map just
    // never learned it), so `lookedUpId` feeds the fetch effect below and
    // the loaded payload's ai_asked picks the re-ask icon variant. A null (a
    // genuine
    // miss) — or a failed lookup — leaves ticket 03's missing card exactly
    // as it was. `lookupBusy` holds the card's ask icon while the resolve
    // is in flight.
    const [lookedUpId, setLookedUpId] = useState(null);
    const [lookupBusy, setLookupBusy] = useState(false);
    // The on-open duplicate check's outcome for the current word:
    // 'checking' while the findNotes round-trip is in flight (the icon
    // stays in add form — no spinner), 'found' once the word sits in its
    // language's deck (the icon flips to the update glyph), 'absent' when
    // it does not — or when Anki was unreachable: the sync layer collapses
    // "not found" and "couldn't ask" into one null, and a send still runs
    // its own find-before-add, so a misjudged icon can never create a
    // second card.
    const [ankiExisting, setAnkiExisting] = useState('checking');
    // The on-open reachability probe's outcome (popup-provenance ticket 05):
    // whether AnkiConnect answered its cheap version action for the current
    // open — amber for the Anki icon while true, grey otherwise (pending,
    // unreachable, no settings). A browser-side fact only: no backend flag,
    // re-probed per open so starting Anki mid-session is picked up on the
    // next popup.
    const [ankiReachable, setAnkiReachable] = useState(false);
    // The quiet-probe fourth state (2026-10-24): has THIS page session ever
    // heard a successful answer from Anki? A fresh page starts true —
    // nothing is on record — and while it stays true the on-open probe goes
    // quiet (lib/ankiConnect.js's onlyIfKnown gate): with Anki down a cold
    // page opening popups logs ZERO refused requests instead of one per
    // 30s window. The first reader engagement with the icon (hover or the
    // click) probes for real and flips this false whatever the answer —
    // from then on the session is either reachable (successes don't log)
    // or known-unreachable (failures memoized 30s there), and opens probe
    // again either way. A completed send also records a success.
    const [ankiUnknown, setAnkiUnknown] = useState(true);
    // Mirrors `data` on every render; the send's completion paths compare
    // the data they started with against it, so a late result for a
    // previous word never lands on the current one (the explainKey
    // precedent in ExplainPane).
    const dataRef = useRef(null);
    dataRef.current = data;
    // Mirrors the popup's open origin next to dataRef: the missing card's
    // adoption ask starts from data === null, so the data mirror alone
    // cannot tell "still on the missing token" from "the popup moved on"
    // (the fetch effect nulls data for the new word too) — the word id,
    // surface and column language can.
    const originRef = useRef(null);
    originRef.current = {wordId, surface, languageCode: language?.code ?? null};
    // The word identity the popup's handlers act on: the bound word id for a
    // mapped token, the adopted payload's id once the missing card's ask
    // swapped in (wordId stays null there — the page's word map only learns
    // the new word on its next refresh).
    const resolvedWordId = wordId ?? data?.id ?? null;
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
        // Missing mode (popup-ai-enrich ticket 03): no word id — nothing to
        // fetch and no payload state to reset; the card renders from the
        // surface alone. No GET /words/ may fire for a token without an id
        // — unless the on-open lookup resolved one (popup-ai-enrich ticket
        // 07): fetchId is then the word the surface re-resolved to,
        // fetched exactly like a mapped token so the payload's ai_asked
        // picks the toolbar's re-ask icon variant. The early return still
        // clears the
        // previous word's payload state — the missing-card branch keys on
        // data == null, so a popup that moves here from a loaded token
        // without unmounting must not keep painting that token's
        // dictionary.
        const fetchId = wordId ?? lookedUpId;
        if (fetchId == null) {
            setLoading(false);
            setError(null);
            setData(null);
            setAiStarted(false);
            return;
        }
        let cancelled = false;
        setLoading(true);
        setError(null);
        setData(null);
        setAnkiSend(null);
        // The new word starts undetected — the previous word's 'found' must
        // never paint onto it (the detection effect below re-asserts
        // 'checking' when it runs for the loaded data).
        setAnkiExisting('checking');
        // A fresh word also clears the previous word's ask state — an
        // in-flight enrichment for the old word is dropped by its
        // staleAsk guard below, so the flag never sticks, and the queued
        // ask's "started" notice belongs to the word it was asked on.
        setAiBusy(false);
        setAiError(null);
        setAiErrorStatus(null);
        setAiStarted(false);

        fetch(`/words/${fetchId}?surface=${encodeURIComponent(surface)}`, {
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
    }, [wordId, lookedUpId, surface, t]);

    // The Anki success notices' auto-dismiss (word-popup-overhaul ticket
    // 04): added / updated / duplicate are the info popup's only transient
    // items — ~4 seconds after a send lands its notice dismisses itself.
    // Errors are deliberately outside this gate: they stay sticky until
    // their per-item dismiss control (or the word change / popup close
    // below). The effect's cleanup is the timing guard: ANY change to
    // `ankiSend` — a new send, a manual dismiss, the fetch effect's
    // word-change reset, an unmount — tears the pending timer down before
    // it can fire, so a notice can never outlive its word or swallow a
    // newer one's state.
    useEffect(() => {
        if (ankiSend == null
            || (ankiSend.status !== 'added' && ankiSend.status !== 'updated'
                && ankiSend.status !== 'duplicate')) {
            return;
        }
        const timer = setTimeout(() => setAnkiSend(null), ANKI_NOTICE_LIFETIME_MS);
        return () => clearTimeout(timer);
    }, [ankiSend]);

    // The queued ask-AI's "started" notice auto-dismiss (word-popup-overhaul
    // ticket 06): a successful dispatch floats the transient notice through
    // the same ~4s gate as the Anki success notices — the same timing guard
    // applies: ANY `aiStarted` change (a newer ask, the fetch effect's
    // word-change reset, an unmount) tears the pending timer down before it
    // can fire.
    useEffect(() => {
        if (!aiStarted) {
            return;
        }
        const timer = setTimeout(() => setAiStarted(false), ANKI_NOTICE_LIFETIME_MS);
        return () => clearTimeout(timer);
    }, [aiStarted]);

    // The resolve list (ai-answer-words ticket 02), keyed as a primitive
    // string: the caller's ordered codes when a list is passed (ticket 03's
    // AI-answer mount — learning first), otherwise the clicked column's
    // single language, the exact pre-list behavior. Codes are locale tags
    // and never contain '|', so the join/split round-trip is lossless — and
    // keying on the string, not the array reference, keeps an inline
    // `languageCodes={[a, b]}` prop (a fresh array every parent render)
    // from re-triggering the effect below.
    const lookupCodesKey = languageCodes != null
        ? (languageCodes.join('|') || null)
        : (language?.code ?? null);

    // The on-open re-resolution (popup-ai-enrich ticket 07): in missing
    // mode the popup resolves its RAW surface server-side once per open —
    // the minted word a previous adopt-on-ask left behind re-links to the
    // page (`entities:enrich`, the scheduler dev never runs, is the only
    // other linker). Since ai-answer-words ticket 02 the resolve runs
    // through lib/wordLookup.js's sequential resolver: codes in list order,
    // the first hit wins (later codes never fire), a miss falls through,
    // and any transport failure collapses the whole sequence to null — the
    // single-code contract the popup always had. A hit flips the popup into
    // known mode through the fetch effect above; a null (all codes missed,
    // or the resolve failed) leaves the missing card. The cancelled guard
    // mirrors the fetch effect's and spans the whole sequence: a popup
    // closed or moved to another surface drops the late answer instead of
    // resolving the wrong token. `lookupBusy` holds the card's ask icon
    // until the sequence settles either way.
    useEffect(() => {
        setLookedUpId(null);
        if (wordId != null || lookupCodesKey == null) {
            return;
        }
        let cancelled = false;
        setLookupBusy(true);
        resolveWordId(surface, lookupCodesKey.split('|')).then((found) => {
            if (!cancelled) {
                setLookupBusy(false);
                setLookedUpId(found);
            }
        });
        return () => {
            cancelled = true;
        };
    }, [wordId, surface, lookupCodesKey]);

    // The popup-open penalty (word-popup-overhaul ticket 01): a missing-mode
    // popup whose surface RESOLVED to a dictionary entry — this instance's
    // on-open lookup, or the 409 sync's re-resolve (both land through
    // setLookedUpId) — costs −5 familiarity ONCE per popup instance, ledger-
    // free (the server dedupes nothing; repeat opens are fresh instances
    // and apply again). Keyed on the resolved id alone: a miss (null) never
    // fires — a word that stays missing costs nothing — and a bound wordId
    // never fires either (lookedUpId stays null; WordText posts that open's
    // penalty itself). Best-effort like every event POST: a failure leaves
    // the header at the stale score.
    const [resolvedFamiliarity, setResolvedFamiliarity] = useState(null);
    useEffect(() => {
        if (lookedUpId == null) {
            setResolvedFamiliarity(null);
            return;
        }
        let cancelled = false;
        recordWordOpens([lookedUpId]).then((familiarity) => {
            if (!cancelled && familiarity[lookedUpId] !== undefined) {
                setResolvedFamiliarity(familiarity[lookedUpId]);
            }
        });
        return () => {
            cancelled = true;
        };
    }, [lookedUpId]);

    // The header's familiarity score: the caller's open-time value,
    // overridden by the popup-open penalty's response once it lands — the
    // same slot the columns patch through their own response handling
    // (WordText), so both surfaces read one value; the Anki card's
    // Familiarity field reports it too.
    const effectiveFamiliarity = resolvedFamiliarity ?? familiarity ?? null;

    // On-open duplicate detection (anki-popup-refresh): the popup silently
    // asks Anki whether this word is already saved — scoped to the word's
    // language deck + Word + Language through lib/ankiSync.js's
    // findExistingNote, which never runs the ensure (detection must not
    // create decks or note types), answers from its page-session memo on
    // re-opens and on send-seeded entries, and resolves null on ANY failure
    // — so an unreachable Anki quietly leaves the add-form icon. The check
    // only runs once the reachability probe has answered reachable
    // (2026-10-24): with Anki off it never fires, so a popup open costs no
    // findNotes request at all. Keyed to the loaded data like the fetch
    // effect above; the cancelled guard drops a late answer for a popup
    // that has since moved to another word.
    useEffect(() => {
        if (!ankiReachable || !ankiSettings || !data) {
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
    }, [ankiReachable, ankiSettings, data]);

    // On-open reachability probe (popup-provenance ticket 05): beside the
    // duplicate check it shares the open lifecycle with — same
    // [ankiSettings, data] key, same cancelled guard — the popup asks the
    // user's AnkiConnect endpoint its cheap version action through
    // lib/ankiConnect.js's probeReachable. Quiet until the session has
    // heard from Anki (2026-10-24): the probe runs with {onlyIfKnown:
    // true}, and the lib's page-session success ledger answers null
    // WITHOUT a request while no success is on record — the browser logs
    // every refused fetch as a console error, so with Anki down a cold
    // page opening popups stays silent instead of erroring once per 30s
    // window. A null answer (quiet) and a rejection (known-unreachable,
    // failures memoized 30s there) both keep the icon grey; a resolve
    // marks it reachable — the Save-to-Anki icon paints its amber token
    // and the duplicate check fires. The flag starts cleared — whatever a
    // previous open proved, the current probe answers for itself — and
    // re-runs per open (the key moves with every loaded payload); the
    // reader's hover/click of the icon probes ungated (probeAnkiNow
    // below), so a cold session learns Anki came up without a reload. A
    // late answer for a popup that has moved to another word is dropped.
    useEffect(() => {
        if (!ankiSettings || !data) {
            return;
        }
        let cancelled = false;
        setAnkiReachable(false);
        probeReachable(ankiSettings, {onlyIfKnown: true}).then(
            (answer) => {
                if (!cancelled) {
                    setAnkiReachable(answer != null);
                }
            },
            () => {
                if (!cancelled) {
                    setAnkiReachable(false);
                }
            },
        );
        return () => {
            cancelled = true;
        };
    }, [ankiSettings, data]);

    // The reader-engaged probe (2026-10-24): hovering the grey Anki icon —
    // or clicking it — asks the endpoint ungated, whatever the session
    // ledger holds. A resolve marks the session reachable (the ledger
    // records the success, so later opens probe again) and lights the
    // icon amber in place; a rejection makes unreachability KNOWN — the
    // unknown flag drops and the tooltip switches to the start-Anki
    // guidance (failures stay memoized 30s in the lib, so mousing over
    // the icon costs at most one refused request per window). A click
    // runs this first, then sendToAnki's find-before-add talks to Anki
    // for real — the send is its own reachability proof either way.
    const probeAnkiNow = () => {
        if (!ankiSettings) {
            return;
        }
        probeReachable(ankiSettings).then(
            () => {
                setAnkiReachable(true);
                setAnkiUnknown(false);
            },
            () => {
                setAnkiReachable(false);
                setAnkiUnknown(false);
            },
        );
    };

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
        fetch(`/words/${resolvedWordId}/progress`, {
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

    // The icon-click handler (2026-10-24): the click is also the check —
    // the ungated probe fires first (learning Anki came up lights the icon
    // amber in place), then the send's find-before-add talks to Anki
    // for real. With Anki off the send fails its first request and lands
    // in the inline error line — never a doomed multi-request cascade,
    // because the send's own findExistingNote hits the same refused
    // endpoint once and the sync layer collapses the failure to null
    // (find-before-add) before addNote is even attempted... whose failure
    // is the same single refused endpoint. The hover half lives in
    // probeAnkiNow, passed down as the icon's onAnkiProbe.
    const ankiIconClick = () => {
        probeAnkiNow();
        sendToAnki();
    };

    // The info popup's dismiss controls (word-popup-overhaul ticket 04): the
    // sticky items — the Anki error and the ask-AI error — clear their own
    // message state on click; everything else (the success notices'
    // auto-dismiss timer, the word change reset, the popup close unmount)
    // keeps clearing through the same state.
    const dismissAnkiStatus = () => setAnkiSend(null);
    const dismissAskAiError = () => {
        setAiError(null);
        setAiErrorStatus(null);
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
    // duplicate check. Failures land in `ankiSend` for the info popup above
    // the card — never a silent no-op — and every completion first checks
    // the mirrored data ref: a popup that moved to another word never
    // adopts the previous word's result.
    const sendToAnki = () => {
        if (!ankiSettings || !data || ankiSend?.status === 'sending') {
            return;
        }
        const language = data.language_code ?? null;
        const config = ankiWordSyncConfig(ankiSettings, language);
        setAnkiSend({status: 'sending'});
        const tags = data.tags ?? [];
        const noteFields = buildNoteFields(data, {
            familiarity: effectiveFamiliarity,
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
                // A completed send is itself reachability evidence — Anki
                // answered every action, an added / updated note and a
                // foreign-model duplicate alike — so the session ledger
                // records the success (later opens probe for real again)
                // and the icon flips amber without a re-probe.
                markAnkiReachable(ankiSettings);
                setAnkiUnknown(false);
                setAnkiReachable(true);
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
                // A network-kind failure means Anki never answered — the
                // session ledger drops the endpoint (later opens go quiet
                // again) and the icon turns grey, now KNOWN-unreachable
                // (the tooltip switches to the start-Anki guidance). An
                // anki-kind failure means AnkiConnect answered with an
                // error string and a note-type mismatch means the
                // find-before-add talked to it: both keep the icon amber.
                if (error instanceof AnkiConnectError && error.kind === 'network') {
                    unmarkAnkiReachable(ankiSettings);
                    setAnkiUnknown(false);
                    setAnkiReachable(false);
                }
                setAnkiSend({status: 'error', error});
            },
        );
    };

    // The per-word Ask-AI send (popup-ai-enrich tickets 04 + 05;
    // word-popup-overhaul ticket 06 queued it): one POST to
    // /ai/word-enrich. The response is the minimal {queued: true} marker —
    // the provider call runs in the queue — so the success path NEVER
    // swaps the payload: it floats the transient "AI enrichment started"
    // notice through the info popup, and the enriched content appears the
    // next time the popup opens (the icon's ai_asked flips then too — the
    // re-ask variant picks it up from the fresh payload, never live). A
    // failed JOB is silent client-side: nothing to wire, the ledger just
    // stays failed and the icon retryable. Both completion paths check the
    // ask's origin first (see staleAsk): a late result for a popup that
    // has moved to another token is dropped, and the fetch effect's reset
    // clears the in-flight flag and the notice for the new word.
    // Failures of the dispatch itself land in `aiError` in the info popup
    // above the card — the server's guidance verbatim (no key, no model,
    // already asked/in flight, throttled, non-admin), sticky until
    // dismissed.
    const staleAsk = (origin) => dataRef.current !== origin.data
        || originRef.current.wordId !== origin.wordId
        || originRef.current.surface !== origin.surface
        || originRef.current.languageCode !== origin.languageCode;

    const runEnrich = (body, origin) => {
        setAiBusy(true);
        setAiError(null);
        setAiErrorStatus(null);
        fetch('/ai/word-enrich', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(getCsrfToken() ? {'X-CSRF-TOKEN': getCsrfToken()} : {}),
            },
            body: JSON.stringify(body),
        })
            .then(async (res) => {
                const json = await res.json().catch(() => null);
                if (!res.ok) {
                    // The status rides along: 400/403 (no model / no key)
                    // switch the missing card's error line to the explain
                    // pane's guidance treatment.
                    const error = new Error(
                        json?.error
                            ?? json?.message
                            ?? t('word.ask_ai_failed', {status: res.status}),
                    );
                    error.status = res.status;
                    throw error;
                }

                return json;
            })
            .then((json) => {
                if (staleAsk(origin)) {
                    return;
                }
                // Queued: the ask is dispatched, the content arrives on the
                // next open — the notice says so, the card keeps its
                // current content (no live swap).
                setAiBusy(false);
                setAiErrorStatus(null);
                setAiStarted(true);
            })
            .catch((e) => {
                if (staleAsk(origin)) {
                    return;
                }
                setAiBusy(false);
                setAiErrorStatus(e.status ?? null);
                setAiError(e instanceof Error && e.message
                    ? e.message
                    : t('word.ask_ai_failed', {status: '?'}));

                // The 409 sync: the only enrich 409 left (ADR 0082
                // amendment — the completed lock is gone) is the "in
                // progress" gate, meaning another tab/tab-window's ask is
                // mid-flight. Refetching the payload is a harmless sync —
                // ai_asked stays false until the winner's ask succeeds (a
                // popup that was already re-ask-able just stays that way) —
                // and it future-proofs the icon against any payload drift
                // while the request ran. The missing card has no id to
                // refetch (the adopt-on-ask 409 carries no payload), so its
                // lookup re-resolves the adopted word and the fetch effect
                // loads the resulting payload.
                if (e.status === 409) {
                    if (origin.data?.id != null) {
                        fetch(`/words/${origin.data.id}?surface=${encodeURIComponent(surface)}`, {
                            headers: {Accept: 'application/json'},
                        })
                            .then(async (res) => (res.ok ? res.json() : null))
                            .then((json) => {
                                if (json && !staleAsk(origin)) {
                                    setData(json.data);
                                }
                            })
                            .catch(() => {});
                    } else if (language?.code != null) {
                        // The single code the ask itself carried — the ask
                        // flow stays keyed on the column language; the
                        // ordered list never widens it.
                        resolveWordId(surface, [language.code]).then((found) => {
                            if (found != null && !staleAsk(origin)) {
                                setLookedUpId(found);
                            }
                        });
                    }
                }
            });
    };

    // The known-word ask / re-ask (ticket 04, re-ask per the ADR 0082
    // amendment): the BOUND word id (data.id — the same id the server's
    // ledger tracks and the payload's ai_asked reports) plus the popup's
    // current surface. The queued flow answers {queued: true} without a
    // payload, so the surface matters only if the dispatch itself 409s —
    // the refetch sync below reuses it.
    const askAi = () => {
        if (!data || aiBusy) {
            return;
        }
        runEnrich({word_id: data.id, surface}, {data, wordId, surface, languageCode: language?.code ?? null});
    };

    // The missing card's adopt-on-ask (ticket 05): the RAW clicked surface
    // plus the clicked column's language code — the server derives the
    // lookup key (never trusting the title), adopts onto the existing row
    // or mints one, and dispatches the queued job. The card stays missing
    // until the popup reopens: the on-open resolve then finds the minted
    // word and the fetch effect loads its payload (the response carries no
    // payload to swap anymore). A column without a language descriptor
    // keeps the icon hidden for non-admins and disabled here for admins:
    // there is nothing valid to ask with.
    const askAiMissing = () => {
        if (data != null || aiBusy || language?.code == null) {
            return;
        }
        runEnrich({surface, language_code: language.code}, {data: null, wordId, surface, languageCode: language.code});
    };

    const explainProps = explain && explainKey
        ? {...explain, wordId: resolvedWordId, surface}
        : null;

    return createPortal(
        // The POSITIONED WRAPPER (word-popup-overhaul ticket 04): positioning
        // only — popupStyle's box, the dialog semantics and the close
        // handlers' `ref` — while the card surface itself lives one level
        // down (PopupContent / MissingPopupContent render it). The split
        // gives the info popup layer a home beside the overflow-hidden
        // card surface: a child of THIS wrapper is never clipped by it.
        <div
            ref={ref}
            role="dialog"
            aria-label={data?.word ?? surface}
            style={{...popupStyle(rect, fontSize), fontSize: `${fontSize}px`}}
            className="word-popup absolute z-50 flex flex-col"
        >
            {wordId == null && lookedUpId == null && data == null ? (
                // Missing mode (popup-ai-enrich ticket 03) until the
                // on-open lookup resolves the adopted word a queued ask
                // minted (ticket 05's ask dispatches the job now — no
                // payload comes back to swap — and ticket 07's resolve
                // links it on the next open): the unmapped token's card —
                // raw surface, honest not-in-dictionary note, the full
                // toolbar (word-popup-overhaul ticket 05) with every
                // wordless icon disabled. No fetch, no open penalty (a
                // word that stays missing costs nothing), no
                // familiarity/frequency/form-of, and no progress/Anki
                // handlers passed down — their structural absence is what
                // disables those icons — while the robot (Models used)
                // stays live and Ask-AI renders for approved admins only
                // (word-popup-overhaul ticket 06). Ask-AI is still the only
                // path from missing to populated — by way of the reopen.
                <MissingPopupContent
                    surface={surface}
                    sections={effectiveSections}
                    explainProps={explainProps}
                    modelsOpen={modelsOpen}
                    onModelsOpenChange={setModelsOpen}
                    canAskAi={canAskAi}
                    onAskAi={canAskAi && language?.code != null && !lookupBusy ? askAiMissing : null}
                    aiBusy={aiBusy}
                    aiStarted={aiStarted}
                    aiError={aiError}
                    aiErrorStatus={aiErrorStatus}
                    onDismissAskAi={dismissAskAiError}
                    resolving={lookupBusy}
                />
            ) : (
                <>
                    {(loading || error) && data == null && (
                        // The pre-payload card (loading / load error): its own
                        // card surface, same as the loaded views around it.
                        <div className={`word-popup-card ${popupContainerClass} min-h-0 flex-1`}>
                            <div className="p-3.5">
                                {loading && <p className="word-popup-loading text-[0.857em] opacity-60">{t('word.loading')}</p>}
                                {error && <p className="word-popup-error text-[0.857em] text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">{error}</p>}
                            </div>
                        </div>
                    )}

                    {data && (
                        <PopupContent
                            surface={surface}
                            familiarity={effectiveFamiliarity}
                            data={data}
                            explainProps={explainProps}
                            explainKey={explainKey}
                            sections={effectiveSections}
                            modelsOpen={modelsOpen}
                            onModelsOpenChange={setModelsOpen}
                            onProgressAction={changeProgress}
                            progressBusy={busy}
                            onAnkiSend={ankiIconClick}
                            ankiSend={ankiSend}
                            ankiExisting={ankiExisting}
                            ankiReachable={ankiReachable}
                            ankiSettings={ankiSettings}
                            ankiUnknown={ankiUnknown}
                            onAnkiProbe={probeAnkiNow}
                            canAskAi={canAskAi}
                            onAskAi={canAskAi ? askAi : null}
                            aiBusy={aiBusy}
                            aiStarted={aiStarted}
                            aiError={aiError}
                            aiErrorStatus={aiErrorStatus}
                            onDismissAnki={dismissAnkiStatus}
                            onDismissAskAi={dismissAskAiError}
                        />
                    )}
                </>
            )}
        </div>,
        document.body,
    );
}
