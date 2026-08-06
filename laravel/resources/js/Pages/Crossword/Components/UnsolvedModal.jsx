export default function UnsolvedModal({show, onClose, items, hasCrossword}) {
    if (!show) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div
                className="absolute inset-0 bg-[var(--wbench-ink)]/60"
                onClick={onClose}
                aria-hidden="true"
            />
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Unsolved words"
                className="relative bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] rounded-sm w-full md:w-5/6 lg:w-3/4 max-h-[90vh] overflow-y-auto p-6 sm:p-8"
            >
                <div className="flex justify-between items-start mb-6 pb-4 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                    <div className="flex flex-col gap-1">
                        <span className="font-[var(--font-wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            Crossword
                        </span>
                        <h2 className="font-[var(--font-wbench-serif)] text-2xl sm:text-3xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            Unsolved words
                        </h2>
                    </div>
                    <button
                        type="button"
                        className="text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)] transition-colors text-2xl leading-none focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                        onClick={onClose}
                        aria-label="Close"
                    >
                        ✕
                    </button>
                </div>

                <ol className="space-y-5 list-none">
                    {!hasCrossword && (
                        <li className="font-[var(--font-wbench-serif)] italic text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">No crossword loaded.</li>
                    )}
                    {items.map((item) => (
                        <li key={item.word} className="group relative pl-5">
                            <span aria-hidden="true" className="xword-edge absolute left-0 top-1/2 -translate-y-1/2 self-stretch h-8"/>
                            <span className="font-[var(--font-wbench-serif)] text-xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">{item.word}</span>
                            {item.definitions.map((definition, index) => (
                                <span
                                    key={index}
                                    className="block mt-1 font-[var(--font-wbench-serif)] italic text-base text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]"
                                >
                                    {definition}
                                </span>
                            ))}
                        </li>
                    ))}
                    {hasCrossword && items.length === 0 && (
                        <li className="font-[var(--font-wbench-serif)] italic text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]">All solved.</li>
                    )}
                </ol>
            </div>
        </div>
    );
}