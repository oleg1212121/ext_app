import {useLayoutEffect, useRef, useState} from 'react';
import {Link} from '@inertiajs/react';
import {useI18n} from '../i18n';
import {AnkiConnectError} from '../lib/ankiConnect';
import {AnkiNoteTypeMismatchError} from '../lib/ankiSync';
import {POPUP_MARGIN, VIEWPORT_MARGIN} from '../lib/popupStyle';
// The ask-AI error and the per-item dismiss control it shares with the Anki
// error line below (word-card ticket 03: flat siblings, the helper beside
// the component that mounts it — imported one way, so no module cycle).
import {AskAiError, InfoDismissButton} from './AskAiError.jsx';

// The info popup's viewport-clamp floor (word-popup-overhaul ticket 04):
// the space between the viewport's top edge and the card's top edge bounds
// the layer; this floor keeps a usable strip when a flipped card sits hard
// against the top (the layer scrolls internally from there).
const MIN_INFO_HEIGHT = 96;

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
 * and the explanation slot WordCard passes in behind its toggle gate.
 * With nothing to show the layer renders null — no box, no layout, no
 * shadow.
 */
export function InfoPopup({ankiSend = null, onDismissAnki = null, aiStarted = false, aiError = null, aiErrorStatus = null, onRetryAskAi = null, onDismissAskAi = null, explainSlot = null}) {
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
