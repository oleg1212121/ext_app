import {Link} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';
import LinkPagination from '../../Components/LinkPagination.jsx';
import {useI18n} from '../../i18n';

const searchInputClass = 'h-9 flex-1 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-3 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] placeholder:text-[var(--wbench-ink-soft)]/50 dark:placeholder:text-[var(--wbench-ink-soft-night)]/40 focus:outline-none focus:ring-2 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)] focus:border-transparent';

const searchButtonClass = 'inline-flex h-9 items-center border border-[var(--wbench-accent)] dark:border-[var(--wbench-accent-night)] px-4 font-sans text-sm text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] transition-colors hover:bg-[var(--wbench-accent)] hover:text-white dark:hover:bg-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-ink-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm';

const emptyBoxClass = 'border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-4 py-12 text-center';

// The reader flow's works-first entry: the public works catalog (every
// work, empty ones included) with readable-scoped counts. Whole-card links
// into the work's sections page; a reading surface, so no add-work card —
// identical copy reuses the library.* keys.
export default function Works({works = [], meta, q = ''}) {
    const {t} = useI18n();
    const listUrl = '/reader';

    const pageUrl = (page) => {
        const params = new URLSearchParams();
        if (q) params.set('q', q);
        params.set('page', String(page));
        return `${listUrl}?${params.toString()}`;
    };

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {t('reader.reader_heading')}
                    </p>
                    <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                        {t('library.works')}
                    </h1>
                </header>

                <form method="get" action={listUrl} className="flex items-center gap-2">
                    <label htmlFor="work-search" className="sr-only">{t('library.search_works')}</label>
                    <input
                        id="work-search"
                        name="q"
                        type="search"
                        defaultValue={q}
                        placeholder={t('library.search_placeholder')}
                        className={searchInputClass}
                    />
                    <button type="submit" className={searchButtonClass}>{t('reader.search')}</button>
                </form>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {works.map((work) => (
                        <WorkCard key={work.id} work={work}/>
                    ))}
                </div>

                {works.length === 0 && q && (
                    <div className={emptyBoxClass}>
                        <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('library.no_works_match', {q})}
                        </p>
                        <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('reader.no_works_match_hint')}
                        </p>
                    </div>
                )}

                <LinkPagination meta={meta} pageUrl={pageUrl}/>
            </div>
        </div>
    );
}

function WorkCard({work}) {
    const {t} = useI18n();
    return (
        <Link
            href={`/reader/works/${work.id}`}
            className="group flex flex-col gap-2 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-5 transition-colors hover:border-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
        >
            <div className="flex items-baseline justify-between gap-3">
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {work.original_language?.name ?? '—'}
                </span>
                <span className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {work.entities_count} {work.entities_count === 1 ? t('reader.text') : t('reader.texts')}
                    {' · '}
                    {work.alignments_count} {work.alignments_count === 1 ? t('reader.alignment') : t('reader.alignments')}
                </span>
            </div>
            <h2 className="font-serif text-xl leading-snug tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] group-hover:text-[var(--wbench-accent)] dark:group-hover:text-[var(--wbench-accent-night)]">
                {work.title}
            </h2>
            {work.author && (
                <p className="text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {work.author}
                </p>
            )}
            {work.description && (
                <p className="line-clamp-2 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {work.description}
                </p>
            )}
        </Link>
    );
}

Works.layout = (page) => <Main children={page}/>;
