import {Link} from '@inertiajs/react';
import {useI18n} from '../i18n';
import {explainButtonClass, toolbarButtonClass} from '../lib/popupStyle';
import {Icon} from './icons.jsx';

/**
 * The ask-AI error state shared by every card status — the dictionary card
 * and the missing card run the same runEnrich, so one render carries the
 * failure for both: the server's guidance verbatim, the explain pane's
 * go-to-settings link for the no-model (400) / no-key (403) answers, and
 * the Retry control the four-state contract requires of an error state —
 * it re-sends the ask through the same guarded callback the toolbar icon
 * uses. Rendered inside the info popup above the card (word-popup-overhaul
 * ticket 04) — sticky until dismissed, so it takes the shared per-item
 * dismiss control when a dismiss callback is passed.
 */
export function AskAiError({message, status, onRetry = null, onDismiss = null}) {
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
 * auto-dismiss instead and take none. It lives here, beside the ask-AI
 * error that mounts it, rather than in the info popup's file: the layer
 * imports both from this file for its Anki error line, so the import graph
 * runs one way (word-card ticket 03 — no InfoPopup <-> AskAiError cycle).
 */
export function InfoDismissButton({onClick}) {
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
