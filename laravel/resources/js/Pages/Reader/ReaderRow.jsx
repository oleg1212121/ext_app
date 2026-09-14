import {useEffect, useRef, useState} from 'react';
import WordText from '../../Components/WordText.jsx';

export default function ReaderRow({
    index,
    primary,
    translation,
    showAll,
    sideBySide,
    fontSize,
    expanded,
    onToggle,
    wordMap,
    primaryHighlightable,
    translationWordMap,
    translationHighlightable,
    highlight,
    onWordProgress,
}) {
    const hasTranslation = translation.trim() !== '';
    const isVisible = showAll || expanded;
    const rowRef = useRef(null);
    const [hovered, setHovered] = useState(false);

    useEffect(() => {
        if (rowRef.current) {
            rowRef.current.style.setProperty('--fs', `${fontSize}px`);
        }
    }, [fontSize]);

    const toggleable = hasTranslation && !showAll;

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
            ref={rowRef}
            className="reader-row group relative"
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
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
                        'transition-colors duration-150',
                        'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]',
                        toggleable ? 'cursor-pointer' : 'cursor-default',
                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] focus-visible:rounded-sm',
                    ].join(' ')}
                    style={{
                        lineHeight: 1.7,
                        fontSize: `${fontSize}px`,
                        fontFamily: 'var(--font-serif)',
                    }}
                >
                    <WordText
                        text={primary}
                        wordMap={wordMap}
                        highlight={highlight && primaryHighlightable}
                        onWordProgress={onWordProgress}
                        className="whitespace-pre-line"
                    />
                </div>

                {sideBySide && hasTranslation && (
                    <span aria-hidden="true" className="gutter-cane hidden lg:block row-span-2 self-stretch h-full min-h-[3rem]" data-row-hover={hovered || isVisible ? 'true' : 'false'}/>
                )}

                {hasTranslation && (
                    <div
                        className={[
                            'transition-opacity duration-150',
                            isVisible ? 'opacity-100' : 'opacity-0 hidden',
                        ].join(' ')}
                        style={{
                            lineHeight: 1.7,
                            fontSize: `${Math.round(fontSize * 0.95)}px`,
                            fontFamily: 'var(--font-serif)',
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
                                text={translation}
                                wordMap={translationWordMap}
                                highlight={highlight && translationHighlightable}
                                onWordProgress={onWordProgress}
                            />
                        </div>
                    </div>
                )}
            </div>

            {!sideBySide && (
                <span
                    aria-hidden="true"
                    className="absolute left-0 top-4 bottom-4 w-px bg-[var(--color-hairline)] dark:bg-[var(--color-hairline-night)] opacity-0 group-hover:opacity-100 transition-opacity duration-150"
                />
            )}
        </li>
    );
}
