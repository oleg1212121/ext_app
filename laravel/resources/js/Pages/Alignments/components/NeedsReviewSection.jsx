import Pagination from './Pagination.jsx';

function targetPage(item, rowsPerPage) {
    return Math.max(Math.ceil(item.rank / rowsPerPage), 1);
}

export default function NeedsReviewSection({expanded, onToggle, items, meta, busy, rowsPerPage, onPageChange, onRowClick}) {
    return (
        <section className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
            <button
                type="button"
                onClick={onToggle}
                className="flex w-full items-center justify-between gap-2 bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] px-3 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
                aria-expanded={expanded}
            >
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    Needs review
                </span>
                <span className="font-mono text-[10px] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {meta.total}
                </span>
            </button>

            {expanded && (
                <>
                    <div className="flex flex-col divide-y divide-[var(--wbench-rule)] dark:divide-[var(--wbench-rule-night)]">
                        {items.map((item) => {
                            const page = targetPage(item, rowsPerPage);

                            return (
                                <button
                                    key={item.key}
                                    type="button"
                                    onClick={() => onRowClick(item, page)}
                                    className="grid grid-cols-1 gap-x-3 gap-y-0.5 px-3 py-2 text-left hover:bg-[var(--wbench-paper-deep)] dark:hover:bg-[var(--wbench-paper-deep-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] sm:grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-baseline"
                                >
                                    <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                        #{item.rank}
                                        {item.one_sided && (
                                            <span className="ml-1.5 text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]">
                                                1-sided
                                            </span>
                                        )}
                                    </span>
                                    <span className="font-serif text-[13px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] line-clamp-2">
                                        {item.en_part || '—'}
                                    </span>
                                    <span className="font-serif text-[13px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] line-clamp-2">
                                        {item.ru_part || '—'}
                                    </span>
                                    <span className="font-mono text-[10px] tabular-nums text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                        {item.similarity !== null ? `sim ${Number(item.similarity).toFixed(4)}` : 'sim —'} · → p. {page}
                                    </span>
                                </button>
                            );
                        })}

                        {items.length === 0 && (
                            <p className="px-3 py-8 text-center font-serif text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                Nothing needs review.
                            </p>
                        )}
                    </div>

                    <Pagination meta={meta} busy={busy} onPage={onPageChange} onPerPage={null} />
                </>
            )}
        </section>
    );
}
