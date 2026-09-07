import {Link, usePage} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';

const STATUS_BADGE = {
    pending: 'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] bg-transparent border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
    verifying: 'text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] bg-transparent border-[var(--wbench-accent)]/40 dark:border-[var(--wbench-accent-night)]/40',
    aligning: 'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] bg-transparent border-[var(--wbench-ink-soft)]/40 dark:border-[var(--wbench-ink-soft-night)]/40',
    completed: 'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] bg-transparent border-[var(--wbench-ink)]/30 dark:border-[var(--wbench-ink-night)]/30',
    failed: 'text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)] bg-transparent border-[var(--wbench-danger)]/40 dark:border-[var(--wbench-danger-night)]/40',
};

function similarityClass(value) {
    if (value === null) return 'text-[var(--wbench-ink-soft)]/50 dark:text-[var(--wbench-ink-soft-night)]/50';
    if (value >= 0.85) return 'text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]';
    if (value >= 0.70) return 'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]';
    return 'text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)]';
}

const PageLink = ({disabled, href, children, label}) => {
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
};

export default function Index({entityMatches, meta}) {
    const {flash} = usePage().props;
    const {current_page, last_page} = meta;

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-wrap items-end justify-between gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            Alignments
                        </p>
                        <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            EN / RU semantic pairs
                        </h1>
                    </div>
                    <div className="flex flex-col items-end gap-2">
                        <p className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {meta.total} {meta.total === 1 ? 'pair' : 'pairs'}
                        </p>
                        <Link
                            href="/alignments/create"
                            className="inline-flex h-8 items-center border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-3 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] transition-colors hover:border-[var(--wbench-accent)] hover:text-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                        >
                            + Create new
                        </Link>
                    </div>
                </header>

                {flash?.success && (
                    <div className="border border-[var(--wbench-accent)]/40 bg-[var(--wbench-accent)]/5 px-4 py-3 text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                        {flash.success}
                    </div>
                )}

                <div className="overflow-hidden border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                    <div className="overflow-x-auto">
                        <table className="min-w-full border-collapse">
                            <thead>
                                <tr className="border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)]">
                                    {['EN entity', 'RU entity', 'Similarity', 'Progress', 'EN sents', 'RU sents', 'Status', 'Created', 'Open'].map((header, index) => (
                                        <th
                                            key={header}
                                            className={[
                                                'px-4 py-2 font-mono text-[10px] uppercase tracking-[0.24em]',
                                                index === 8
                                                    ? 'text-right text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]'
                                                    : 'text-left text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]',
                                            ].join(' ')}
                                        >
                                            {header}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[var(--wbench-rule)] dark:divide-[var(--wbench-rule-night)]">
                                {entityMatches.length === 0 ? (
                                    <tr>
                                        <td colSpan={9} className="px-4 py-12 text-center">
                                            <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                                No alignments yet.
                                            </p>
                                            <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                                Run the alignment pipeline to produce a pair.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    entityMatches.map((run) => (
                                        <IndexRow key={run.id} run={run}/>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                <nav aria-label="Pagination" className="flex items-center justify-between border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                    <PageLink disabled={current_page <= 1} href={`/alignments?page=${current_page - 1}`} label="Previous page">
                        ← Prev
                    </PageLink>
                    <span className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {current_page} / {Math.max(last_page, 1)}
                    </span>
                    <PageLink disabled={current_page >= last_page} href={`/alignments?page=${current_page + 1}`} label="Next page">
                        Next →
                    </PageLink>
                </nav>
            </div>
        </div>
    );
}

Index.layout = (page) => <Main children={page}/>;

function IndexRow({run}) {
    const progress = Math.min((run.confirmed_count ?? 0) / Math.max(run.linked_count ?? 0, 1), 1);

    return (
        <tr className="group transition-colors hover:bg-[var(--wbench-paper-deep)] dark:hover:bg-[var(--wbench-paper-deep-night)]">
            <td className="px-4 py-2.5 align-top">
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    EN
                </span>
                <p className="mt-0.5 font-serif text-[15px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                    {run.en_entity_name || '—'}
                </p>
            </td>
            <td className="px-4 py-2.5 align-top">
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    RU
                </span>
                <p className="mt-0.5 font-serif text-[15px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                    {run.ru_entity_name || '—'}
                </p>
            </td>
            <td className={`px-4 py-2.5 align-top font-mono text-sm ${similarityClass(run.entity_similarity)}`}>
                {run.entity_similarity !== null ? Number(run.entity_similarity).toFixed(4) : '—'}
            </td>
            <td className="px-4 py-2.5 align-top">
                <div className="flex items-center gap-2">
                    <span className="h-1 w-20 overflow-hidden rounded-full bg-[var(--wbench-rule)] dark:bg-[var(--wbench-rule-night)]">
                        <span
                            className="block h-full bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)] transition-[width] duration-300"
                            style={{width: `${Math.round(progress * 100)}%`}}
                        />
                    </span>
                    <span className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {run.confirmed_count} / {run.linked_count}
                    </span>
                </div>
            </td>
            <td className="px-4 py-2.5 align-top font-mono text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                {run.en_total_sentences ?? '—'}
            </td>
            <td className="px-4 py-2.5 align-top font-mono text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                {run.ru_total_sentences ?? '—'}
            </td>
            <td className="px-4 py-2.5 align-top">
                <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 font-mono text-[10px] uppercase tracking-[0.18em] ${STATUS_BADGE[run.status] ?? STATUS_BADGE.pending}`}>
                    {run.status}
                </span>
            </td>
            <td className="px-4 py-2.5 align-top font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                {run.created_at ? new Date(run.created_at).toLocaleDateString() : '—'}
            </td>
            <td className="px-4 py-2.5 text-right align-top">
                <Link
                    href={`/alignments/${run.id}`}
                    className="inline-flex h-8 items-center border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-3 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] transition-colors hover:border-[var(--wbench-accent)] hover:text-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                >
                    Open
                </Link>
            </td>
        </tr>
    );
}
