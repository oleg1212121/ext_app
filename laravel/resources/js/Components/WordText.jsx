import React, {useCallback, useLayoutEffect, useMemo, useRef, useState} from 'react';
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

// Mirror of the tokenizer's word pattern: the gaps between key segments can
// still pack single-letter words (я, о, а) that a stress mark can land on.
const WORD_RUN_PATTERN = /[\p{L}\p{M}]+(?:['’\-][\p{L}\p{M}]+)*/gu;

const renderStressMarks = (offsets) => offsets.map((offset) => (
    <span key={offset} className="stress-mark" data-offset={offset} aria-hidden="true"/>
));

/**
 * Sub-pieces of one segment under the stress overlay: pieces containing a
 * stress offset become "hosts" — a span keeping the word's text as ONE
 * intact text node plus empty, absolutely-positioned mark children (offsets
 * are relative to the piece text). Words are never split into multiple text
 * nodes, so selection, copy/paste, and double-click dictionary extensions
 * (Yomitan reads per text node) always see whole original words.
 */
function stressPieces(segment, base, marks) {
    if (!marks) {
        return null;
    }
    const within = [];
    for (const offset of marks) {
        if (offset >= base && offset < base + segment.text.length) {
            within.push(offset - base);
        }
    }
    if (within.length === 0) {
        return null;
    }
    if (segment.key !== null) {
        // A key segment is one word: the whole token hosts its marks.
        return [{text: segment.text, host: true, offsets: within}];
    }
    // A gap can pack several single-letter words around punctuation — split
    // it at word boundaries and host only the marked words.
    const pieces = [];
    let cursor = 0;
    for (const match of segment.text.matchAll(WORD_RUN_PATTERN)) {
        const start = match.index;
        const end = start + match[0].length;
        if (start > cursor) {
            pieces.push({text: segment.text.slice(cursor, start), host: false});
        }
        const offsets = within
            .filter((offset) => offset >= start && offset < end)
            .map((offset) => offset - start);
        pieces.push({text: match[0], host: offsets.length > 0, offsets});
        cursor = end;
    }
    if (cursor < segment.text.length) {
        pieces.push({text: segment.text.slice(cursor), host: false});
    }
    return pieces;
}

// Content of a segment that renders as bare text (no word-token span): host
// pieces get their own span grouping the word's text with its empty mark
// children; everything else stays plain text.
function stressContent(segment, pieces) {
    if (pieces === null) {
        return segment.text;
    }
    return pieces.map((piece, pieceIndex) => piece.host ? (
        <span key={pieceIndex} className="stress-host">
            {piece.text}
            {renderStressMarks(piece.offsets)}
        </span>
    ) : (
        <React.Fragment key={pieceIndex}>{piece.text}</React.Fragment>
    ));
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
 * Stress marks (ADR 0052): when showStress is on, characters stressed in a
 * sentence's `stressed` variant get an accent drawn above them. The DOM
 * text is never altered and words are never split — each stressed word
 * hosts empty, absolutely-positioned .stress-mark children whose horizontal
 * position is measured from the stressed glyph, so selection, copy/paste,
 * double-click dictionary extensions, and browser find only ever see whole
 * original words (no U+0301, no е→ё). stressOffsets() maps the variant back
 * onto the plain text; any divergence renders that sentence plain.
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

    const rootRef = useRef(null);

    // Pin each rendered mark over its stressed glyph, in both axes, against
    // the sentence-level positioned block (mark.offsetParent). The inline
    // host word cannot serve as the anchor: once it wraps across lines, its
    // union bounding rect no longer matches the box the browser anchors
    // abspos children of a fragmented inline to, so a mark could land a
    // line-start offset away — past the column edge, widening the scrollable
    // overflow. A one-character Range never spans lines, so the glyph rect
    // always sits on the mark's own line. Measuring on mount/structural
    // change, on web-font loads, and when the parent block resizes
    // (font-size settings, zoom, side reveal) covers every case where the
    // offsets can actually change.
    useLayoutEffect(() => {
        const root = rootRef.current;
        if (!root || stressMaps === null) {
            return;
        }
        const measure = () => {
            const range = document.createRange();
            for (const mark of root.querySelectorAll('.stress-mark')) {
                const host = mark.parentElement;
                const textNode = host !== null
                    ? Array.from(host.childNodes).find((node) => node.nodeType === Node.TEXT_NODE && node.data.length > 0)
                    : null;
                const offset = Number(mark.dataset.offset);
                if (textNode === null || !(offset >= 0 && offset < textNode.data.length)) {
                    continue;
                }
                const block = mark.offsetParent;
                if (block === null) {
                    // display:none subtree — unmeasurable until revealed,
                    // where the resize observer re-measures.
                    continue;
                }
                range.setStart(textNode, offset);
                range.setEnd(textNode, offset + 1);
                const charRect = range.getBoundingClientRect();
                const blockRect = block.getBoundingClientRect();
                mark.style.left = `${charRect.left + charRect.width / 2 - blockRect.left}px`;
                // Vertical base only — .stress-mark adds its hand-tuned
                // offset in CSS; an inline style.top would override it.
                mark.style.setProperty('--stress-mark-line-top', `${charRect.top - blockRect.top}px`);
            }
        };
        measure();
        let frame = 0;
        const observer = new ResizeObserver(() => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(measure);
        });
        if (root.parentElement) {
            observer.observe(root.parentElement);
        }
        document.fonts?.ready.then(measure);
        document.fonts?.addEventListener('loadingdone', measure);
        return () => {
            observer.disconnect();
            cancelAnimationFrame(frame);
            document.fonts?.removeEventListener('loadingdone', measure);
        };
    }, [segmentLists, stressMaps, interactive]);

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
        const phrasal = interactive && phrasalMarks !== null ? (phrasalMarks[index] ?? null) : null;
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
                    const pieces = stressPieces(segment, base, stress);
                    if (segment.key !== null) {
                        const markLabel = phrasal !== null ? (phrasal.get(tokenIndex++) ?? null) : null;
                        const entry = wordMap[segment.key];
                        const markClass = markLabel !== null ? ' phrasal-hit' : '';
                        if (entry?.w) {
                            return renderToken(segment, segmentIndex, entry, markClass, markLabel, pieces);
                        }
                        if (markLabel !== null) {
                            return (
                                <span key={segmentIndex} className={'word-token phrasal-hit'} title={markLabel}>
                                    {stressContent(segment, pieces)}
                                </span>
                            );
                        }
                    }
                    return <React.Fragment key={segmentIndex}>{stressContent(segment, pieces)}</React.Fragment>;
                })}
            </span>
        );
    };

    const interactiveToken = (sentence, segment, index, entry, markClass, markLabel, pieces) => (
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
            {pieces === null ? segment.text : (
                <React.Fragment>
                    {segment.text}
                    {pieces.flatMap((piece) => renderStressMarks(piece.offsets))}
                </React.Fragment>
            )}
        </span>
    );

    // segmentLists/phrasalMarks/stressMaps are indexed by position in the
    // full sentence list (illustrations included), so text sentences must
    // look up their memo by that same index — not by a text-only count.
    const firstTextIndex = sentences.findIndex((sentence) => sentence.image === undefined);

    return (
        <span ref={rootRef} className={className}>
            {sentences.map((sentence, index) => {
                if (sentence.image !== undefined) {
                    return <IllustrationFigure key={sentence.id} sentence={sentence} {...(figureProps ?? {})}/>;
                }
                const separator = index !== firstTextIndex ? ' ' : null;
                return (
                    <React.Fragment key={sentence.id}>
                        {separator}
                        {renderTextSentence(sentence, index, (segment, segmentIndex, entry, markClass, markLabel, pieces) => interactiveToken(sentence, segment, segmentIndex, entry, markClass, markLabel, pieces))}
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
