import {Link} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';

const SIGNATURE_BADGE = {
    generated: 'text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] border-[var(--wbench-accent)]/40 dark:border-[var(--wbench-accent-night)]/40',
    pending: 'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
    none: 'text-[var(--wbench-ink-soft)]/50 dark:text-[var(--wbench-ink-soft-night)]/50 border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
};

const PageLink = ({disabled, href, children}) => {
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

export default function List({lang, language, entities = [], meta}) {
    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-wrap items-end justify-between gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            Entities · {language.name}
                        </p>
                        <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {language.native_name || language.name} entities
                        </h1>
                    </div>
                    <Link
                        href={`/entities/${lang}/create`}
                        className="inline-flex h-9 items-center border border-[var(--wbench-accent)] dark:border-[var(--wbench-accent-night)] px-4 font-sans text-sm text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] transition-colors hover:bg-[var(--wbench-accent)] hover:text-white dark:hover:bg-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-ink-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                    >
                        + Create entity
                    </Link>
                </header>

                <div className="overflow-hidden border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                    <div className="overflow-x-auto">
                        <table className="min-w-full border-collapse">
                            <thead>
                                <tr className="border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)]">
                                    {['Name', 'Description', 'Signature', 'Sentences', 'Created', 'Open'].map((header, index) => (
                                        <th
                                            key={header}
                                            className={[
                                                'px-4 py-2 font-mono text-[10px] uppercase tracking-[0.24em]',
                                                index === 5
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
                                {entities.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-12 text-center">
                                            <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                                No entities yet.
                                            </p>
                                            <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                                Upload a text file to start a {language.code} entity.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    entities.map((entity) => (
                                        <ListRow key={entity.id} entity={entity} lang={lang}/>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {meta && meta.last_page > 1 && (
                    <nav aria-label="Pagination" className="flex items-center justify-between border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                        <PageLink disabled={meta.current_page <= 1} href={`/entities/${lang}?page=${meta.current_page - 1}`}>
                            ← Prev
                        </PageLink>
                        <span className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {meta.current_page} / {meta.last_page}
                        </span>
                        <PageLink disabled={meta.current_page >= meta.last_page} href={`/entities/${lang}?page=${meta.current_page + 1}`}>
                            Next →
                        </PageLink>
                    </nav>
                )}
            </div>
        </div>
    );
}

function ListRow({entity, lang}) {
    return (
        <tr className="group transition-colors hover:bg-[var(--wbench-paper-deep)] dark:hover:bg-[var(--wbench-paper-deep-night)]">
            <td className="px-4 py-2.5 align-top">
                <Link
                    href={`/entities/${lang}/${entity.id}`}
                    className="font-serif text-[15px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)]"
                >
                    {entity.name}
                </Link>
            </td>
            <td className="px-4 py-2.5 align-top">
                <p className="line-clamp-2 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {entity.description || '—'}
                </p>
            </td>
            <td className="px-4 py-2.5 align-top">
                <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 font-mono text-[10px] uppercase tracking-[0.18em] ${SIGNATURE_BADGE[entity.signature_status] ?? SIGNATURE_BADGE.none}`}>
                    {entity.signature_status}
                </span>
            </td>
            <td className="px-4 py-2.5 align-top font-mono text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                {entity.sentences_count}
            </td>
            <td className="px-4 py-2.5 align-top font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                {entity.created_at ? new Date(entity.created_at).toLocaleDateString() : '—'}
            </td>
            <td className="px-4 py-2.5 text-right align-top">
                <Link
                    href={`/entities/${lang}/${entity.id}`}
                    className="inline-flex h-8 items-center border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-3 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] transition-colors hover:border-[var(--wbench-accent)] hover:text-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                >
                    Open
                </Link>
            </td>
        </tr>
    );
}

List.layout = (page) => <Main children={page}/>;
