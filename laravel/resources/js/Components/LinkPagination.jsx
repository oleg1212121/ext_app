import {Link} from '@inertiajs/react';

function PageLink({disabled, href, children}) {
    if (disabled) {
        return (
            <span className="inline-flex h-8 items-center px-3 font-sans text-sm text-[var(--wbench-ink-soft)]/40 dark:text-[var(--wbench-ink-soft-night)]/40 cursor-not-allowed">
                {children}
            </span>
        );
    }

    return (
        <Link
            href={href}
            preserveState
            preserveScroll
            className="inline-flex h-8 items-center px-3 font-sans text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
        >
            {children}
        </Link>
    );
}

export default function LinkPagination({meta, pageUrl}) {
    if (!meta || meta.last_page <= 1) {
        return null;
    }

    return (
        <nav aria-label="Pagination" className="flex items-center justify-between border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
            <PageLink disabled={meta.current_page <= 1} href={pageUrl(meta.current_page - 1)}>
                ← Prev
            </PageLink>
            <span className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                {meta.current_page} / {meta.last_page}
            </span>
            <PageLink disabled={meta.current_page >= meta.last_page} href={pageUrl(meta.current_page + 1)}>
                Next →
            </PageLink>
        </nav>
    );
}
