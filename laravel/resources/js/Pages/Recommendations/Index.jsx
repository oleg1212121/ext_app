import {Link} from '@inertiajs/react';
import {useState} from 'react';
import Main from '../../Layouts/Main.jsx';
import LinkPagination from '../../Components/LinkPagination.jsx';
import {useI18n} from '../../i18n';

const searchInputClass = 'h-9 min-w-0 flex-1 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-3 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] placeholder:text-[var(--wbench-ink-soft)]/50 dark:placeholder:text-[var(--wbench-ink-soft-night)]/40 focus:outline-none focus:ring-2 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)] focus:border-transparent';

const selectClass = 'h-9 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-2 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] focus:outline-none focus:ring-2 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)] focus:border-transparent';

const applyButtonClass = 'inline-flex h-9 items-center border border-[var(--wbench-accent)] dark:border-[var(--wbench-accent-night)] px-4 font-sans text-sm text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] transition-colors hover:bg-[var(--wbench-accent)] hover:text-white dark:hover:bg-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-ink-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm';

const emptyBoxClass = 'border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-4 py-12 text-center';

export default function Index({
    works = [],
    meta,
    q = '',
    knowledge = 90,
    lang = 'en',
    languages = [],
    has_no_familiarity: hasNoFamiliarity = false,
    has_any_scores: hasAnyScores = true,
}) {
    const {t} = useI18n();

    const pageUrl = (page) => {
        const params = new URLSearchParams();
        if (q) params.set('q', q);
        params.set('knowledge', String(knowledge));
        params.set('lang', lang);
        params.set('page', String(page));
        return `/recommendations?${params.toString()}`;
    };

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {t('entities.word_knowledge')}
                    </p>
                    <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                        {t('recommendations.title')}
                    </h1>
                    <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {t('recommendations.subtitle')}
                    </p>
                </header>

                <form method="get" action="/recommendations" className="flex flex-wrap items-center gap-2">
                    <label htmlFor="recommendation-search" className="sr-only">{t('recommendations.search_placeholder')}</label>
                    <input
                        id="recommendation-search"
                        name="q"
                        type="search"
                        defaultValue={q}
                        placeholder={t('recommendations.search_placeholder')}
                        className={searchInputClass}
                    />
                    <label htmlFor="recommendation-knowledge" className="sr-only">{t('recommendations.knowledge')}</label>
                    <input
                        id="recommendation-knowledge"
                        name="knowledge"
                        type="number"
                        min="0"
                        max="100"
                        defaultValue={knowledge}
                        className={`${selectClass} w-24`}
                    />
                    <label htmlFor="recommendation-lang" className="sr-only">{t('recommendations.language')}</label>
                    <select id="recommendation-lang" name="lang" defaultValue={lang} className={selectClass}>
                        {languages.map((language) => (
                            <option key={language.code} value={language.code}>{language.name}</option>
                        ))}
                    </select>
                    <button type="submit" className={applyButtonClass}>{t('recommendations.apply')}</button>
                </form>

                <div className="flex flex-col gap-4">
                    {works.map((work) => (
                        <WorkRow key={work.id} work={work}/>
                    ))}
                </div>

                {works.length === 0 && q && (
                    <div className={emptyBoxClass}>
                        <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('recommendations.empty_filtered', {knowledge: Math.round(knowledge)})}
                        </p>
                        <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('recommendations.empty_filtered_hint')}
                        </p>
                    </div>
                )}

                {works.length === 0 && !q && (
                    <div className={emptyBoxClass}>
                        <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {hasAnyScores
                                ? t('recommendations.empty_filtered', {knowledge: Math.round(knowledge)})
                                : t('recommendations.empty_no_scores')}
                        </p>
                        <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {hasAnyScores
                                ? t('recommendations.empty_filtered_hint')
                                : t('recommendations.empty_no_scores_hint')}
                        </p>
                        {hasNoFamiliarity && (
                            <Link
                                href="/word-test"
                                className="mt-3 inline-flex font-sans text-sm text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] underline decoration-dotted underline-offset-4 hover:decoration-solid"
                            >
                                {t('entities.word_knowledge_take_test')}
                            </Link>
                        )}
                    </div>
                )}

                <LinkPagination meta={meta} pageUrl={pageUrl}/>
            </div>
        </div>
    );
}

function WorkRow({work}) {
    const {t} = useI18n();
    const [open, setOpen] = useState(false);
    const count = work.entities.length;

    return (
        <div className="flex flex-col gap-3 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-5">
            <div className="flex items-baseline justify-between gap-3">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <div className="flex flex-wrap items-baseline gap-x-3">
                        <Link
                            href={`/works/${work.id}`}
                            className="font-serif text-xl leading-snug tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)]"
                        >
                            {work.title}
                        </Link>
                        {work.author && (
                            <span className="text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                {work.author}
                            </span>
                        )}
                    </div>
                    <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {work.original_language?.name ?? '—'}
                    </span>
                </div>
                <span className="inline-flex shrink-0 items-center rounded-full border border-[var(--wbench-accent)]/40 dark:border-[var(--wbench-accent-night)]/40 px-2.5 py-0.5 font-mono text-[10px] uppercase tracking-[0.18em] text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]">
                    {t('entities.word_knowledge')} {Math.round(work.min_score)}%
                </span>
            </div>
            {work.description && (
                <p className="line-clamp-2 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {work.description}
                </p>
            )}
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                className="inline-flex w-fit items-center gap-1 font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] transition-colors hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
            >
                {open ? t('recommendations.hide_texts') : t('recommendations.show_texts')} ({count})
                <svg
                    className={`h-3 w-3 opacity-60 transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth="2"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            {open && (
                <ul role="list" className="flex flex-col divide-y divide-[var(--wbench-rule)]/60 dark:divide-[var(--wbench-rule-night)]/60 border-t border-[var(--wbench-rule)]/60 dark:border-[var(--wbench-rule-night)]/60">
                    {work.entities.map((entity) => (
                        <li key={entity.id} className="flex items-start justify-between gap-4 py-3">
                            <div className="flex min-w-0 flex-col gap-0.5">
                                <Link
                                    href={`/works/${entity.work_id}/entities/${entity.id}`}
                                    className="font-serif text-base leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)]"
                                >
                                    {entity.name}
                                    {entity.label && (
                                        <span className="text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]"> · {entity.label}</span>
                                    )}
                                </Link>
                                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                    {entity.language?.name ?? '—'} · {entity.sentences_count} {entity.sentences_count === 1 ? t('library.sentence') : t('library.sentences')}
                                </span>
                                {entity.description && (
                                    <p className="line-clamp-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                        {entity.description}
                                    </p>
                                )}
                            </div>
                            <span className="shrink-0 font-mono text-xs text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]">
                                {Math.round(entity.word_knowledge)}%
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

Index.layout = (page) => <Main children={page}/>;
