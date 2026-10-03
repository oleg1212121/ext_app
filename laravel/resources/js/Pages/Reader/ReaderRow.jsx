import {memo} from 'react';
import WordText from '../../Components/WordText.jsx';
import {sideHasContent, sideSentences} from '../../lib/readingRows.mjs';

// Memoized: rows are token-heavy, and the reader re-renders for plenty of
// reasons (audio status, page picker, sibling row expansion) that leave an
// untouched row's props identical.
//
// A row is one Reading row object (ADR 0060: {key, a, b} canonical sides of
// sentence objects); first/second are display-column descriptors the reader
// page derives once per render — {side, wordMap, highlightable, explainable}.
// `second` is null for single-language texts.
function ReaderRow({
    index,
    row,
    first,
    second = null,
    showAll,
    sideBySide,
    fontSize,
    popupFontSize,
    expanded,
    onToggle,
    highlight,
    onWordProgress,
    showStress = false,
    showPhrasal = false,
    explain = null,
}) {
    const firstSentences = sideSentences(row, first.side);
    const secondSentences = second !== null ? sideSentences(row, second.side) : [];

    // Keyless users still get the word popup's tab strip and its Models used
    // popup, so the explain payload passes through whenever the side is
    // language-eligible — enabled or not.
    const firstExplain = first.explainable ? explain ?? undefined : undefined;
    const secondExplain = second?.explainable ? explain ?? undefined : undefined;

    // An image-only second side still has content to show (and to reveal),
    // so the illustration counts toward "has translation".
    const hasSecond = second !== null && sideHasContent(row, second.side);
    const isVisible = showAll || expanded;

    const toggleable = hasSecond && !showAll;

    // A div with role="button" (not a real <button>) so the interactive word
    // tokens inside stay valid HTML; word clicks stopPropagation, so the row
    // only toggles when the line itself is activated.
    const handleActivation = (event) => {
        if (!toggleable) {
            return;
        }
        if (event.type === 'click' || event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            onToggle(index);
        }
    };

    return (
        <li
            className="reader-row group relative"
        >
            <div
                className={[
                    'grid gap-x-8 gap-y-3 py-4',
                    sideBySide ? 'lg:grid-cols-[1fr_1px_1fr] lg:items-start' : 'grid-cols-1',
                ].join(' ')}
            >
                <div
                    role={toggleable ? 'button' : undefined}
                    tabIndex={toggleable ? 0 : undefined}
                    data-index={index}
                    onClick={handleActivation}
                    onKeyDown={handleActivation}
                    aria-expanded={toggleable ? isVisible : undefined}
                    className={[
                        'primary-line block text-left w-full',
                        'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]',
                        toggleable ? 'cursor-pointer' : 'cursor-default',
                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] focus-visible:rounded-sm',
                    ].join(' ')}
                    style={{
                        lineHeight: 1.7,
                        fontSize: `${fontSize}px`,
                        fontFamily: 'var(--font-reading)',
                    }}
                >
                    <WordText
                        sentences={firstSentences}
                        wordMap={first.wordMap}
                        highlight={highlight && first.highlightable}
                        rowKey={row.key}
                        onWordProgress={onWordProgress}
                        className="whitespace-pre-line"
                        popupFontSize={popupFontSize}
                        explain={firstExplain}
                        showStress={showStress}
                        showPhrasal={showPhrasal}
                    />
                </div>

                {sideBySide && hasSecond && (
                    // Hover styling is CSS-only (.group:hover in app.css);
                    // the data attribute covers the revealed state.
                    <span aria-hidden="true" className="gutter-cane hidden lg:block row-span-2 self-stretch h-full min-h-[3rem]" data-row-hover={isVisible ? 'true' : 'false'}/>
                )}

                {hasSecond && (
                    <div
                        className={[
                            isVisible ? 'opacity-100' : 'opacity-0 hidden',
                        ].join(' ')}
                        style={{
                            lineHeight: 1.7,
                            fontSize: `${Math.round(fontSize * 0.95)}px`,
                            fontFamily: 'var(--font-reading)',
                            color: 'var(--color-verdigris)',
                        }}
                        aria-hidden={!isVisible}
                    >
                        <div
                            className="whitespace-pre-line italic"
                            style={{
                                paddingLeft: sideBySide ? undefined : '1.25rem',
                                borderLeft: sideBySide ? undefined : '1px solid var(--color-verdigris)',
                            }}
                        >
                            <WordText
                                sentences={secondSentences}
                                wordMap={second.wordMap}
                                highlight={highlight && second.highlightable}
                                rowKey={row.key}
                                onWordProgress={onWordProgress}
                                popupFontSize={popupFontSize}
                                explain={secondExplain}
                                showStress={showStress}
                                showPhrasal={showPhrasal}
                            />
                        </div>
                    </div>
                )}
            </div>

            {!sideBySide && (
                <span
                    aria-hidden="true"
                    className="absolute left-0 top-4 bottom-4 w-px bg-[var(--color-hairline)] dark:bg-[var(--color-hairline-night)] opacity-0 group-hover:opacity-100"
                />
            )}
        </li>
    );
}

export default memo(ReaderRow);
