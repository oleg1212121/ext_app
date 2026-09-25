import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {router} from '@inertiajs/react';
import ReaderRow from './ReaderRow.jsx';
import {popupFontSizeFor} from '../../Components/WordPopup.jsx';
import {useI18n} from '../../i18n';
import {useUiSettingsAutosave} from '../../hooks/useUiSettingsAutosave';
import {loadReadingPositions, saveReadingPositions} from '../../lib/readingPosition';
import {loadSideFlip, saveSideFlip} from '../../lib/sideFlip';

const MIN_FONT_SIZE = 16;
const MAX_FONT_SIZE = 38;
const DEFAULT_FONT_SIZE = 20;
const FONT_STEP = 2;

// Props a page turn replaces; everything else (entity, fontSize, audio
// state) survives the visit untouched.
const PAGED_PROPS = ['rows', 'rowKeys', 'wordMap', 'translationWordMap', 'meta'];

const LANG_GLYPH = {
    en: 'EN',
    ru: 'RU',
};

const IconButton = ({onClick, disabled, label, children}) => (
    <button
        type="button"
        onClick={onClick}
        disabled={disabled}
        aria-label={label}
        title={label}
        className={[
            'h-8 min-w-8 px-2 inline-flex items-center justify-center font-serif text-lg leading-none',
            'text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70',
            'hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)]',
            'disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:text-[var(--color-ink-soft)]',
            'transition-colors duration-150',
            'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] rounded-sm',
        ].join(' ')}
    >
        {children}
    </button>
);

const ToggleButton = ({onClick, active, children}) => (
    <button
        type="button"
        onClick={onClick}
        className={[
            'px-2.5 h-8 inline-flex items-center font-sans text-xs tracking-wide rounded-sm',
            'border transition-colors duration-150',
            'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]',
            active
                ? 'border-[var(--color-vermilion)] text-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)] dark:text-[var(--color-vermilion-night)]'
                : 'border-[var(--color-hairline)] text-[var(--color-ink-soft)] dark:border-[var(--color-hairline-night)] dark:text-[var(--color-vellum-night)]/70 hover:border-[var(--color-ink)] dark:hover:border-[var(--color-vellum-night)] hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)]',
        ].join(' ')}
    >
        {children}
    </button>
);

const Divider = () => (
    <span aria-hidden="true" className="hidden sm:inline-block w-px h-5 bg-[var(--color-hairline)] dark:bg-[var(--color-hairline-night)]"/>
);

export default function ReaderApp({
    primaryLang = null,
    translationLang = null,
    entity,
    rows = [],
    rowKeys = [],
    meta = null,
    positionKey = null,
    fontSize: savedFontSize,
    highlight: savedHighlight = true,
    wordMap: initialWordMap = {},
    primaryHighlightable = false,
    translationWordMap: initialTranslationWordMap = {},
    translationHighlightable = false,
    primaryExplainable = false,
    translationExplainable = false,
    primarySide = null,
    explain = null,
}) {
    const {t} = useI18n();
    const [fontSize, setFontSize] = useState(savedFontSize ?? DEFAULT_FONT_SIZE);
    // Word-popup typography follows the page's font setting (ADR 0031).
    const popupFontSize = popupFontSizeFor(fontSize);
    const [highlight, setHighlight] = useState(savedHighlight);
    useUiSettingsAutosave('reader', {font_size: fontSize, highlight});
    const [wordMap, setWordMap] = useState(initialWordMap);
    const [translationWordMap, setTranslationWordMap] = useState(initialTranslationWordMap);
    // Language toggle (Working state, per device + positionKey): when true,
    // the server's translation column is read as the primary one.
    const [flipped, setFlipped] = useState(() => loadSideFlip(positionKey));
    const [showAll, setShowAll] = useState(false);
    const [sideBySide, setSideBySide] = useState(false);
    const [wideMode, setWideMode] = useState(false);
    const [expandedRows, setExpandedRows] = useState(() => new Set());
    const [audioStatus, setAudioStatus] = useState('');
    const [audioReady, setAudioReady] = useState(false);
    const [audioPlaying, setAudioPlaying] = useState(false);
    const [pageInput, setPageInput] = useState('1');

    const contentRef = useRef(null);
    const audioRef = useRef(null);
    const audioPickerRef = useRef(null);
    const audioObjectUrlRef = useRef(null);

    useEffect(() => {
        return () => {
            if (audioObjectUrlRef.current) {
                try {
                    URL.revokeObjectURL(audioObjectUrlRef.current);
                } catch {
                    // ignore
                }
            }
        };
    }, []);

    const adjustFontSize = useCallback((delta) => {
        setFontSize((current) => Math.max(MIN_FONT_SIZE, Math.min(MAX_FONT_SIZE, current + delta)));
    }, []);

    const toggleRow = useCallback((index) => {
        setExpandedRows((prev) => {
            const next = new Set(prev);
            if (next.has(index)) {
                next.delete(index);
            } else {
                next.add(index);
            }
            return next;
        });
    }, []);

    // Word progress changed in a popup: recolor the same word everywhere (a
    // token can appear in the primary text and its translation).
    const handleWordProgress = useCallback((key, status) => {
        const apply = (map) => (map[key] ? {...map, [key]: {...map[key], s: status}} : map);
        setWordMap(apply);
        setTranslationWordMap(apply);
    }, []);

    // The language toggle only exists when the text has a translation side;
    // when flipped, the server's translation column is read as the primary.
    const hasTranslation = translationLang !== null && translationLang !== undefined;
    const effectiveFlipped = hasTranslation && flipped;

    const displayRows = useMemo(
        () => (effectiveFlipped
            ? rows.map(([primary, translation]) => [translation, primary])
            : rows),
        [rows, effectiveFlipped],
    );

    const shownWordMap = effectiveFlipped ? translationWordMap : wordMap;
    const shownTranslationWordMap = effectiveFlipped ? wordMap : translationWordMap;
    const shownPrimaryHighlightable = effectiveFlipped ? translationHighlightable : primaryHighlightable;
    const shownTranslationHighlightable = effectiveFlipped ? primaryHighlightable : translationHighlightable;
    const shownPrimaryExplainable = effectiveFlipped ? translationExplainable : primaryExplainable;
    const shownTranslationExplainable = effectiveFlipped ? primaryExplainable : translationExplainable;
    const otherSide = primarySide === 'a' ? 'b' : primarySide === 'b' ? 'a' : null;
    const shownPrimarySide = effectiveFlipped ? otherSide : primarySide;
    const readingLang = effectiveFlipped ? translationLang : primaryLang;

    const setReadingLang = useCallback((lang) => {
        if (!hasTranslation || lang === readingLang) {
            return;
        }
        const next = !flipped;
        setFlipped(next);
        saveSideFlip(positionKey, next);
    }, [flipped, hasTranslation, readingLang, positionKey]);

    const handlePickAudio = useCallback(() => {
        audioPickerRef.current?.click();
    }, []);

    const handleAudioFileChange = useCallback((event) => {
        const file = event.target.files?.[0];
        if (!file) {
            return;
        }

        if (audioObjectUrlRef.current) {
            try {
                URL.revokeObjectURL(audioObjectUrlRef.current);
            } catch {
                // ignore
            }
        }

        const objectUrl = URL.createObjectURL(file);
        audioObjectUrlRef.current = objectUrl;

        if (audioRef.current) {
            audioRef.current.src = objectUrl;
            audioRef.current.load();
        }

        setAudioReady(true);
        setAudioPlaying(false);
        const fileName = file.name.length > 20 ? `${file.name.substring(0, 20)}…` : file.name;
        setAudioStatus(t('reader.audio_loaded', {name: fileName}));
    }, []);

    const handleAudioPlay = useCallback(async () => {
        if (!audioRef.current?.src) {
            return;
        }

        try {
            await audioRef.current.play();
            setAudioPlaying(true);
            setAudioStatus(t('reader.playing'));
        } catch (err) {
            setAudioStatus(t('reader.cannot_play', {reason: err?.message || t('reader.unknown_error')}));
        }
    }, []);

    const handleAudioPause = useCallback(() => {
        if (!audioRef.current?.src) {
            return;
        }

        audioRef.current.pause();
        setAudioPlaying(false);
        setAudioStatus(t('reader.paused'));
    }, []);

    const handleAudioStop = useCallback(() => {
        if (!audioRef.current?.src) {
            return;
        }

        audioRef.current.pause();
        try {
            audioRef.current.currentTime = 0;
        } catch {
            // ignore
        }
        setAudioPlaying(false);
        setAudioStatus(t('reader.stopped'));
    }, []);

    useEffect(() => {
        const onKeyDown = (event) => {
            if (event.code !== 'Space' || ['INPUT', 'TEXTAREA'].includes(event.target.tagName)) {
                return;
            }

            event.preventDefault();

            if (!audioRef.current?.src) {
                return;
            }

            if (audioRef.current.paused) {
                audioRef.current.play()
                    .then(() => {
                        setAudioPlaying(true);
                        setAudioStatus(t('reader.playing'));
                    })
                    .catch((err) => setAudioStatus(t('reader.cannot_play', {reason: err?.message || t('reader.unknown_error')})));
            } else {
                audioRef.current.pause();
                setAudioPlaying(false);
                setAudioStatus(t('reader.paused'));
            }
        };

        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    const currentPage = meta?.current_page ?? 1;
    const lastPage = meta?.last_page ?? 1;
    const totalRows = meta?.total ?? rows.length;

    const savePosition = useCallback((page) => {
        if (!positionKey) {
            return;
        }
        const positions = loadReadingPositions();
        positions[positionKey] = page;
        saveReadingPositions(positions);
    }, [positionKey]);

    // A page turn is an Inertia partial reload: the server ships only the
    // page's rows, row keys and page-scoped word maps, and the component —
    // audio player included — stays mounted. State mirroring those props
    // must be resynced by hand: useState initializers don't re-run on a
    // preserved-state visit.
    const goToPage = useCallback((page) => {
        const target = Math.max(1, Math.min(lastPage, page));
        if (target === currentPage) {
            return;
        }

        savePosition(target);
        router.visit(`${window.location.pathname}?page=${target}`, {
            only: PAGED_PROPS,
            preserveState: true,
            preserveScroll: true,
        });
    }, [currentPage, lastPage, savePosition]);

    // The page picker mirrors the page the reader is on; typed values commit
    // on Enter or blur, clamped into range.
    useEffect(() => {
        setPageInput(String(currentPage));
    }, [currentPage]);

    const submitPageInput = () => {
        const parsed = Number.parseInt(pageInput, 10);
        const target = Number.isNaN(parsed) ? currentPage : Math.max(1, Math.min(lastPage, parsed));
        setPageInput(String(target));
        if (target !== currentPage) {
            goToPage(target);
        }
    };

    const previousPageRef = useRef(currentPage);
    useEffect(() => {
        if (previousPageRef.current === currentPage) {
            return;
        }
        previousPageRef.current = currentPage;
        setWordMap(initialWordMap);
        setTranslationWordMap(initialTranslationWordMap);
        setExpandedRows(new Set());
        contentRef.current?.scrollTo({top: 0});
    }, [currentPage, initialWordMap, initialTranslationWordMap]);

    // Restore the text's saved Reading position once, when the URL doesn't
    // already pin a page: jump history-replace to the clamped saved page
    // (repairing the stored value if the text shrank under it). In-session
    // Back to the bare URL skips this — a ref'd mount-only effect won't
    // re-fire while Inertia keeps the component instance alive.
    const restoredRef = useRef(false);
    useEffect(() => {
        if (restoredRef.current || !positionKey || lastPage <= 1) {
            return;
        }
        restoredRef.current = true;

        if (new URLSearchParams(window.location.search).has('page')) {
            return;
        }

        const positions = loadReadingPositions();
        const saved = Number.isInteger(positions[positionKey]) ? positions[positionKey] : 1;
        const clamped = Math.max(1, Math.min(lastPage, saved));

        if (saved !== clamped) {
            savePosition(clamped);
        }

        if (clamped > 1) {
            // router.visit, NOT router.replace: in Inertia v3 replace() is a
            // client-side page-object patch that takes no options and never
            // fetches — the saved page was never actually loaded (and the
            // stray page-set reset the scroll: the "reload" glitch).
            router.visit(`${window.location.pathname}?page=${clamped}`, {
                only: PAGED_PROPS,
                preserveState: true,
                preserveScroll: true,
                replace: true,
                // Fires on success and failure alike: a failed visit still
                // reveals page 1 instead of trapping the placeholder.
                onFinish: () => setRestoring(false),
            });
        }
    }, [positionKey, lastPage, savePosition]);

    // While the restore above is in flight, hold the rows and pager back:
    // rendering page 1 for a beat and then swapping rows under the user
    // scrolled mid-read looked like a reload and yanked scroll to the top
    // (the page-turn effect scrolls on the currentPage change). The
    // placeholder keeps the container short, so that scroll lands as a
    // harmless no-op on the restored page.
    const [restoring, setRestoring] = useState(() => {
        if (!positionKey || (meta?.last_page ?? 1) <= 1) {
            return false;
        }
        if (new URLSearchParams(window.location.search).has('page')) {
            return false;
        }
        const saved = loadReadingPositions()[positionKey];
        return Number.isInteger(saved) && saved > 1;
    });

    const entityTitle = entity?.name ?? t('reader.untitled');

    return (
        <div
            id="readerRoot"
            className="flex-1 min-h-0 flex flex-col bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]"
        >
            <header className="flex-none border-b border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]">
                <div className="px-4 sm:px-6 lg:px-8 py-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                    <button
                        type="button"
                        onClick={() => history.length > 1 ? history.back() : null}
                        className="font-sans text-xs tracking-wide text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)] transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] rounded-sm"
                    >
                        {t('reader.back_to_library')}
                    </button>

                    <Divider/>

                    <div className="min-w-0 flex items-baseline gap-3">
                        <h1 className="truncate font-serif text-lg sm:text-xl tracking-tight">{entityTitle}</h1>
                        <span className="font-sans text-[10px] tracking-[0.2em] uppercase text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">
                            {LANG_GLYPH[readingLang] ?? readingLang}
                        </span>
                    </div>

                    <div className="ml-auto flex flex-wrap items-center gap-2 sm:gap-3">
                        {hasTranslation && (
                            <div
                                role="radiogroup"
                                aria-label={t('reader.reading_language')}
                                className="flex items-center gap-0.5 border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] rounded-sm p-0.5"
                            >
                                {[primaryLang, translationLang].map((code) => (
                                    <label
                                        key={code}
                                        className={[
                                            'px-2 h-7 inline-flex items-center font-sans text-xs tracking-wide rounded-sm cursor-pointer select-none',
                                            'transition-colors duration-150 focus-within:outline-none focus-within:ring-2 focus-within:ring-[var(--color-vermilion)]',
                                            readingLang === code
                                                ? 'bg-[var(--color-vermilion)] text-vellum dark:bg-[var(--color-vermilion-night)] dark:text-ink-night'
                                                : 'text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)]',
                                        ].join(' ')}
                                    >
                                        <input
                                            type="radio"
                                            name="reader-reading-language"
                                            value={code}
                                            checked={readingLang === code}
                                            onChange={() => setReadingLang(code)}
                                            className="sr-only"
                                        />
                                        {LANG_GLYPH[code] ?? code}
                                    </label>
                                ))}
                            </div>
                        )}

                        <div className="flex items-center gap-0.5 border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] rounded-sm">
                            <IconButton label={t('reader.decrease_text_size')} onClick={() => adjustFontSize(-FONT_STEP)}>
                                −
                            </IconButton>
                            <span
                                id="fontSizeValue"
                                aria-live="off"
                                className="font-serif text-xs tabular-nums w-7 text-center text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70"
                            >
                                {fontSize}
                            </span>
                            <IconButton label={t('reader.increase_text_size')} onClick={() => adjustFontSize(FONT_STEP)}>
                                +
                            </IconButton>
                        </div>

                        <Divider/>

                        <ToggleButton active={showAll} onClick={() => setShowAll((v) => !v)}>
                            {t('reader.show_all')}
                        </ToggleButton>
                        <ToggleButton active={highlight} onClick={() => setHighlight((v) => !v)}>
                            {t('reader.highlights')}
                        </ToggleButton>
                        <ToggleButton active={sideBySide} onClick={() => setSideBySide((v) => !v)}>
                            {sideBySide ? t('reader.stacked') : t('reader.side_by_side')}
                        </ToggleButton>
                        <ToggleButton active={wideMode} onClick={() => setWideMode((v) => !v)}>
                            {wideMode ? t('reader.normal_width') : t('reader.wide')}
                        </ToggleButton>

                        <Divider/>

                        <div className="flex items-center gap-2">
                            <input
                                ref={audioPickerRef}
                                id="audioPicker"
                                type="file"
                                accept="audio/*"
                                className="hidden"
                                onChange={handleAudioFileChange}
                            />
                            <button
                                id="pickAudioBtn"
                                type="button"
                                onClick={handlePickAudio}
                                className="px-2.5 h-8 font-sans text-xs tracking-wide rounded-sm border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 hover:border-[var(--color-ink)] dark:hover:border-[var(--color-vellum-night)] hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)] transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]"
                            >
                                {t('reader.pick_audio')}
                            </button>
                            <div className="flex items-center gap-0.5 border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] rounded-sm">
                                <IconButton
                                    id="audioPlay"
                                    label={t('reader.play')}
                                    disabled={!audioReady}
                                    onClick={handleAudioPlay}
                                >
                                    ▶
                                </IconButton>
                                <IconButton
                                    id="audioPause"
                                    label={t('reader.pause')}
                                    disabled={!audioReady}
                                    onClick={handleAudioPause}
                                >
                                    ❚❚
                                </IconButton>
                                <IconButton
                                    id="audioStop"
                                    label={t('reader.stop')}
                                    disabled={!audioReady}
                                    onClick={handleAudioStop}
                                >
                                    ■
                                </IconButton>
                            </div>
                            <span
                                id="audioStatus"
                                className={[
                                    'font-sans text-xs',
                                    audioPlaying
                                        ? 'text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]'
                                        : 'text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60',
                                ].join(' ')}
                            >
                                {audioStatus}
                            </span>
                        </div>
                    </div>
                </div>
            </header>

            <main
                id="contentContainer"
                ref={contentRef}
                // overflow-anchor: Chromium's scroll anchoring recomputes
                // anchor nodes on every layout change and is a known
                // scroll-freeze ingredient; scrollbar-gutter:stable keeps
                // the scrollbar from appearing/disappearing mid-scroll.
                className="flex-1 min-h-0 overflow-y-auto [overflow-anchor:none] [scrollbar-gutter:stable]"
            >
                <div
                    className={[
                        'mx-auto px-5 sm:px-8 lg:px-10 pt-12 pb-24 transition-[max-width] duration-300',
                        wideMode ? 'w-[95%] max-w-[1400px] 2xl:max-w-[1600px]' : 'max-w-[62rem]',
                    ].join(' ')}
                >
                    <div className="mb-10 text-center">
                        <span className="font-sans text-[10px] tracking-[0.24em] uppercase text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/50">
                            {t('reader.folio')} · {totalRows} {totalRows === 1 ? t('reader.line') : t('reader.lines')}
                        </span>
                        <h2 className="mt-2 font-serif font-light text-3xl sm:text-4xl tracking-tight leading-tight">
                            {entityTitle}
                        </h2>
                        <span className="mt-4 inline-block h-px w-12 bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)]"/>
                    </div>

                    <ol role="list" className="list-none m-0 p-0 space-y-1">
                        {restoring ? (
                            <li className="flex justify-center py-24" aria-busy="true">
                                <svg
                                    className="animate-spin h-6 w-6 text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                                </svg>
                            </li>
                        ) : displayRows.map(([primary, translation], index) => (
                            <ReaderRow
                                key={rowKeys[index] ?? index}
                                index={index}
                                primary={primary}
                                translation={translation}
                                rowKey={rowKeys[index]}
                                showAll={showAll}
                                sideBySide={sideBySide}
                                fontSize={fontSize}
                                popupFontSize={popupFontSize}
                                expanded={expandedRows.has(index)}
                                onToggle={toggleRow}
                                wordMap={shownWordMap}
                                primaryHighlightable={shownPrimaryHighlightable}
                                translationWordMap={shownTranslationWordMap}
                                translationHighlightable={shownTranslationHighlightable}
                                highlight={highlight}
                                onWordProgress={handleWordProgress}
                                primaryExplainable={shownPrimaryExplainable}
                                translationExplainable={shownTranslationExplainable}
                                primarySide={shownPrimarySide}
                                explain={explain}
                            />
                        ))}
                    </ol>

                    <p className="mt-12 text-center font-sans text-xs tracking-wide text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/50">
                        {t('reader.hint')}
                    </p>
                </div>
            </main>

            {meta && !restoring && totalRows > 0 && (
                <nav
                    aria-label={t('reader.pages')}
                    className="flex-none border-t border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]"
                >
                    <div className="py-2 flex items-center justify-center gap-2">
                        <IconButton
                            label={t('reader.previous_page')}
                            disabled={currentPage <= 1}
                            onClick={() => goToPage(currentPage - 1)}
                        >
                            ‹
                        </IconButton>
                        <span className="flex items-center gap-1.5 font-sans text-xs tabular-nums text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
                            <input
                                id="readerPagePicker"
                                // type="text" + inputMode, not type="number":
                                // Chrome's number input spins the value on
                                // wheel while focused, an unneeded re-render
                                // trigger under the cursor in a scroll area.
                                type="text"
                                inputMode="numeric"
                                pattern="[0-9]*"
                                value={pageInput}
                                aria-label={t('reader.go_to_page')}
                                onChange={(event) => setPageInput(event.target.value)}
                                onBlur={submitPageInput}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        event.currentTarget.blur();
                                    }
                                }}
                                className="w-14 h-7 px-1 text-center bg-transparent border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] rounded-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]"
                            />
                            <span>{t('reader.page_of', {last: lastPage})}</span>
                        </span>
                        <IconButton
                            label={t('reader.next_page')}
                            disabled={currentPage >= lastPage}
                            onClick={() => goToPage(currentPage + 1)}
                        >
                            ›
                        </IconButton>
                    </div>
                </nav>
            )}

            <audio
                ref={audioRef}
                id="readerAudio"
                className="hidden"
                onEnded={() => {
                    setAudioPlaying(false);
                    setAudioStatus(t('reader.ended'));
                }}
            />
        </div>
    );
}
