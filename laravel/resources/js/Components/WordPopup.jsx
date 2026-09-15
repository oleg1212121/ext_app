import React, {useEffect, useRef, useState} from 'react';
import {createPortal} from 'react-dom';
import {useI18n} from '../i18n';
import {getCsrfToken} from '../lib/http';
import {FAMILIARITY_MAX} from '../lib/wordFamiliarity';

const POPUP_MARGIN = 8;
const POPUP_WIDTH = 340;
const VIEWPORT_MARGIN = 12;

function popupStyle(rect) {
    const left = Math.min(
        Math.max(VIEWPORT_MARGIN, rect.left + window.scrollX),
        Math.max(VIEWPORT_MARGIN, window.innerWidth - POPUP_WIDTH - VIEWPORT_MARGIN),
    );
    const top = rect.bottom + window.scrollY + POPUP_MARGIN;

    return {left: `${left}px`, top: `${top}px`, width: `${POPUP_WIDTH}px`};
}

/**
 * Word popup shown next to a clicked word: dictionary details fetched lazily
 * from GET /words/{id} plus the word progress actions.
 */
export default function WordPopup({wordId, surface, familiarity, rect, onClose, onProgress}) {
    const {t} = useI18n();
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        setError(null);
        setData(null);

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

    useEffect(() => {
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                onClose();
            }
        };
        const onMouseDown = (event) => {
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

    return createPortal(
        <div
            ref={ref}
            role="dialog"
            aria-label={data?.word ?? surface}
            style={popupStyle(rect)}
            className="word-popup absolute z-50 rounded-md border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[#FDFBF5] dark:bg-[#1C1915] shadow-lg shadow-black/20 p-3.5 font-sans text-sm text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]"
        >
            {loading && <p className="text-xs opacity-60">{t('word.loading')}</p>}

            {!loading && error && <p className="text-xs text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">{error}</p>}

            {!loading && data && (
                <>
                    <div className="flex items-baseline gap-2 flex-wrap">
                        <span className="font-serif text-lg leading-tight">{data.word}</span>
                        {data.word_class && (
                            <span className="text-[11px] uppercase tracking-wider opacity-60">{data.word_class}</span>
                        )}
                    </div>

                    <p className="mt-0.5 text-[11px] tabular-nums opacity-60">
                        {t('word.familiarity', {value: familiarity ?? 0, max: FAMILIARITY_MAX})}
                    </p>

                    {data.is_form && (
                        <p className="mt-1 text-xs italic opacity-70">
                            «{surface}» — {t('word.form_of')} «{data.word}»
                        </p>
                    )}

                    {data.transcriptions?.length > 0 && (
                        <p className="mt-1 text-sm opacity-80">
                            [{data.transcriptions.map((item) => item.value).join(' · ')}]
                        </p>
                    )}

                    {data.definitions?.length > 0 ? (
                        <ol className="mt-2 list-decimal ml-4 space-y-1 text-[13px] leading-snug">
                            {data.definitions.map((definition, index) => (
                                <li key={index}>{definition}</li>
                            ))}
                        </ol>
                    ) : (
                        <p className="mt-2 text-xs opacity-60">{t('word.no_definitions')}</p>
                    )}

                    {data.translations?.length > 0 && (
                        <p className="mt-2 text-[13px] leading-snug text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">
                            {data.translations.map((translation) => translation.word).join(', ')}
                        </p>
                    )}

                    {data.examples?.length > 0 && (
                        <details className="mt-2 text-xs">
                            <summary className="cursor-pointer opacity-70">{t('word.examples')}</summary>
                            <ul className="mt-1 ml-4 list-disc space-y-0.5 italic opacity-80">
                                {data.examples.map((example, index) => (
                                    <li key={index}>{example}</li>
                                ))}
                            </ul>
                        </details>
                    )}

                    <div className="mt-3 flex gap-2">
                        {familiarity !== FAMILIARITY_MAX && (
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => changeProgress('known')}
                                className="px-2.5 py-1 rounded-sm border border-[var(--color-verdigris)] dark:border-[var(--color-verdigris-night)] text-xs hover:bg-[var(--color-verdigris)]/10 disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]"
                            >
                                {t('word.i_know_this')}
                            </button>
                        )}
                        {familiarity != null && (
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => changeProgress('reset')}
                                className="px-2.5 py-1 rounded-sm border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] text-xs opacity-80 hover:opacity-100 disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]"
                            >
                                {t('word.remove_mark')}
                            </button>
                        )}
                    </div>
                </>
            )}
        </div>,
        document.body,
    );
}
