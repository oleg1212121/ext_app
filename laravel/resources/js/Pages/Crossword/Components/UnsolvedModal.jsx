export default function UnsolvedModal({show, onClose, items, hasCrossword}) {
    if (!show) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div
                className="absolute inset-0 bg-[var(--color-ink)]/60"
                onClick={onClose}
                aria-hidden="true"
            />
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Unsolved words"
                className="relative bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] rounded-sm w-full md:w-5/6 lg:w-3/4 max-h-[90vh] overflow-y-auto p-6 sm:p-8"
            >
                <div className="flex justify-between items-start mb-6 pb-4 border-b border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]">
                    <div className="flex flex-col gap-1">
                        <span className="font-serif italic text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)] text-[10px] tracking-[0.22em] uppercase">Crossword</span>
                        <h2 className="font-serif text-2xl sm:text-3xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">Unsolved words</h2>
                    </div>
                    <button
                        type="button"
                        className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60 hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)] transition-colors text-2xl leading-none focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] rounded-sm"
                        onClick={onClose}
                        aria-label="Close"
                    >
                        ✕
                    </button>
                </div>

                <ol className="space-y-5 list-none">
                    {!hasCrossword && (
                        <li className="font-serif italic text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">No crossword loaded.</li>
                    )}
                    {items.map((item) => (
                        <li key={item.word} className="group relative pl-5">
                            <span aria-hidden="true" className="ribbon-mark absolute left-0 top-1/2 -translate-y-1/2 self-stretch h-8"/>
                            <span className="font-serif text-xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">{item.word}</span>
                            {item.definitions.map((definition, index) => (
                                <span
                                    key={index}
                                    className="block mt-1 font-serif italic text-base text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70"
                                >
                                    {definition}
                                </span>
                            ))}
                        </li>
                    ))}
                    {hasCrossword && items.length === 0 && (
                        <li className="font-serif italic text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">All solved.</li>
                    )}
                </ol>
            </div>
        </div>
    );
}