import {Link} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';
import LinkPagination from '../../Components/LinkPagination.jsx';
import {useI18n} from '../../i18n';

const SIGNATURE_BADGE = {
    generated: 'text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] border-[var(--wbench-accent)]/40 dark:border-[var(--wbench-accent-night)]/40',
    pending: 'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
    none: 'text-[var(--wbench-ink-soft)]/50 dark:text-[var(--wbench-ink-soft-night)]/50 border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
};

export default function ShowWork({work, entities = [], meta, q = ''}) {
    const {t} = useI18n();
    const pageUrl = (page) => {
        const params = new URLSearchParams();
        if (q) params.set('q', q);
        params.set('page', String(page));
        return `/library/${work.id}?${params.toString()}`;
    };

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <Link
                        href="/library"
                        className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                    >
                        ← {t('library.library')}
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

                <form method="get" action={`/library/${work.id}`} className="flex items-center gap-2">
                    <label htmlFor="entity-search" className="sr-only">{t('library.search_entities')}</label>
                    <input
                        id="entity-search"
                        name="q"
                        type="search"
                        defaultValue={q}
                        placeholder={t('library.entities_search_placeholder')}
                        className="h-9 flex-1 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-3 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] placeholder:text-[var(--wbench-ink-soft)]/50 dark:placeholder:text-[var(--wbench-ink-soft-night)]/40 focus:outline-none focus:ring-2 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)] focus:border-transparent"
                    />
                    <button
                        type="submit"
                        className="inline-flex h-9 items-center border border-[var(--wbench-accent)] dark:border-[var(--wbench-accent-night)] px-4 font-sans text-sm text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] transition-colors hover:bg-[var(--wbench-accent)] hover:text-white dark:hover:bg-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-ink-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                    >
                        {t('library.search')}
                    </button>
                </form>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        href={`/library/${work.id}/entities/create`}
                        className="group flex min-h-32 flex-col items-center justify-center gap-2 rounded-sm border border-dashed border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-6 transition-colors hover:border-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
                    >
                        <span className="font-serif text-3xl leading-none text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] transition-colors group-hover:text-[var(--wbench-accent)] dark:group-hover:text-[var(--wbench-accent-night)]">
                            +
                        </span>
                        <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] transition-colors group-hover:text-[var(--wbench-accent)] dark:group-hover:text-[var(--wbench-accent-night)]">
                            {t('library.add_entity')}
                        </span>
                    </Link>

                    {entities.map((entity) => (
                        <EntityCard key={entity.id} entity={entity}/>
                    ))}
                </div>

                {entities.length === 0 && q && (
                    <div className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-4 py-12 text-center">
                        <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('library.no_entities_match', {q})}
                        </p>
                        <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('library.no_entities_match_hint')}
                        </p>
                    </div>
                )}

                {entities.length === 0 && !q && (
                    <div className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-4 py-12 text-center">
                        <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('library.no_entities_yet')}
                        </p>
                        <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('library.no_entities_yet_hint')}
                        </p>
                    </div>
                )}

                <LinkPagination meta={meta} pageUrl={pageUrl}/>
            </div>
        </div>
    );
}

function EntityCard({entity}) {
    const {t} = useI18n();
    return (
        <Link
            href={`/entities/${entity.language?.code}/${entity.id}`}
            className="group flex flex-col gap-2 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-5 transition-colors hover:border-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
        >
            <div className="flex items-baseline justify-between gap-3">
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {entity.language?.name ?? '—'}
                </span>
                <span className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {entity.sentences_count} {entity.sentences_count === 1 ? t('library.sentence') : t('library.sentences')}
                </span>
            </div>
            <h2 className="font-serif text-lg leading-snug tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] group-hover:text-[var(--wbench-accent)] dark:group-hover:text-[var(--wbench-accent-night)]">
                {entity.name}
            </h2>
            {entity.label && (
                <p className="text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {entity.label}
                </p>
            )}
            {entity.description && (
                <p className="line-clamp-2 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {entity.description}
                </p>
            )}
            <span className={`mt-auto inline-flex items-center rounded-full border px-2.5 py-0.5 font-mono text-[10px] uppercase tracking-[0.18em] w-fit ${SIGNATURE_BADGE[entity.signature_status] ?? SIGNATURE_BADGE.none}`}>
                {entity.signature_status}
            </span>
        </Link>
    );
}

ShowWork.layout = (page) => <Main children={page}/>;
