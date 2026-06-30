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

    return (
        <article
            className={[
                'reader-row mb-4 pb-4 last:mb-0 last:pb-0 border-b border-gray-200 dark:border-gray-600 last:border-0',
                sideBySide ? 'md:grid md:grid-cols-2 md:gap-8 items-start' : '',
            ].join(' ')}
        >
            <button
                type="button"
                className="en w-full text-left cursor-pointer text-gray-800 dark:text-gray-200 hover:text-gray-600 dark:hover:text-gray-400 transition-colors duration-150 disabled:cursor-default"
                style={{
                    lineHeight: 1.8,
                    fontSize: `${fontSize}px`,
                    fontFamily: 'Georgia, Times New Roman, serif',
                }}
                data-index={index}
                onClick={() => {
                    if (!showAll && hasTranslation) {
                        onToggle(index);
                    }
                }}
                disabled={showAll || !hasTranslation}
            >
                <span className="whitespace-pre-line">{primary}</span>
            </button>
            {hasTranslation && (
                <div
                    className={[
                        'ru mt-3 text-emerald-700 dark:text-emerald-400 leading-relaxed transition-opacity duration-150',
                        isVisible ? '' : 'hidden',
                    ].join(' ')}
                    style={{
                        lineHeight: 1.75,
                        fontSize: `${fontSize * 0.95}px`,
                        fontFamily: 'Georgia, Times New Roman, serif',
                        opacity: isVisible ? 1 : 0,
                    }}
                >
                    <div className="pl-4 border-l-2 border-emerald-400 dark:border-emerald-500 whitespace-pre-line">
                        {translation}
                    </div>
                </div>
            )}
        </article>
    );
}
