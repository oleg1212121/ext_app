import React, {useCallback, useMemo, useState} from 'react';
import {createPortal} from 'react-dom';
import {segmentText} from '../lib/wordTokenizer.mjs';
import {FAMILIARITY_MAX, FAMILIARITY_STRONG_AT, FAMILIARITY_PROGRESS_AT, recordWordEvents} from '../lib/wordFamiliarity';
import WordPopup from './WordPopup.jsx';

// Memoized: a reading page mounts hundreds of WordText instances and its
// parent components re-render for reasons (streaming answer, audio status,
// sibling rows) that leave most instances' props identical.
function tierClass(familiarity, highlight) {
    if (!highlight) {
        return 'word-token';
    }
    if ((familiarity ?? 0) >= FAMILIARITY_MAX) {
        return 'word-token word-known';
    }
    if (familiarity >= FAMILIARITY_STRONG_AT) {
        return 'word-token word-progress-strong';
    }
    if (familiarity >= FAMILIARITY_PROGRESS_AT) {
        return 'word-token word-progress';
    }
    return 'word-token word-unknown';
}

/**
 * Renders text split into interactive dictionary words. Only tokens present
 * in the word map ({l_word: {w: wordId, s: familiarity|null}}) become
 * interactive; everything else is plain text. Ctrl+click opens the popup
 * (plain clicks do nothing); highlight = knowledge tinting, gated by the
 * caller (setting + language eligibility).
 *
 * Interactive tokens are role="button" spans, not real <button> elements:
 * Chromium treats button labels as widget chrome, so a double-click would
 * never produce a native text selection for browser extensions to read.
 * They stay focusable with the same keyboard contract a button had
 * (Ctrl+Enter/Ctrl+Space opens the popup).
 *
 * rowKey (optional) scopes this sentence for familiarity bookkeeping: the
 * first popup lookup of a word within the row costs -2, credited once.
 *
 * The text is one row side: its sentences joined with "\n" in document order
 * (MeaningMatchPresenter::sideText). Each sentence renders in its own inline
 * span — visually identical, but a click knows which sentence it hit. With
 * rowKey and an enabled `explain` ({enabled, modelKey}) present, WordPopup
 * gets an `explain` payload; the backend rebuilds the same sentence list, so
 * the index is exact. rowKey's prefix selects the backend source: "mm:{id}"
 * is a meaning match row (side required), anything else ("es:{id}") is a
 * bare entity sentence (side unused).
 */
function WordText({text, wordMap = {}, highlight = true, rowKey, onWordProgress, className, popupFontSize, side, explain}) {
    const sentences = useMemo(() => String(text ?? '').split('\n'), [text]);
    const sentenceSegments = useMemo(
        () => sentences.map((sentence) => segmentText(sentence)),
        [sentences],
    );
    const [popup, setPopup] = useState(null);

    const openPopup = useCallback((event, segment, sentenceIndex) => {
        event.stopPropagation();
        if (!event.ctrlKey) {
            return;
        }
        const entry = wordMap[segment.key];
        if (!entry?.w) {
            return;
        }
        setPopup({
            wordId: entry.w,
            surface: segment.key,
            familiarity: entry.s,
            rect: event.currentTarget.getBoundingClientRect(),
            sentenceIndex,
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

    const explainPayload = popup && rowKey && explain?.enabled ? {
        rowKind: rowKey.startsWith('mm:') ? 'mm' : 'es',
        rowId: Number(rowKey.slice(3)),
        side: side ?? null,
        sentenceIndex: popup.sentenceIndex,
        modelKey: explain.modelKey ?? null,
    } : undefined;

    return (
        <span className={className}>
            {sentenceSegments.map((segments, sentenceIndex) => (
                <React.Fragment key={sentenceIndex}>
                    {sentenceIndex > 0 && ' '}
                    <span className="inline">
                        {segments.map((segment, index) => (
                            wordMap[segment.key]?.w ? (
                                <span
                                    key={index}
                                    role="button"
                                    tabIndex={0}
                                    className={tierClass(wordMap[segment.key].s, highlight)}
                                    onClick={(event) => openPopup(event, segment, sentenceIndex)}
                                    onKeyDown={(event) => {
                                        if (event.key !== 'Enter' && event.key !== ' ') {
                                            return;
                                        }
                                        // Mirror the <button> this replaced: Enter/Space
                                        // synthesized a click (swallowed by openPopup), and
                                        // only Ctrl+that click opened the popup.
                                        event.stopPropagation();
                                        if (event.ctrlKey) {
                                            event.preventDefault();
                                            openPopup(event, segment, sentenceIndex);
                                        }
                                    }}
                                >
                                    {segment.text}
                                </span>
                            ) : (
                                <React.Fragment key={index}>{segment.text}</React.Fragment>
                            )
                        ))}
                    </span>
                </React.Fragment>
            ))}
            {popup && createPortal(
                <WordPopup
                    wordId={popup.wordId}
                    surface={popup.surface}
                    familiarity={popup.familiarity}
                    rect={popup.rect}
                    fontSize={popupFontSize}
                    explain={explainPayload}
                    onClose={() => setPopup(null)}
                    onProgress={handleProgress}
                />,
                document.body,
            )}
        </span>
    );
}

export default React.memo(WordText);
