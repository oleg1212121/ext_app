import {useEffect, useRef, useState} from 'react';

function pageItems(current, last) {
    const pages = new Set([1, last]);

    for (let p = current - 2; p <= current + 2; p++) {
        if (p >= 1 && p <= last) {
            pages.add(p);
        }
    }

    const sorted = [...pages].sort((a, b) => a - b);
    const items = [];
    let prev = 0;

    for (const p of sorted) {
        if (p - prev > 1) {
            items.push('…');
        }
        items.push(p);
        prev = p;
    }

    return items;
}

function PerPageSelect({perPageOptions, perPage, busy, onPerPage}) {
    const [open, setOpen] = useState(false);
    const rootRef = useRef(null);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onPointerDown = (event) => {
            if (rootRef.current && !rootRef.current.contains(event.target)) {
                setOpen(false);
            }
        };

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    return (
        <div ref={rootRef} className="relative">
            <button
                type="button"
                aria-haspopup="listbox"
                aria-expanded={open}
                disabled={busy}
                onClick={() => setOpen((prev) => !prev)}
                className="inline-flex h-7 min-w-14 items-center justify-between gap-1 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-1.5 font-mono text-[11px] tabular-nums text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] focus:outline-none focus:ring-1 focus:ring-[var(--wbench-accent)] disabled:cursor-not-allowed"
            >
                <span>{perPage}</span>
                <svg viewBox="0 0 16 16" className="h-3 w-3 shrink-0 text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
                    <path d="M4 6l4 4 4-4" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
            </button>

            {open && (
                <div role="listbox" aria-label="Rows per page" className="absolute right-0 top-full z-10 mt-1 min-w-14 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] py-0.5 shadow-sm">
                    {perPageOptions.map((n) => (
                        <button
                            key={n}
                            type="button"
                            role="option"
                            aria-selected={n === perPage}
                            disabled={busy}
                            onClick={() => {
                                setOpen(false);
                                onPerPage(n);
                            }}
                            className={[
                                'flex w-full min-w-14 items-center justify-center px-2 py-1 font-mono text-[11px] tabular-nums focus:outline-none focus-visible:bg-[var(--wbench-paper-deep)] dark:focus-visible:bg-[var(--wbench-paper-deep-night)]',
                                n === perPage
                                    ? 'bg-[var(--wbench-accent)] text-white'
                                    : 'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] hover:bg-[var(--wbench-paper-deep)] dark:hover:bg-[var(--wbench-paper-deep-night)]',
                            ].join(' ')}
                        >
                            {n}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function Pagination({meta, onPage, onPerPage, perPageOptions, busy}) {
    const {current_page, last_page, total, per_page} = meta;

    const pageBtn = (disabled) => [
        'inline-flex h-7 items-center px-2.5 font-mono text-[11px] uppercase tracking-[0.14em]',
        'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]',
        'hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm',
        disabled ? 'pointer-events-none opacity-40' : '',
    ].join(' ');

    const pageNumberBtn = (page, disabled) => [
        'inline-flex h-7 min-w-7 items-center justify-center px-1 font-mono text-[11px] tabular-nums',
        page === current_page
            ? 'bg-[var(--wbench-accent)] text-white'
            : 'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm',
        disabled ? 'pointer-events-none opacity-40' : '',
    ].join(' ');

    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] px-3 py-1.5">
            <div className="flex items-center gap-0.5">
                <button type="button" disabled={busy || current_page <= 1} onClick={() => onPage(current_page - 1)} className={pageBtn(busy || current_page <= 1)}>
                    ← Prev
                </button>

                {pageItems(current_page, Math.max(last_page, 1)).map((item, index) => (
                    item === '…' ? (
                        <span key={`gap-${index}`} className="px-1 font-mono text-[11px] text-[var(--wbench-ink-soft)]/60 dark:text-[var(--wbench-ink-soft-night)]/60">
                            …
                        </span>
                    ) : (
                        <button
                            key={item}
                            type="button"
                            aria-current={item === current_page ? 'page' : undefined}
                            aria-label={`Go to page ${item}`}
                            disabled={busy || item === current_page}
                            onClick={() => onPage(item)}
                            className={pageNumberBtn(item, busy || item === current_page)}
                        >
                            {item}
                        </button>
                    )
                ))}

                <button type="button" disabled={busy || current_page >= last_page} onClick={() => onPage(current_page + 1)} className={pageBtn(busy || current_page >= last_page)}>
                    Next →
                </button>
            </div>

            {onPerPage && (
                <label className="flex items-center gap-2 font-mono text-[10px] uppercase tracking-[0.14em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    Per page
                    <PerPageSelect perPageOptions={perPageOptions} perPage={per_page} busy={busy} onPerPage={onPerPage} />
                </label>
            )}

            <span className="font-mono text-[11px] tabular-nums text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                {total} {total === 1 ? 'row' : 'rows'}
            </span>
        </div>
    );
}
