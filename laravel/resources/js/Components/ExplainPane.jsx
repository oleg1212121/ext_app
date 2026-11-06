import {useEffect, useRef, useState} from 'react';
import {Link} from '@inertiajs/react';
import {useI18n} from '../i18n';
import {getCsrfToken} from '../lib/http';
import {renderMarkdown} from '../lib/markdown';
import {explainButtonClass} from '../lib/popupStyle';
import {canAskExplanation} from '../lib/wordExplain';

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
 * moment the toolbar's Explanation icon first shows it — WordCard mounts
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
export function ExplainPane({explain, explainKey, preview = false}) {
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

    // Toggle-on == this pane mounting (WordCard mounts ExplainPane only
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
