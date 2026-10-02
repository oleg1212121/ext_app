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
 * rowKey and an `explain` ({enabled, modelKey, modelLabel, followsAnswer,
 * answerLabel}) present — enabled or not — WordPopup gets an `explain`
 * payload; the backend rebuilds the same sentence list, so
 * the index is exact. rowKey's prefix selects the backend source: "mm:{id}"
 * is a meaning match row (side required), anything else ("es:{id}") is a
 * bare entity sentence (side unused).
 *
 * Stress marks (ADR 0052): `stressed` is the same side's "\n"-joined
 * stressed variant (sentence-aligned 1:1, or null). When showStress is on
 * and a sentence has a variant, the variant is displayed instead — the
 * word map still resolves because keys strip combining marks.
 *
 * Phrasal verbs (ADR 0057): `phrasal` is one entry per sentence — the hit
 * list ({verb, particles, start, end, phrase} char spans indexing content)
 * or null — or null when the side has no hits. When showPhrasal is on, the
 * tokens each hit covers get a dotted underline with the matched headword
 * as tooltip. Hit spans index the plain content, so token indexes are
 * computed from the original sentence; the stressed variant keeps the token
 * sequence (marks attach inside tokens), so the indexes transfer.
 */
function WordText({text, wordMap = {}, highlight = true, rowKey, onWordProgress, className, popupFontSize, side, explain, stressed = null, showStress = false, phrasal = null, showPhrasal = false}) {
    const sentences = useMemo(() => String(text ?? '').split('\n'), [text]);
    const stressedSentences = useMemo(() => (stressed !== null ? String(stressed).split('\n') : null), [stressed]);
    const useStressed = showStress && stressedSentences !== null && stressedSentences.length === sentences.length;
    const displaySentences = useMemo(
        () => sentences.map((sentence, index) => (useStressed ? (stressedSentences[index] ?? sentence) : sentence)),
        [sentences, stressedSentences, useStressed],
    );
    const sentenceSegments = useMemo(
        () => displaySentences.map((sentence) => segmentText(sentence)),
        [displaySentences],
    );

    // Per sentence: token index -> matched headword, over the key-bearing
    // segments of the ORIGINAL text (hit spans index content).
    const phrasalMarks = useMemo(() => {
        if (!showPhrasal || !Array.isArray(phrasal)) {
            return null;
        }
        return sentences.map((sentence, sentenceIndex) => {
            const hits = phrasal[sentenceIndex];
            if (!Array.isArray(hits) || hits.length === 0) {
                return null;
            }
            const marks = new Map();
            let offset = 0;
            let tokenIndex = 0;
            for (const segment of segmentText(sentence)) {
                const length = segment.text.length;
                if (segment.key !== null) {
                    for (const hit of hits) {
                        if (hit && offset < hit.end && offset + length > hit.start) {
                            marks.set(tokenIndex, hit.phrase ?? [hit.verb, ...(hit.particles ?? [])].join(' '));
                        }
                    }
                    tokenIndex += 1;
                }
                offset += length;
            }
            return marks.size > 0 ? marks : null;
        });
    }, [sentences, phrasal, showPhrasal]);
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

    // The payload is built whenever `explain` exists — enabled or not — so
    // the word popup's tab strip (and its Models used popup) also reaches
    // keyless users; `enabled` rides along for the guidance states.
    const explainPayload = popup && rowKey && explain ? {
        rowKind: rowKey.startsWith('mm:') ? 'mm' : 'es',
        rowId: Number(rowKey.slice(3)),
        side: side ?? null,
        sentenceIndex: popup.sentenceIndex,
        modelKey: explain.modelKey ?? null,
        enabled: explain.enabled === true,
        modelLabel: explain.modelLabel ?? null,
        followsAnswer: explain.followsAnswer === true,
        answerLabel: explain.answerLabel ?? null,
    } : undefined;

    return (
        <span className={className}>
            {sentenceSegments.map((segments, sentenceIndex) => {
                const marks = phrasalMarks?.[sentenceIndex] ?? null;
                let tokenIndex = 0;
                return (
                <React.Fragment key={sentenceIndex}>
                    {sentenceIndex > 0 && ' '}
                    <span className="inline">
                        {segments.map((segment, index) => {
                            if (segment.key !== null) {
                                const markLabel = marks !== null ? (marks.get(tokenIndex++) ?? null) : null;
                                const entry = wordMap[segment.key];
                                const markClass = markLabel !== null ? ' phrasal-hit' : '';
                                if (entry?.w) {
                                    return (
                                        <span
                                            key={index}
                                            role="button"
                                            tabIndex={0}
                                            className={tierClass(entry.s, highlight) + markClass}
                                            title={markLabel ?? undefined}
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
                                    );
                                }
                                if (markLabel !== null) {
                                    return (
                                        <span key={index} className={'word-token phrasal-hit'} title={markLabel}>
                                            {segment.text}
                                        </span>
                                    );
                                }
                                return <React.Fragment key={index}>{segment.text}</React.Fragment>;
                            }
                            return <React.Fragment key={index}>{segment.text}</React.Fragment>;
                        })}
                    </span>
                </React.Fragment>
                );
            })}
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
