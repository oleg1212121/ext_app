// A book illustration sentence (ADR 0050/0060): the picture with its
// optional caption beneath. Both reading surfaces render it through this
// module; each passes its typography. No transitions or hover styling —
// the reader's row perf contract (fixed identity, memo-friendly) applies.
export default function IllustrationFigure({
    sentence,
    figureClassName = 'text-center',
    imgClassName = 'max-h-[60vh]',
    captionClassName = 'italic',
    captionStyle,
}) {
    const caption = sentence.text ?? '';

    return (
        <figure className={figureClassName}>
            <img
                src={sentence.image.url}
                alt={caption || ''}
                loading="lazy"
                decoding="async"
                width={sentence.image.width ?? undefined}
                height={sentence.image.height ?? undefined}
                className={`mx-auto inline-block ${imgClassName} w-auto max-w-full rounded-sm`}
            />
            {caption ? (
                <figcaption className={captionClassName} style={captionStyle}>
                    {caption}
                </figcaption>
            ) : null}
        </figure>
    );
}
