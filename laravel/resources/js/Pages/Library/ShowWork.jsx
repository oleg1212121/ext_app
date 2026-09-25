import {Link} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';
import {useI18n} from '../../i18n';

export default function ShowWork({work}) {
    const {t} = useI18n();

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <Link
                        href="/works"
                        className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                    >
                        ← {t('library.works')}
                    </Link>
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('library.library')}
                            {work.original_language ? ` · ${work.original_language.name}` : ''}
                        </p>
                        <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {work.title}
                        </h1>
                    </div>
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {work.author && <span>{work.author}</span>}
                        {work.original_language && (
                            <span className="font-mono text-[10px] uppercase tracking-[0.18em]">
                                {t('library.original')} · {work.original_language.name}
                            </span>
                        )}
                    </div>
                    {work.description && (
                        <p className="max-w-3xl text-sm leading-relaxed text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {work.description}
                        </p>
                    )}
                </header>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <BranchCard
                        href={`/works/${work.id}/entities`}
                        title={t('library.entities_title')}
                        count={work.entities_count}
                        countWord={work.entities_count === 1 ? t('library.entity') : t('library.entities')}
                    />
                    <BranchCard
                        href={`/works/${work.id}/alignments`}
                        title={t('library.alignments_title')}
                        count={work.alignments_count}
                        countWord={work.alignments_count === 1 ? t('library.alignment') : t('library.alignments')}
                    />
                </div>
            </div>
        </div>
    );
}

function BranchCard({href, title, count, countWord}) {
    return (
        <Link
            href={href}
            className="group flex flex-col gap-2 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-5 transition-colors hover:border-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
        >
            <div className="flex items-baseline justify-between gap-3">
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {count} {countWord}
                </span>
                <span
                    aria-hidden="true"
                    className="font-serif italic text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] transition-colors group-hover:text-[var(--wbench-accent)] dark:group-hover:text-[var(--wbench-accent-night)]"
                >
                    →
                </span>
            </div>
            <h2 className="font-serif text-xl leading-snug tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] group-hover:text-[var(--wbench-accent)] dark:group-hover:text-[var(--wbench-accent-night)]">
                {title}
            </h2>
        </Link>
    );
}

ShowWork.layout = (page) => <Main children={page}/>;
