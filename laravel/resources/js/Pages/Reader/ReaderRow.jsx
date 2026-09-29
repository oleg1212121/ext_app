import {memo, useMemo} from 'react';
import WordText from '../../Components/WordText.jsx';

const NO_IMAGES = [];

// A book illustration: the picture with its optional caption beneath. No
// transitions or hover styling — the reader's row perf contract (fixed
// identity, memo-friendly, no layout thrash) applies.
const IllustrationFigure = ({image}) => (
    <figure className="text-center">
        <img
            src={image.url}
            alt={image.caption || ''}
            loading="lazy"
            decoding="async"
            width={image.width ?? undefined}
            height={image.height ?? undefined}
            className="mx-auto inline-block max-h-[60vh] w-auto max-w-full rounded-sm"
        />
        {image.caption ? (
            <figcaption
                className="italic"
                style={{fontSize: '0.8em', lineHeight: 1.5, color: 'var(--color-ink-soft)'}}
            >
                {image.caption}
            </figcaption>
        ) : null}
    </figure>
);

// Memoized: rows are token-heavy, and the reader re-renders for plenty of
// reasons (audio status, page picker, sibling row expansion) that leave an
// untouched row's props identical.
function ReaderRow({
    index,
    primary,
    translation,
    rowKey,
    primaryImages = NO_IMAGES,
    translationImages = NO_IMAGES,
    showAll,
    sideBySide,
    fontSize,
    popupFontSize,
    expanded,
    onToggle,
    wordMap,
    primaryHighlightable,
    translationWordMap,
    translationHighlightable,
    highlight,
    onWordProgress,
    primaryExplainable = false,
    translationExplainable = false,
    primarySide = null,
    explain = null,
    primaryStressed = null,
    translationStressed = null,
    primaryIntonations = null,
    translationIntonations = null,
    showStress = false,
}) {
    // The translation column lives on the other entity match side than the
    // primary one; without a primary side (single-language text) it has none.
    const translationSide = primarySide === 'a' ? 'b' : primarySide === 'b' ? 'a' : null;
    // Stable payload identities: without them, memoized WordText instances
    // would re-render on every parent pass. Passed through whenever the side
    // is language-eligible — enabled or not — so keyless users still get the
    // word popup's tab strip and its Models used popup.
    const primaryExplainPayload = useMemo(
        () => (primaryExplainable && primarySide ? explain ?? undefined : undefined),
        [explain, primaryExplainable, primarySide],
    );
    const translationExplainPayload = useMemo(
        () => (translationExplainable && translationSide ? explain ?? undefined : undefined),
        [explain, translationExplainable, translationSide],
    );
    // An image-only side has empty text but still has content to show (and
    // to reveal), so the illustration counts toward "has translation".
    const hasTranslation = translation.trim() !== '' || translationImages.length > 0;
    const isVisible = showAll || expanded;

    // Plain-text fast path: when the server ships no word map for a side
    // (entity_words still building, or text not indexed), WordText's
    // tokenizer/segment machinery has nothing to do — render the raw string
    // instead. WordText joins sentences (split on "\n") with spaces, so the
    // fallback replaces newlines the same way to stay visually identical.
    const primaryInteractive = Object.keys(wordMap ?? {}).length > 0;
    const translationInteractive = Object.keys(translationWordMap ?? {}).length > 0;

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
                        fontFamily: 'var(--font-serif)',
                    }}
                >
                    {primaryImages.map((image) => (
                        <IllustrationFigure key={image.id} image={image}/>
                    ))}
                    {primaryInteractive ? (
                        <WordText
                            text={primary}
                            wordMap={wordMap}
                            highlight={highlight && primaryHighlightable}
                            rowKey={rowKey}
                            onWordProgress={onWordProgress}
                            className="whitespace-pre-line"
                            popupFontSize={popupFontSize}
                            side={primarySide ?? undefined}
                            explain={primaryExplainPayload}
                            stressed={primaryStressed}
                            intonations={primaryIntonations}
                            showStress={showStress}
                        />
                    ) : (showStress && primaryStressed ? primaryStressed : primary).replaceAll('\n', ' ')}
                </div>

                {sideBySide && hasTranslation && (
                    // Hover styling is CSS-only (.group:hover in app.css);
                    // the data attribute covers the revealed state.
                    <span aria-hidden="true" className="gutter-cane hidden lg:block row-span-2 self-stretch h-full min-h-[3rem]" data-row-hover={isVisible ? 'true' : 'false'}/>
                )}

                {hasTranslation && (
                    <div
                        className={[
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
                            {translationImages.map((image) => (
                                <IllustrationFigure key={image.id} image={image}/>
                            ))}
                            {translationInteractive ? (
                                <WordText
                                    text={translation}
                                    wordMap={translationWordMap}
                                    highlight={highlight && translationHighlightable}
                                    rowKey={rowKey}
                                    onWordProgress={onWordProgress}
                                    popupFontSize={popupFontSize}
                                    side={translationSide ?? undefined}
                                    explain={translationExplainPayload}
                                    stressed={translationStressed}
                                    intonations={translationIntonations}
                                    showStress={showStress}
                                />
                            ) : (showStress && translationStressed ? translationStressed : translation).replaceAll('\n', ' ')}
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
