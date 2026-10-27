import {Link} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';
import LinkPagination from '../../Components/LinkPagination.jsx';
import {useI18n} from '../../i18n';

const searchInputClass = 'h-9 flex-1 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-3 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] placeholder:text-[var(--wbench-ink-soft)]/50 dark:placeholder:text-[var(--wbench-ink-soft-night)]/40 focus:outline-none focus:ring-2 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)] focus:border-transparent';

const searchButtonClass = 'inline-flex h-9 items-center border border-[var(--wbench-accent)] dark:border-[var(--wbench-accent-night)] px-4 font-sans text-sm text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] transition-colors hover:bg-[var(--wbench-accent)] hover:text-white dark:hover:bg-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-ink-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm';

const emptyBoxClass = 'border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-4 py-12 text-center';

// The reader flow's per-work alignments list: the matches of the work the
// user can read at completed or stale, searchable by either side's entity
// name and filterable to a language of either side. Reading-surface
// minimalism — whole-card links into the alignment reader (which owns the
// side rule), no editor/simulator buttons, no progress bars.
export default function WorkAlignmentsList({work, alignments = [], meta, q = '', lang = '', languages = []}) {
    const {t} = useI18n();
    const listUrl = `/reader/works/${work.id}/alignments`;

    const pageUrl = (page) => {
        const params = new URLSearchParams();
        if (q) params.set('q', q);
        if (lang) params.set('lang', lang);
        params.set('page', String(page));
        return `${listUrl}?${params.toString()}`;
    };

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <Link
                        href={`/reader/works/${work.id}`}
                        className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                    >
                        ← {work.title}
                    </Link>
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('reader.reader_heading')} · {work.title}
                        </p>
                        <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('reader.alignments')}
                        </h1>
                    </div>
                </header>

                <form method="get" action={listUrl} className="flex flex-wrap items-center gap-2">
                    <label htmlFor="alignment-search" className="sr-only">{t('reader.search_alignments')}</label>
                    <input
                        id="alignment-search"
                        name="q"
                        type="search"
                        defaultValue={q}
                        placeholder={t('reader.search_alignments')}
                        className={searchInputClass}
                    />
                    <label htmlFor="language-filter" className="sr-only">{t('reader.language_filter_label')}</label>
                    <select
                        id="language-filter"
                        name="lang"
                        defaultValue={lang}
                        className="h-9 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-2 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] focus:outline-none focus:ring-2 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)] focus:border-transparent"
                    >
                        <option value="">{t('reader.all_languages')}</option>
                        {languages.map((language) => (
                            <option key={language.code} value={language.code}>
                                {language.native_name ? `${language.name} — ${language.native_name}` : language.name}
                            </option>
                        ))}
                    </select>
                    <button type="submit" className={searchButtonClass}>{t('reader.search')}</button>
                </form>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {alignments.map((alignment) => (
                        <AlignmentCard key={alignment.id} alignment={alignment} work={work}/>
                    ))}
                </div>

                {alignments.length === 0 && q && (
                    <div className={emptyBoxClass}>
                        <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('reader.no_alignments_match', {q})}
                        </p>
                        <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('reader.no_alignments_match_hint')}
                        </p>
                    </div>
                )}

                {alignments.length === 0 && !q && (
                    <div className={emptyBoxClass}>
                        <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {lang ? t('reader.no_alignments') : t('reader.no_alignments_yet')}
                        </p>
                        <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {lang ? t('reader.no_alignments_hint') : t('reader.no_alignments_yet_hint')}
                        </p>
                    </div>
                )}

                <LinkPagination meta={meta} pageUrl={pageUrl}/>
            </div>
        </div>
    );
}

function AlignmentCard({alignment, work}) {
    return (
        <Link
            href={`/reader/works/${work.id}/alignments/${alignment.id}`}
            className="group flex flex-col gap-2 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-5 transition-colors hover:border-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
        >
            <div className="flex items-baseline justify-between gap-3">
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {(alignment.a_language_code || 'a').toUpperCase()} · {(alignment.b_language_code || 'b').toUpperCase()}
                </span>
                <span className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {alignment.status}
                </span>
            </div>
            <h2 className="font-serif text-lg leading-snug tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] group-hover:text-[var(--wbench-accent)] dark:group-hover:text-[var(--wbench-accent-night)]">
                {alignment.a_entity_name || '—'}
            </h2>
            <p className="font-serif text-lg leading-snug tracking-tight text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] group-hover:text-[var(--wbench-accent)] dark:group-hover:text-[var(--wbench-accent-night)]">
                {alignment.b_entity_name || '—'}
            </p>
        </Link>
    );
}

WorkAlignmentsList.layout = (page) => <Main children={page}/>;
