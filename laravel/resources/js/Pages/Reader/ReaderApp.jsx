import {useCallback, useEffect, useRef, useState} from 'react';
import ReaderRow from './ReaderRow.jsx';

const MIN_FONT_SIZE = 16;
const MAX_FONT_SIZE = 38;
const DEFAULT_FONT_SIZE = 20;

export default function ReaderApp({lang = 'en', entity, rows = []}) {
    const [fontSize, setFontSize] = useState(DEFAULT_FONT_SIZE);
    const [showAll, setShowAll] = useState(false);
    const [sideBySide, setSideBySide] = useState(false);
    const [wideMode, setWideMode] = useState(false);
    const [expandedRows, setExpandedRows] = useState(() => new Set());
    const [audioStatus, setAudioStatus] = useState('');
    const [audioReady, setAudioReady] = useState(false);

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
        const fileName = file.name.length > 20 ? `${file.name.substring(0, 20)}...` : file.name;
        setAudioStatus(`Ready: ${fileName}`);
    }, []);

    const handleAudioPlay = useCallback(async () => {
        if (!audioRef.current?.src) {
            return;
        }

        try {
            await audioRef.current.play();
            setAudioStatus('Playing ▶');
        } catch (err) {
            setAudioStatus(`Cannot play: ${err?.message || 'unknown error'}`);
        }
    }, []);

    const handleAudioPause = useCallback(() => {
        if (!audioRef.current?.src) {
            return;
        }

        audioRef.current.pause();
        setAudioStatus('Paused ⏸');
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
        setAudioStatus('Stopped ⏹');
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
                    .then(() => setAudioStatus('Playing ▶'))
                    .catch((err) => setAudioStatus(`Cannot play: ${err?.message || 'unknown error'}`));
            } else {
                audioRef.current.pause();
                setAudioStatus('Paused ⏸');
            }
        };

        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    const entityTitle = entity?.name ?? 'Book Reader';

    return (
        <div
            id="readerRoot"
            ref={rootRef}
            className="min-h-screen flex flex-col bg-orange-50 dark:bg-gray-900"
        >
            <header className="flex-none bg-white dark:bg-gray-800 border-b-2 border-gray-400 dark:border-gray-600 shadow-md">
                <div className="flex flex-wrap items-center justify-center gap-3 px-4 py-3">
                    <div className="flex items-center gap-2">
                        <h1 className="text-lg font-semibold text-gray-800 dark:text-gray-100">{entityTitle}</h1>
                        <span className="text-xs text-gray-500 dark:text-gray-400 uppercase">{lang}</span>
                    </div>

                    <div className="h-6 w-px bg-gray-400 dark:bg-gray-600"/>

                    <div className="flex items-center gap-1">
                        <button
                            id="fontSizeDecrease"
                            type="button"
                            className="w-8 h-8 flex items-center justify-center bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 rounded transition"
                            onClick={() => adjustFontSize(-2)}
                        >
                            <span className="text-lg font-semibold">−</span>
                        </button>
                        <span
                            id="fontSizeValue"
                            className="text-xs text-gray-600 dark:text-gray-400 font-mono w-8 text-center"
                        >
                            {fontSize}
                        </span>
                        <button
                            id="fontSizeIncrease"
                            type="button"
                            className="w-8 h-8 flex items-center justify-center bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 rounded transition"
                            onClick={() => adjustFontSize(2)}
                        >
                            <span className="text-lg font-semibold">+</span>
                        </button>
                    </div>

                    <div className="h-6 w-px bg-gray-400 dark:bg-gray-600"/>

                    <label className="inline-flex items-center gap-2 cursor-pointer">
                        <input
                            id="toggleAll"
                            type="checkbox"
                            className="w-4 h-4 text-gray-700 dark:text-gray-300 rounded border-gray-300 dark:border-gray-600 focus:ring-gray-600 dark:bg-gray-700"
                            checked={showAll}
                            onChange={(event) => setShowAll(event.target.checked)}
                        />
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Show All</span>
                    </label>

                    <button
                        id="layoutToggle"
                        type="button"
                        className="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition"
                        onClick={() => setSideBySide((value) => !value)}
                    >
                        {sideBySide ? 'Stacked' : 'Side by Side'}
                    </button>

                    <button
                        id="widthToggle"
                        type="button"
                        className="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition"
                        onClick={() => setWideMode((value) => !value)}
                    >
                        {wideMode ? 'Normal Mode' : 'Wide Mode'}
                    </button>

                    <div className="h-6 w-px bg-gray-400 dark:bg-gray-600"/>

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
                            className="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition"
                            onClick={handlePickAudio}
                        >
                            Pick Audio
                        </button>
                        <button
                            id="audioPlay"
                            type="button"
                            className="px-3 py-1.5 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition disabled:opacity-50 disabled:cursor-not-allowed"
                            disabled={!audioReady}
                            onClick={handleAudioPlay}
                        >
                            Play
                        </button>
                        <button
                            id="audioPause"
                            type="button"
                            className="px-3 py-1.5 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition disabled:opacity-50 disabled:cursor-not-allowed"
                            disabled={!audioReady}
                            onClick={handleAudioPause}
                        >
                            Pause
                        </button>
                        <button
                            id="audioStop"
                            type="button"
                            className="px-3 py-1.5 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition disabled:opacity-50 disabled:cursor-not-allowed"
                            disabled={!audioReady}
                            onClick={handleAudioStop}
                        >
                            Stop
                        </button>
                        <span id="audioStatus" className="text-xs text-gray-600 dark:text-gray-400">
                            {audioStatus}
                        </span>
                    </div>
                </div>
            </header>

            <main className="flex-1 overflow-y-auto bg-orange-100 dark:bg-gray-800 pb-5">
                <div
                    id="contentContainer"
                    className={[
                        'mx-auto px-4 sm:px-6 lg:px-8 py-6',
                        wideMode ? '' : 'max-w-7xl',
                    ].join(' ')}
                    style={wideMode ? {width: '95%'} : undefined}
                >
                    <div className="bg-white dark:bg-gray-700 rounded-md shadow-sm border-2 border-gray-400 dark:border-gray-600 p-6">
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
                    </div>
                </div>
            </main>

            <audio
                ref={audioRef}
                id="readerAudio"
                className="hidden"
                onEnded={() => setAudioStatus('Ended')}
            />
        </div>
    );
}
