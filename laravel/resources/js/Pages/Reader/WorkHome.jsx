import {Link} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';
import {useI18n} from '../../i18n';

// The reader flow's work sections page: the work header plus one entry
// card per section. The Alignments card joins the Texts card with the
// alignments-list ticket — the payload already carries alignments_count.
export default function WorkHome({work, entitiesCount}) {
    const {t} = useI18n();

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {t('reader.reader_heading')}
                        {work.original_language ? ` · ${work.original_language.name}` : ''}
                    </p>
                    <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                        {work.title}
                    </h1>
                    <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {work.author && <span>{work.author}</span>}
                    </div>
                    {work.description && (
                        <p className="mt-3 max-w-3xl text-sm leading-relaxed text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {work.description}
                        </p>
                    )}
                </header>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <SectionCard
                        href={`/reader/works/${work.id}/entities`}
                        count={entitiesCount}
                        countWord={entitiesCount === 1 ? t('reader.text') : t('reader.texts')}
                        title={t('reader.texts')}
                    />
                </div>
            </div>
        </div>
    );
}

function SectionCard({href, count, countWord, title}) {
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

WorkHome.layout = (page) => <Main children={page}/>;
