import React, {useCallback, useMemo, useState} from 'react';
import {createPortal} from 'react-dom';
import {segmentText} from '../lib/wordTokenizer.mjs';
import WordPopup from './WordPopup.jsx';

function tierClass(status, highlight) {
    if (!highlight || status === 'known') {
        return 'word-token';
    }
    if (status === 'learning' || status === 'solved') {
        return 'word-token word-progress';
    }

    return 'word-token word-unknown';
}

/**
 * Renders text split into interactive dictionary words. Only tokens present
 * in the word map ({l_word: {w: wordId, s: status|null}}) become clickable;
 * everything else is plain text. Highlight = knowledge tinting, gated by the
 * caller (setting + language eligibility).
 */
export default function WordText({text, wordMap = {}, highlight = true, onWordProgress, className}) {
    const segments = useMemo(() => segmentText(text ?? ''), [text]);
    const [popup, setPopup] = useState(null);

    const openPopup = useCallback((event, segment) => {
        event.stopPropagation();
        const entry = wordMap[segment.key];
        if (!entry?.w) {
            return;
        }
        setPopup({
            wordId: entry.w,
            surface: segment.key,
            status: entry.s,
            rect: event.currentTarget.getBoundingClientRect(),
        });
    }, [wordMap]);

    const handleProgress = useCallback((key, status) => {
        setPopup(null);
        onWordProgress?.(key, status);
    }, [onWordProgress]);

    return (
        <span className={className}>
            {segments.map((segment, index) => (
                wordMap[segment.key]?.w ? (
                    <button
                        key={index}
                        type="button"
                        className={tierClass(wordMap[segment.key].s, highlight)}
                        onClick={(event) => openPopup(event, segment)}
                    >
                        {segment.text}
                    </button>
                ) : (
                    <React.Fragment key={index}>{segment.text}</React.Fragment>
                )
            ))}
            {popup && createPortal(
                <WordPopup
                    wordId={popup.wordId}
                    surface={popup.surface}
                    status={popup.status}
                    rect={popup.rect}
                    onClose={() => setPopup(null)}
                    onProgress={handleProgress}
                />,
                document.body,
            )}
        </span>
    );
}
