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

// Intonation annotations arrive as {terminal, nuclear}; older payloads (and
// any hand-built ones) may carry the bare terminal string.
function normalizeAnnotation(value) {
    if (typeof value === 'string') {
        return {terminal: value, nuclear: null};
    }
    return value ?? null;
}

// The pitch-peak caret floating above the nuclear-stressed word. Accent color
// with a per-page fallback: the simulator defines --wbench-accent, the reader
// --color-vermilion; one rule serves both token sets.
const NuclearCaret = () => (
    <span
        aria-hidden="true"
        className="pointer-events-none absolute left-1/2 top-[-0.95em] -translate-x-1/2 text-[0.55em] leading-none"
        style={{color: 'var(--wbench-accent, var(--color-vermilion))'}}
    >
        ∧
    </span>
);

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
 * stressed variant (sentence-aligned 1:1, or null), `intonations` the
 * per-sentence intonation annotations ({terminal: 'rise'|'fall',
 * nuclear: {start, end}|null} — legacy bare 'rise'/'fall' strings are
 * accepted). When showStress is on and a sentence has a variant, the
 * variant is displayed instead — the word map still resolves because keys
 * strip combining marks.
 *
 * Intonation is independent of stress marks (shown when `showIntonation` is
 * on): the terminal arrow trails the sentence, and the nuclear-stressed
 * word — the pitch peak — carries a small caret above it. The stored
 * nuclear span points into the sentence's plain content, so it is mapped
 * through the plain sentence's segmentation; the stressed variant (combining
 * marks inside word tokens only) segments 1:1 with the plain text, so the
 * segment index transfers unchanged. A null nuclear degrades to arrow-only.
 */
function WordText({text, wordMap = {}, highlight = true, rowKey, onWordProgress, className, popupFontSize, side, explain, stressed = null, intonations = null, showStress = false, showIntonation = false}) {
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
    // Per sentence: the index of the display segment carrying the nuclear
    // caret (-1 = none). Computed against the plain sentence's segmentation
    // (the span is offset into plain content, not the stressed variant).
    const nuclearIndices = useMemo(
        () => sentences.map((sentence, index) => {
            const nuclear = normalizeAnnotation(intonations?.[index])?.nuclear;
            if (!nuclear) {
                return -1;
            }
            let offset = 0;
            const segments = segmentText(sentence);
            for (let s = 0; s < segments.length; s++) {
                const end = offset + segments[s].text.length;
                const covers = offset < nuclear.end && end > nuclear.start;
                offset = end;
                if (covers && segments[s].key !== null) {
                    return s;
                }
            }
            return -1;
        }),
        [sentences, intonations],
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
                const annotation = showIntonation ? normalizeAnnotation(intonations?.[sentenceIndex]) : null;
                return (
                <React.Fragment key={sentenceIndex}>
                    {sentenceIndex > 0 && ' '}
                    <span className="inline">
                        {segments.map((segment, index) => {
                            const entry = segment.key !== null ? wordMap[segment.key] : undefined;
                            const caret = showIntonation && nuclearIndices[sentenceIndex] === index ? <NuclearCaret/> : null;
                            if (entry?.w) {
                                return (
                                    <span
                                        key={index}
                                        role="button"
                                        tabIndex={0}
                                        className={caret !== null ? `relative ${tierClass(entry.s, highlight)}` : tierClass(entry.s, highlight)}
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
                                        {caret}
                                    </span>
                                );
                            }
                            if (caret === null) {
                                return <React.Fragment key={index}>{segment.text}</React.Fragment>;
                            }
                            return (
                                <span key={index} className="relative">
                                    {segment.text}
                                    {caret}
                                </span>
                            );
                        })}
                        {annotation?.terminal ? (
                            <span aria-hidden="true" className="ml-[0.15em] text-[0.75em] opacity-70">
                                {annotation.terminal === 'rise' ? '↗' : '↘'}
                            </span>
                        ) : null}
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
