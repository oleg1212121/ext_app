import {Link, router} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';
import {useI18n} from '../../i18n';

const SIGNATURE_BADGE = {
    generated: 'text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] border-[var(--wbench-accent)]/40 dark:border-[var(--wbench-accent-night)]/40',
    pending: 'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
    none: 'text-[var(--wbench-ink-soft)]/50 dark:text-[var(--wbench-ink-soft-night)]/50 border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
};

const MATCH_BADGE = {
    pending: 'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
    verifying: 'text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] border-[var(--wbench-accent)]/40 dark:border-[var(--wbench-accent-night)]/40',
    aligning: 'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] border-[var(--wbench-ink-soft)]/40 dark:border-[var(--wbench-ink-soft-night)]/40',
    completed: 'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] border-[var(--wbench-ink)]/30 dark:border-[var(--wbench-ink-night)]/30',
    failed: 'text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)] border-[var(--wbench-danger)]/40 dark:border-[var(--wbench-danger-night)]/40',
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

export default function Show({lang, language, entity, entityMatches = [], sentences = [], sentences_meta, can_edit: canEdit = false, can_change_approval: canChangeApproval = false}) {
    const {t} = useI18n();
    const fileName = entity.file_path ? entity.file_path.split('/').pop() : null;
    const pageOffset = ((sentences_meta?.current_page ?? 1) - 1) * (sentences_meta?.per_page ?? 20);

    const toggleApproved = () => {
        router.patch(
            `/entities/${lang}/${entity.id}/approved`,
            {is_approved: ! entity.is_approved},
            {preserveScroll: true},
        );
    };

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <Link
                        href={`/entities/${lang}`}
                        className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                    >
                        ← {language.name} {t('entities.entities')}
                    </Link>
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                {entity.signature_status === 'generated' ? t('entities.signature_generated') : entity.signature_status === 'pending' ? t('entities.signature_pending') : t('entities.no_file')}
                            </p>
                            <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                {entity.name}
                            </h1>
                            {entity.work_title && (
                                <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                    {entity.work_title}{entity.label ? ` · ${entity.label}` : ''}
                                </p>
                            )}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {entity.is_approved && (
                                <span
                                    title={t('entities.approved_hint')}
                                    className="inline-flex h-9 items-center rounded-full border border-[var(--wbench-accent)]/40 dark:border-[var(--wbench-accent-night)]/40 px-4 font-mono text-[10px] uppercase tracking-[0.18em] text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]"
                                >
                                    {t('entities.approved')}
                                </span>
                            )}
                            {canChangeApproval && (
                                <button
                                    type="button"
                                    onClick={toggleApproved}
                                    className="inline-flex h-9 items-center border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-4 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] transition-colors hover:border-[var(--wbench-accent)] hover:text-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                                >
                                    {entity.is_approved ? t('entities.unapprove') : t('entities.approve')}
                                </button>
                            )}
                            <Link
                                href={`/reader/${entity.id}`}
                                className="inline-flex h-9 items-center border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-4 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] transition-colors hover:border-[var(--wbench-accent)] hover:text-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                            >
                                {t('entities.read')}
                            </Link>
                            {canEdit && (
                                <Link
                                    href={`/entities/${lang}/${entity.id}/edit`}
                                    className="inline-flex h-9 items-center border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-4 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] transition-colors hover:border-[var(--wbench-accent)] hover:text-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                                >
                                    {t('entities.edit')}
                                </Link>
                            )}
                            {entityMatches.map((match) => (
                                <Link
                                    key={match.id}
                                    href={`/alignments/${match.id}`}
                                    className="inline-flex h-9 items-center gap-2 border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-4 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] transition-colors hover:border-[var(--wbench-accent)] hover:text-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                                >
                                    {t('entities.open_alignment')}
                                    <span className={`inline-flex items-center rounded-full border px-2 py-0.5 font-mono text-[10px] uppercase tracking-[0.18em] ${MATCH_BADGE[match.status] ?? MATCH_BADGE.pending}`}>
                                        {match.status}
                                    </span>
                                </Link>
                            ))}
                        </div>
                    </div>
                </header>

                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-4">
                        <dt className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('entities.description')}
                        </dt>
                        <dd className="mt-1 text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {entity.description || '—'}
                        </dd>
                    </div>
                    <div className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-4">
                        <dt className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('entities.source_file')}
                        </dt>
                        <dd className="mt-1 font-mono text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {fileName ?? '—'}
                        </dd>
                    </div>
                    <div className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-4">
                        <dt className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('entities.signature')}
                        </dt>
                        <dd className="mt-1">
                            <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 font-mono text-[10px] uppercase tracking-[0.18em] ${SIGNATURE_BADGE[entity.signature_status] ?? SIGNATURE_BADGE.none}`}>
                                {entity.signature_status}
                            </span>
                        </dd>
                    </div>
                    <div className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-4">
                        <dt className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('entities.sentences')}
                        </dt>
                        <dd className="mt-1 font-mono text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {entity.sentences_count}
                        </dd>
                    </div>
                </dl>

                <section className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                    <div className="flex items-center justify-between border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-3">
                        <h2 className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('entities.sentences')}
                        </h2>
                        <span className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {sentences_meta?.total ?? sentences.length} {t('entities.total')}
                        </span>
                    </div>
                    {sentences.length === 0 ? (
                        <p className="px-5 py-12 text-center text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('entities.no_sentences_hint')}
                        </p>
                    ) : (
                        <ol className="divide-y divide-[var(--wbench-rule)] dark:divide-[var(--wbench-rule-night)]">
                            {sentences.map((sentence, index) => (
                                <li key={sentence.id} className="flex gap-4 px-5 py-3">
                                    <span className="mt-0.5 font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                        {String(pageOffset + index + 1).padStart(3, '0')}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                            {sentence.content}
                                        </p>
                                        {sentence.type && (
                                            <p className="mt-0.5 font-mono text-[10px] uppercase tracking-[0.18em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                                {sentence.type}
                                            </p>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ol>
                    )}
                </section>

                {sentences_meta && sentences_meta.last_page > 1 && (
                    <nav aria-label={t('entities.pagination')} className="flex items-center justify-between border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                        <PageLink disabled={sentences_meta.current_page <= 1} href={`/entities/${lang}/${entity.id}?page=${sentences_meta.current_page - 1}`}>
                            {t('entities.prev')}
                        </PageLink>
                        <span className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {sentences_meta.current_page} / {sentences_meta.last_page}
                        </span>
                        <PageLink disabled={sentences_meta.current_page >= sentences_meta.last_page} href={`/entities/${lang}/${entity.id}?page=${sentences_meta.current_page + 1}`}>
                            {t('entities.next')}
                        </PageLink>
                    </nav>
                )}
            </div>
        </div>
    );
}

Show.layout = (page) => <Main children={page}/>;
