import {useCallback, useEffect, useRef, useState} from 'react';
import ReaderRow from './ReaderRow.jsx';

const MIN_FONT_SIZE = 16;
const MAX_FONT_SIZE = 38;
const DEFAULT_FONT_SIZE = 20;
const FONT_STEP = 2;

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

export default function ReaderApp({lang = 'en', entity, rows = []}) {
    const [fontSize, setFontSize] = useState(DEFAULT_FONT_SIZE);
    const [showAll, setShowAll] = useState(false);
    const [sideBySide, setSideBySide] = useState(false);
    const [wideMode, setWideMode] = useState(false);
    const [expandedRows, setExpandedRows] = useState(() => new Set());
    const [audioStatus, setAudioStatus] = useState('');
    const [audioReady, setAudioReady] = useState(false);
    const [audioPlaying, setAudioPlaying] = useState(false);

    const rootRef = useRef(null);
    const audioRef = useRef(null);
    const audioPickerRef = useRef(null);
    const audioObjectUrlRef = useRef(null);

    useEffect(() => {
        if (rootRef.current) {
            rootRef.current.style.setProperty('--fs', `${fontSize}px`);
        }
    }, [fontSize]);

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
        setAudioStatus(`Loaded · ${fileName}`);
    }, []);

    const handleAudioPlay = useCallback(async () => {
        if (!audioRef.current?.src) {
            return;
        }

        try {
            await audioRef.current.play();
            setAudioPlaying(true);
            setAudioStatus('Playing');
        } catch (err) {
            setAudioStatus(`Cannot play: ${err?.message || 'unknown error'}`);
        }
    }, []);

    const handleAudioPause = useCallback(() => {
        if (!audioRef.current?.src) {
            return;
        }

        audioRef.current.pause();
        setAudioPlaying(false);
        setAudioStatus('Paused');
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
        setAudioStatus('Stopped');
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
                        setAudioStatus('Playing');
                    })
                    .catch((err) => setAudioStatus(`Cannot play: ${err?.message || 'unknown error'}`));
            } else {
                audioRef.current.pause();
                setAudioPlaying(false);
                setAudioStatus('Paused');
            }
        };

        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    const entityTitle = entity?.name ?? 'Untitled';

    return (
        <div
            id="readerRoot"
            ref={rootRef}
            className="flex-1 min-h-0 flex flex-col bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]"
        >
            <header className="flex-none border-b border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]">
                <div className="px-4 sm:px-6 lg:px-8 py-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                    <button
                        type="button"
                        onClick={() => history.length > 1 ? history.back() : null}
                        className="font-sans text-xs tracking-wide text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)] transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] rounded-sm"
                    >
                        ← Library
                    </button>

                    <Divider/>

                    <div className="min-w-0 flex items-baseline gap-3">
                        <h1 className="truncate font-serif text-lg sm:text-xl tracking-tight">{entityTitle}</h1>
                        <span className="font-sans text-[10px] tracking-[0.2em] uppercase text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">
                            {LANG_GLYPH[lang] ?? lang}
                        </span>
                    </div>

                    <div className="ml-auto flex flex-wrap items-center gap-2 sm:gap-3">
                        <div className="flex items-center gap-0.5 border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] rounded-sm">
                            <IconButton label="Decrease text size" onClick={() => adjustFontSize(-FONT_STEP)}>
                                −
                            </IconButton>
                            <span
                                id="fontSizeValue"
                                aria-live="off"
                                className="font-serif text-xs tabular-nums w-7 text-center text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70"
                            >
                                {fontSize}
                            </span>
                            <IconButton label="Increase text size" onClick={() => adjustFontSize(FONT_STEP)}>
                                +
                            </IconButton>
                        </div>

                        <Divider/>

                        <ToggleButton active={showAll} onClick={() => setShowAll((v) => !v)}>
                            Show all
                        </ToggleButton>
                        <ToggleButton active={sideBySide} onClick={() => setSideBySide((v) => !v)}>
                            {sideBySide ? 'Stacked' : 'Side by side'}
                        </ToggleButton>
                        <ToggleButton active={wideMode} onClick={() => setWideMode((v) => !v)}>
                            {wideMode ? 'Normal width' : 'Wide'}
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
                                Pick audio
                            </button>
                            <div className="flex items-center gap-0.5 border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] rounded-sm">
                                <IconButton
                                    id="audioPlay"
                                    label="Play"
                                    disabled={!audioReady}
                                    onClick={handleAudioPlay}
                                >
                                    ▶
                                </IconButton>
                                <IconButton
                                    id="audioPause"
                                    label="Pause"
                                    disabled={!audioReady}
                                    onClick={handleAudioPause}
                                >
                                    ❚❚
                                </IconButton>
                                <IconButton
                                    id="audioStop"
                                    label="Stop"
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
                className="flex-1 min-h-0 overflow-y-auto"
            >
                <div
                    className={[
                        'mx-auto px-5 sm:px-8 lg:px-10 pt-12 pb-24 transition-all duration-300',
                        wideMode ? 'w-[95%] max-w-[1400px] 2xl:max-w-[1600px]' : 'max-w-[62rem]',
                    ].join(' ')}
                >
                    <div className="mb-10 text-center">
                        <span className="font-sans text-[10px] tracking-[0.24em] uppercase text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/50">
                            Folio · {rows.length} {rows.length === 1 ? 'line' : 'lines'}
                        </span>
                        <h2 className="mt-2 font-serif font-light text-3xl sm:text-4xl tracking-tight leading-tight">
                            {entityTitle}
                        </h2>
                        <span className="mt-4 inline-block h-px w-12 bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)]"/>
                    </div>

                    <ol role="list" className="list-none m-0 p-0 space-y-1">
                        {rows.map(([primary, translation], index) => (
                            <ReaderRow
                                key={index}
                                index={index}
                                primary={primary}
                                translation={translation}
                                showAll={showAll}
                                sideBySide={sideBySide}
                                fontSize={fontSize}
                                expanded={expandedRows.has(index)}
                                onToggle={toggleRow}
                            />
                        ))}
                    </ol>

                    <p className="mt-12 text-center font-sans text-xs tracking-wide text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/50">
                        Tap a line to reveal its translation · Spacebar toggles audio playback
                    </p>
                </div>
            </main>

            <audio
                ref={audioRef}
                id="readerAudio"
                className="hidden"
                onEnded={() => {
                    setAudioPlaying(false);
                    setAudioStatus('Ended');
                }}
            />
        </div>
    );
}
