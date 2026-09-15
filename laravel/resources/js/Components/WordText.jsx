import React, {useCallback, useMemo, useState} from 'react';
import {createPortal} from 'react-dom';
import {segmentText} from '../lib/wordTokenizer.mjs';
import {FAMILIARITY_MAX, FAMILIARITY_STRONG_AT, recordWordEvents} from '../lib/wordFamiliarity';
import WordPopup from './WordPopup.jsx';

function tierClass(familiarity, highlight) {
    if (!highlight || (familiarity ?? 0) >= FAMILIARITY_MAX) {
        return 'word-token';
    }
    if (familiarity >= FAMILIARITY_STRONG_AT) {
        return 'word-token word-progress-strong';
    }
    if (familiarity >= 1) {
        return 'word-token word-progress';
    }

    return 'word-token word-unknown';
}

/**
 * Renders text split into interactive dictionary words. Only tokens present
 * in the word map ({l_word: {w: wordId, s: familiarity|null}}) become
 * clickable; everything else is plain text. Highlight = knowledge tinting,
 * gated by the caller (setting + language eligibility).
 *
 * rowKey (optional) scopes this sentence for familiarity bookkeeping: the
 * first popup lookup of a word within the row costs -2, credited once.
 */
export default function WordText({text, wordMap = {}, highlight = true, rowKey, onWordProgress, className}) {
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
            familiarity: entry.s,
            rect: event.currentTarget.getBoundingClientRect(),
        });
        if (rowKey) {
            recordWordEvents([{row_key: rowKey, kind: 'lookup', word_ids: [entry.w]}])
                .then((familiarity) => {
                    if (familiarity[entry.w] === undefined) {
                        return;
                    }
                    setPopup((current) => current?.wordId === entry.w
                        ? {...current, familiarity: familiarity[entry.w]}
                        : current);
                    onWordProgress?.(segment.key, familiarity[entry.w]);
                });
        }
    }, [wordMap, rowKey, onWordProgress]);

    const handleProgress = useCallback((key, familiarity) => {
        setPopup(null);
        onWordProgress?.(key, familiarity);
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
                    familiarity={popup.familiarity}
                    rect={popup.rect}
                    onClose={() => setPopup(null)}
                    onProgress={handleProgress}
                />,
                document.body,
            )}
        </span>
    );
}
