import React, {useCallback, useMemo, useState} from 'react';
import {createPortal} from 'react-dom';
import {segmentText} from '../lib/wordTokenizer.mjs';
import {stressOffsets} from '../lib/stressMarks.mjs';
import {FAMILIARITY_MAX, FAMILIARITY_STRONG_AT, FAMILIARITY_PROGRESS_AT, recordWordEvents} from '../lib/wordFamiliarity';
import WordPopup from './WordPopup.jsx';
import IllustrationFigure from './IllustrationFigure.jsx';

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

// Children for one segment: the characters at stress offsets (absolute into
// the sentence text) render inside .stress-mark spans; text content is never
// altered, so the segment's textContent stays the plain word.
function stressChildren(text, stress, base) {
    if (!stress) {
        return text;
    }
    const children = [];
    let cursor = 0;
    for (const offset of stress) {
        const local = offset - base;
        if (local < cursor || local >= text.length) {
            continue;
        }
        if (local > cursor) {
            children.push(text.slice(cursor, local));
        }
        children.push(<span key={offset} className="stress-mark">{text[local]}</span>);
        cursor = local + 1;
    }
    if (cursor < text.length) {
        children.push(text.slice(cursor));
    }
    return children.length > 0 ? children : text;
}

/**
 * Renders one reading row side (ADR 0060): the side's sentence objects in
 * document order — illustrations as figures, text sentences split into
 * interactive dictionary words. Only tokens present in the word map
 * ({l_word: {w: wordId, s: familiarity|null}}) become interactive;
 * everything else is plain text. Ctrl+click opens the popup (plain clicks
 * do nothing); highlight = knowledge tinting, gated by the caller.
 *
 * Interactive tokens are role="button" spans, not real <button> elements:
 * Chromium treats button labels as widget chrome, so a double-click would
 * never produce a native text selection for browser extensions to read.
 * They stay focusable with the same keyboard contract a button had
 * (Ctrl+Enter/Ctrl+Space opens the popup).
 *
 * rowKey (optional) scopes this row side for familiarity bookkeeping: the
 * first popup lookup of a word within the row costs -2, credited once.
 *
 * Stress marks (ADR 0052): when showStress is on, characters stressed in a
 * sentence's `stressed` variant are wrapped in .stress-mark spans whose CSS
 * ::after draws the acute. The DOM always carries the plain `text` — never
 * the stressed string — so selection, copy/paste, double-click dictionary
 * extensions, and browser find only ever see original characters (no U+0301,
 * no е→ё). stressOffsets() maps the variant back onto the plain text; any
 * divergence renders that sentence plain.
 *
 * Phrasal verbs (ADR 0057): when showPhrasal is on, the tokens each hit
 * covers get a dotted underline with the matched headword as tooltip. Hit
 * spans index the sentence's plain text, which is what gets segmented.
 *
 * With `explain` ({sentenceId-carrying popup config}) present — enabled or
 * not — WordPopup gets an explain payload keyed by the clicked sentence's
 * entity sentence id, which every sentence object carries.
 */
function WordText({
    sentences = [],
    wordMap = {},
    highlight = true,
    rowKey,
    onWordProgress,
    className,
    popupFontSize,
    explain,
    showStress = false,
    showPhrasal = false,
    figureProps,
}) {
    const interactive = Object.keys(wordMap ?? {}).length > 0;

    // Display segments always come from the plain text; the stressed variant
    // is only consulted for mark placement, never rendered.
    const segmentLists = useMemo(
        () => sentences.map((sentence) => segmentText(sentence.text)),
        [sentences],
    );

    // Per sentence: a Set of offsets into the plain text whose characters
    // get a stress overlay, or null when the toggle is off / no variant /
    // no marks resolved.
    const stressMaps = useMemo(() => {
        if (!showStress) {
            return null;
        }
        return sentences.map((sentence) => {
            if (sentence.image !== undefined || !sentence.stressed) {
                return null;
            }
            const offsets = stressOffsets(sentence.text, sentence.stressed);
            return offsets.length > 0 ? new Set(offsets) : null;
        });
    }, [sentences, showStress]);

    // Per text sentence: token index -> matched headword, over the
    // key-bearing segments of the ORIGINAL text (hit spans index content).
    const phrasalMarks = useMemo(() => {
        if (!showPhrasal) {
            return null;
        }
        return sentences.map((sentence, index) => {
            const hits = sentence.phrasal;
            if (!Array.isArray(hits) || hits.length === 0) {
                return null;
            }
            const marks = new Map();
            let offset = 0;
            let tokenIndex = 0;
            for (const segment of segmentText(sentences[index].text)) {
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
    }, [sentences, showPhrasal]);

    const [popup, setPopup] = useState(null);

    const openPopup = useCallback((event, segment, sentenceId) => {
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
            sentenceId,
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
        sentenceId: popup.sentenceId,
        modelKey: explain.modelKey ?? null,
        enabled: explain.enabled === true,
        modelLabel: explain.modelLabel ?? null,
        followsAnswer: explain.followsAnswer === true,
        answerLabel: explain.answerLabel ?? null,
    } : undefined;

    const renderTextSentence = (sentence, index, renderToken) => {
        const segments = segmentLists[index];
        const phrasal = phrasalMarks !== null ? (phrasalMarks[index] ?? null) : null;
        const stress = stressMaps !== null ? (stressMaps[index] ?? null) : null;
        let tokenIndex = 0;
        // Segments tile the sentence text contiguously, so each segment's
        // base offset is the running total of the lengths before it.
        let charOffset = 0;
        return (
            <span className="inline">
                {segments.map((segment, segmentIndex) => {
                    const base = charOffset;
                    charOffset += segment.text.length;
                    if (segment.key !== null) {
                        const markLabel = phrasal !== null ? (phrasal.get(tokenIndex++) ?? null) : null;
                        const entry = wordMap[segment.key];
                        const markClass = markLabel !== null ? ' phrasal-hit' : '';
                        const children = stressChildren(segment.text, stress, base);
                        if (entry?.w) {
                            return renderToken(segment, segmentIndex, entry, markClass, markLabel, children);
                        }
                        if (markLabel !== null) {
                            return (
                                <span key={segmentIndex} className={'word-token phrasal-hit'} title={markLabel}>
                                    {children}
                                </span>
                            );
                        }
                        return <React.Fragment key={segmentIndex}>{children}</React.Fragment>;
                    }
                    return <React.Fragment key={segmentIndex}>{stressChildren(segment.text, stress, base)}</React.Fragment>;
                })}
            </span>
        );
    };

    const interactiveToken = (sentence, segment, index, entry, markClass, markLabel, children) => (
        <span
            key={index}
            role="button"
            tabIndex={0}
            className={tierClass(entry.s, highlight) + markClass}
            title={markLabel ?? undefined}
            onClick={(event) => openPopup(event, segment, sentence.id)}
            onKeyDown={(event) => {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                // Mirror the <button> this replaced: Enter/Space synthesized a
                // click (swallowed by openPopup), and only Ctrl+that click
                // opened the popup.
                event.stopPropagation();
                if (event.ctrlKey) {
                    event.preventDefault();
                    openPopup(event, segment, sentence.id);
                }
            }}
        >
            {children ?? segment.text}
        </span>
    );

    // segmentLists/phrasalMarks/stressMaps are indexed by position in the
    // full sentence list (illustrations included), so text sentences must
    // look up their memo by that same index — not by a text-only count.
    const firstTextIndex = sentences.findIndex((sentence) => sentence.image === undefined);

    return (
        <span className={className}>
            {sentences.map((sentence, index) => {
                if (sentence.image !== undefined) {
                    return <IllustrationFigure key={sentence.id} sentence={sentence} {...(figureProps ?? {})}/>;
                }
                const separator = index !== firstTextIndex ? ' ' : null;
                if (!interactive) {
                    // Plain fast path: no word map for this side (entity_words
                    // still building, or text not indexed) — render the plain
                    // text with stress overlays.
                    const stress = stressMaps !== null ? (stressMaps[index] ?? null) : null;
                    return (
                        <React.Fragment key={sentence.id}>
                            {separator}
                            <span className="inline">{stressChildren(sentence.text, stress, 0)}</span>
                        </React.Fragment>
                    );
                }
                return (
                    <React.Fragment key={sentence.id}>
                        {separator}
                        {renderTextSentence(sentence, index, (segment, segmentIndex, entry, markClass, markLabel, children) => interactiveToken(sentence, segment, segmentIndex, entry, markClass, markLabel, children))}
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
