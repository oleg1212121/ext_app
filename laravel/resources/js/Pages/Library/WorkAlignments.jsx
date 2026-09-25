import {Link} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';
import LinkPagination from '../../Components/LinkPagination.jsx';
import AlignmentCard from '../../Components/AlignmentCard.jsx';
import {useI18n} from '../../i18n';

const searchInputClass = 'h-9 flex-1 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-3 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] placeholder:text-[var(--wbench-ink-soft)]/50 dark:placeholder:text-[var(--wbench-ink-soft-night)]/40 focus:outline-none focus:ring-2 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)] focus:border-transparent';

const searchButtonClass = 'inline-flex h-9 items-center border border-[var(--wbench-accent)] dark:border-[var(--wbench-accent-night)] px-4 font-sans text-sm text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] transition-colors hover:bg-[var(--wbench-accent)] hover:text-white dark:hover:bg-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-ink-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm';

const emptyBoxClass = 'border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-4 py-12 text-center';

const addCardClass = 'group flex min-h-32 flex-col items-center justify-center gap-2 rounded-sm border border-dashed border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-6 transition-colors hover:border-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]';

export default function WorkAlignments({work, alignments = [], alignmentsMeta, q = ''}) {
    const {t} = useI18n();
    const pageUrl = (page) => {
        const params = new URLSearchParams();
        if (q) params.set('q', q);
        params.set('page', String(page));
        return `/works/${work.id}/alignments?${params.toString()}`;
    };

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <Link
                        href={`/works/${work.id}`}
                        className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                    >
                        ← {work.title}
                    </Link>
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('library.library')} · {work.title}
                        </p>
                        <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('library.alignments_title')}
                        </h1>
                    </div>
                </header>

                <form method="get" action={`/works/${work.id}/alignments`} className="flex items-center gap-2">
                    <label htmlFor="alignment-search" className="sr-only">{t('library.search_alignments')}</label>
                    <input
                        id="alignment-search"
                        name="q"
                        type="search"
                        defaultValue={q}
                        placeholder={t('library.alignments_search_placeholder')}
                        className={searchInputClass}
                    />
                    <button type="submit" className={searchButtonClass}>{t('library.search')}</button>
                </form>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link href={`/works/${work.id}/alignments/create`} className={addCardClass}>
                        <span className="font-serif text-3xl leading-none text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] transition-colors group-hover:text-[var(--wbench-accent)] dark:group-hover:text-[var(--wbench-accent-night)]">
                            +
                        </span>
                        <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] transition-colors group-hover:text-[var(--wbench-accent)] dark:group-hover:text-[var(--wbench-accent-night)]">
                            {t('library.add_alignment')}
                        </span>
                    </Link>

                    {alignments.map((run) => (
                        <AlignmentCard key={run.id} run={run}/>
                    ))}
                </div>

                {alignments.length === 0 && q && (
                    <div className={emptyBoxClass}>
                        <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('library.no_alignments_match', {q})}
                        </p>
                        <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('library.no_alignments_match_hint')}
                        </p>
                    </div>
                )}

                {alignments.length === 0 && !q && (
                    <div className={emptyBoxClass}>
                        <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('library.no_alignments_yet')}
                        </p>
                        <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('library.no_alignments_yet_hint')}
                        </p>
                    </div>
                )}

                <LinkPagination meta={alignmentsMeta} pageUrl={pageUrl}/>
            </div>
        </div>
    );
}

WorkAlignments.layout = (page) => <Main children={page}/>;
