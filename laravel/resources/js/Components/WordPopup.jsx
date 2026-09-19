import React, {useEffect, useRef, useState} from 'react';
import {createPortal} from 'react-dom';
import {useI18n} from '../i18n';
import {getCsrfToken} from '../lib/http';
import {FAMILIARITY_MAX} from '../lib/wordFamiliarity';

const POPUP_MARGIN = 8;
const VIEWPORT_MARGIN = 12;
const MIN_POPUP_HEIGHT = 160;
const TRANSLATIONS_PREVIEW = 8;

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
 * side so the footer actions stay reachable. Width scales with the popup
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

function TranslationLine({translations}) {
    const {t} = useI18n();
    const [expanded, setExpanded] = useState(false);

    if (translations.length === 0) {
        return null;
    }

    const visible = expanded ? translations : translations.slice(0, TRANSLATIONS_PREVIEW);

    return (
        <p className="mt-2 text-[0.929em] leading-snug text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">
            {visible.map((translation) => translation.word).join(', ')}
            {translations.length > TRANSLATIONS_PREVIEW && !expanded && (
                <button
                    type="button"
                    onClick={() => setExpanded(true)}
                    className="ml-1 underline underline-offset-2 opacity-70 hover:opacity-100"
                >
                    {t('word.more_translations', {count: translations.length - TRANSLATIONS_PREVIEW})}
                </button>
            )}
        </p>
    );
}

/**
 * Word popover shown next to a Ctrl-clicked word: one section per part of
 * speech under the headword (details fetched lazily from GET /words/{id}),
 * plus the word progress actions in a footer that never scrolls away.
 * `fontSize` (px) is the popup's root font, derived by the host page from
 * its reading font size (popupFontSizeFor); every inner text size is
 * em-relative to it, and the width scales with it.
 */
export default function WordPopup({wordId, surface, familiarity, rect, onClose, onProgress, fontSize = DEFAULT_POPUP_FONT_SIZE}) {
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
            style={{...popupStyle(rect, fontSize), fontSize: `${fontSize}px`}}
            className="word-popup absolute z-50 flex flex-col overflow-hidden rounded-md border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[#FDFBF5] dark:bg-[#1C1915] shadow-lg shadow-black/20 font-sans text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]"
        >
            {(loading || error) && (
                <div className="p-3.5">
                    {loading && <p className="text-[0.857em] opacity-60">{t('word.loading')}</p>}
                    {error && <p className="text-[0.857em] text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">{error}</p>}
                </div>
            )}

            {!loading && data && (
                <>
                    <div className="shrink-0 px-3.5 pb-1 pt-3">
                        <div className="flex items-baseline gap-2 flex-wrap">
                            <span className="font-serif text-[1.286em] leading-tight">{data.word}</span>
                            {data.word_class && (
                                <span className="text-[0.786em] uppercase tracking-wider opacity-60">{data.word_class}</span>
                            )}
                        </div>

                        <p className="mt-0.5 text-[0.786em] tabular-nums opacity-60">
                            {t('word.familiarity', {value: familiarity ?? 0, max: FAMILIARITY_MAX})}
                        </p>

                        {data.is_form && (
                            <p className="mt-1 text-[0.857em] italic opacity-70">
                                «{surface}» — {t('word.form_of')} «{data.word}»
                            </p>
                        )}
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto px-3.5 pb-1">
                        {data.entries?.map((entry, index) => (
                            <section
                                key={entry.id}
                                className={index > 0
                                    ? 'mt-2.5 border-t border-[var(--color-hairline)] pt-2.5 dark:border-[var(--color-hairline-night)]'
                                    : undefined}
                            >
                                {entry.word_class && (
                                    <h3 className="text-[0.786em] uppercase tracking-wider opacity-60">{entry.word_class}</h3>
                                )}

                                {entry.transcriptions?.length > 0 && (
                                    <p className="mt-1 opacity-80">
                                        [{entry.transcriptions.map((item) => item.value).join(' · ')}]
                                    </p>
                                )}

                                {entry.definitions?.length > 0 ? (
                                    <ol className="ml-4 mt-1.5 list-decimal space-y-1 text-[0.929em] leading-snug">
                                        {entry.definitions.map((definition, definitionIndex) => (
                                            <li key={definitionIndex}>{definition}</li>
                                        ))}
                                    </ol>
                                ) : (
                                    <p className="mt-1.5 text-[0.857em] opacity-60">{t('word.no_definitions')}</p>
                                )}

                                <TranslationLine translations={entry.translations ?? []} />

                                {entry.examples?.length > 0 && (
                                    <details className="mt-2 text-[0.857em]">
                                        <summary className="cursor-pointer opacity-70">{t('word.examples')}</summary>
                                        <ul className="ml-4 mt-1 list-disc space-y-0.5 italic opacity-80">
                                            {entry.examples.map((example, exampleIndex) => (
                                                <li key={exampleIndex}>{example}</li>
                                            ))}
                                        </ul>
                                    </details>
                                )}

                                {entry.etymologies?.length > 0 && (
                                    <div className="mt-2">
                                        <p className="text-[0.786em] uppercase tracking-wider opacity-60">{t('word.etymology')}</p>
                                        {entry.etymologies.map((etymology, etymologyIndex) => (
                                            <p key={etymologyIndex} className="mt-1 text-[0.857em] italic leading-snug opacity-75">
                                                {etymology}
                                            </p>
                                        ))}
                                    </div>
                                )}
                            </section>
                        ))}
                    </div>

                    <div className="mt-2 shrink-0 border-t border-[var(--color-hairline)] px-3.5 py-2.5 dark:border-[var(--color-hairline-night)]">
                        <div className="flex gap-2">
                            {familiarity !== FAMILIARITY_MAX && (
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => changeProgress('known')}
                                    className="rounded-sm border border-[var(--color-verdigris)] px-2.5 py-1 text-[0.857em] hover:bg-[var(--color-verdigris)]/10 disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] dark:border-[var(--color-verdigris-night)]"
                                >
                                    {t('word.i_know_this')}
                                </button>
                            )}
                            {familiarity != null && (
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => changeProgress('reset')}
                                    className="rounded-sm border border-[var(--color-hairline)] px-2.5 py-1 text-[0.857em] opacity-80 hover:opacity-100 disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] dark:border-[var(--color-hairline-night)]"
                                >
                                    {t('word.remove_mark')}
                                </button>
                            )}
                        </div>
                    </div>
                </>
            )}
        </div>,
        document.body,
    );
}
