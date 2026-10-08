import Pagination from './Pagination.jsx';
import {CheckIcon} from './icons.jsx';
import {useI18n} from '../../../i18n';

function targetPage(item, rowsPerPage) {
    return Math.max(Math.ceil(item.rank / rowsPerPage), 1);
}

export default function NeedsReviewSection({expanded, onToggle, items, meta, busy, rowsPerPage, onPageChange, onRowClick, onApprove}) {
    const {t} = useI18n();
    return (
        <section className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
            <button
                type="button"
                onClick={onToggle}
                className="flex w-full items-center justify-between gap-2 bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] px-3 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
                aria-expanded={expanded}
            >
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {t('alignments.needs_review')}
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
                                <div
                                    key={item.key}
                                    className="group flex items-center gap-1 pr-2 hover:bg-[var(--wbench-paper-deep)] dark:hover:bg-[var(--wbench-paper-deep-night)] focus-within:bg-[var(--wbench-paper-deep)] dark:focus-within:bg-[var(--wbench-paper-deep-night)]"
                                >
                                    <button
                                        type="button"
                                        onClick={() => onRowClick(item, page)}
                                        className="min-w-0 flex-1 grid grid-cols-1 gap-x-3 gap-y-0.5 px-3 py-2 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--wbench-accent)] sm:grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-baseline"
                                    >
                                        <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                            #{item.rank}
                                            {item.one_sided && (
                                                <span className="ml-1.5 text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]">
                                                    {t('alignments.one_sided')}
                                                </span>
                                            )}
                                        </span>
                                        <span className="font-serif text-[13px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] line-clamp-2">
                                            {item.a_part || '—'}
                                        </span>
                                        <span className="font-serif text-[13px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] line-clamp-2">
                                            {item.b_part || '—'}
                                        </span>
                                        <span className="font-mono text-[10px] tabular-nums text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                            {item.similarity !== null ? `${t('alignments.sim')} ${Number(item.similarity).toFixed(4)}` : `${t('alignments.sim')} —`} · {t('alignments.page_short', {page})}
                                        </span>
                                    </button>
                                    <span className="flex shrink-0 items-center opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100">
                                        <button
                                            type="button"
                                            onClick={() => onApprove(item)}
                                            disabled={busy}
                                            title={t('alignments.approve')}
                                            aria-label={`${t('alignments.approve_pair')} #${item.rank}`}
                                            className="inline-flex h-7 w-7 items-center justify-center rounded-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] disabled:opacity-40 disabled:cursor-not-allowed"
                                        >
                                            <CheckIcon/>
                                        </button>
                                    </span>
                                </div>
                            );
                        })}

                        {items.length === 0 && (
                            <p className="px-3 py-8 text-center font-serif text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                {t('alignments.nothing_needs_review')}
                            </p>
                        )}
                    </div>

                    <Pagination meta={meta} busy={busy} onPage={onPageChange} onPerPage={null} />
                </>
            )}
        </section>
    );
}
