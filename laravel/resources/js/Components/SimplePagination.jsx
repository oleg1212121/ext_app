import {Link} from '@inertiajs/react';
import {useI18n} from '../i18n';

const pageLinkClass = 'inline-flex h-8 items-center px-3 font-sans text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm';

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
            className={pageLinkClass}
        >
            {children}
        </Link>
    );
}

/**
 * Server-side prev/next pagination on ?page=N — the shared pattern behind
 * the detail pages' content lists (entity sentences, alignment's bilingual
 * rows). Hidden entirely while the content fits on one page.
 */
export default function SimplePagination({meta, baseUrl}) {
    const {t} = useI18n();

    if (!meta || meta.last_page <= 1) {
        return null;
    }

    return (
        <nav aria-label={t('library.pagination')} className="flex items-center justify-between border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
            <PageLink disabled={meta.current_page <= 1} href={`${baseUrl}?page=${meta.current_page - 1}`}>
                {t('library.prev')}
            </PageLink>
            <span className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                {meta.current_page} / {meta.last_page}
            </span>
            <PageLink disabled={meta.current_page >= meta.last_page} href={`${baseUrl}?page=${meta.current_page + 1}`}>
                {t('library.next')}
            </PageLink>
        </nav>
    );
}
