export default function Pagination({meta, onPage, onPerPage, perPageOptions, busy}) {
    const {current_page, last_page, total, per_page} = meta;

    const pageBtn = (disabled) => [
        'inline-flex h-7 items-center px-2.5 font-mono text-[11px] uppercase tracking-[0.14em]',
        'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]',
        'hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm',
        disabled ? 'pointer-events-none opacity-40' : '',
    ].join(' ');

    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] px-3 py-1.5">
            <div className="flex items-center gap-2">
                <button type="button" disabled={busy || current_page <= 1} onClick={() => onPage(current_page - 1)} className={pageBtn(busy || current_page <= 1)}>
                    ← Prev
                </button>
                <span className="font-mono text-[11px] tabular-nums text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {current_page} / {Math.max(last_page, 1)}
                </span>
                <button type="button" disabled={busy || current_page >= last_page} onClick={() => onPage(current_page + 1)} className={pageBtn(busy || current_page >= last_page)}>
                    Next →
                </button>
            </div>

            {onPerPage && (
                <label className="flex items-center gap-2 font-mono text-[10px] uppercase tracking-[0.14em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    Per page
                    <select
                        value={per_page}
                        disabled={busy}
                        onChange={(e) => onPerPage(Number(e.target.value))}
                        className="h-7 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-1.5 font-mono text-[11px] text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] focus:outline-none focus:ring-1 focus:ring-[var(--wbench-accent)]"
                    >
                        {perPageOptions.map((n) => (
                            <option key={n} value={n}>
                                {n}
                            </option>
                        ))}
                    </select>
                </label>
            )}

            <span className="font-mono text-[11px] tabular-nums text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                {total} {total === 1 ? 'row' : 'rows'}
            </span>
        </div>
    );
}
