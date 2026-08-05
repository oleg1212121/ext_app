import {useEffect, useRef, useState} from 'react';

export default function ReaderRow({
    index,
    primary,
    translation,
    showAll,
    sideBySide,
    fontSize,
    expanded,
    onToggle,
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
                <button
                    type="button"
                    data-index={index}
                    disabled={showAll || !hasTranslation}
                    onClick={() => {
                        if (!showAll && hasTranslation) {
                            onToggle(index);
                        }
                    }}
                    className={[
                        'primary-line block text-left w-full',
                        'transition-colors duration-150',
                        'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]',
                        hasTranslation && !showAll ? 'cursor-pointer' : 'cursor-default',
                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] focus-visible:rounded-sm',
                    ].join(' ')}
                    style={{
                        lineHeight: 1.7,
                        fontSize: `${fontSize}px`,
                        fontFamily: 'var(--font-serif)',
                    }}
                    aria-expanded={hasTranslation ? isVisible : undefined}
                >
                    <span className="whitespace-pre-line">{primary}</span>
                </button>

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
                            {translation}
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