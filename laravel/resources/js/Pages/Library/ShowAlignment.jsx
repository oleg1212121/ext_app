import {Link} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';
import {useI18n} from '../../i18n';
import {STATUS_BADGE, similarityClass} from '../../lib/alignmentDisplay.js';

const langTagClass = 'font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]';

const actionButtonClass = 'inline-flex h-8 items-center border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-3 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] transition-colors hover:border-[var(--wbench-accent)] hover:text-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm';

/**
 * Read-only detail page behind an alignment card: the pair's metadata plus
 * the Read / Simulator / Edit actions — the editor is reached only through
 * its Edit button.
 */
export default function ShowAlignment({work, match}) {
    const {t} = useI18n();
    const progress = Math.min((match.confirmed_count ?? 0) / Math.max(match.linked_count ?? 0, 1), 1);

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <Link
                        href={`/works/${work.id}/alignments`}
                        className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                    >
                        ← {t('library.alignments_title')}
                    </Link>
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('library.library')} · {work.title}
                        </p>
                        <h1 className="mt-1 flex flex-wrap items-baseline gap-x-2 gap-y-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            <span className={langTagClass}>{(match.a_language_code || 'a').toUpperCase()}</span>
                            <span>{match.a_entity_name || '—'}</span>
                            <span aria-hidden="true" className="text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">⇄</span>
                            <span className={langTagClass}>{(match.b_language_code || 'b').toUpperCase()}</span>
                            <span>{match.b_entity_name || '—'}</span>
                        </h1>
                    </div>
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 font-mono text-[10px] uppercase tracking-[0.18em] ${STATUS_BADGE[match.status] ?? STATUS_BADGE.pending}`}>
                            {match.status}
                        </span>
                        <span className={`font-mono text-sm ${similarityClass(match.entity_similarity)}`}>
                            {match.entity_similarity !== null ? Number(match.entity_similarity).toFixed(4) : '—'}
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span className="h-1 w-16 overflow-hidden rounded-full bg-[var(--wbench-rule)] dark:bg-[var(--wbench-rule-night)]">
                                <span
                                    className="block h-full bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)] transition-[width] duration-300"
                                    style={{width: `${Math.round(progress * 100)}%`}}
                                />
                            </span>
                            <span className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                {match.confirmed_count} / {match.linked_count}
                            </span>
                        </span>
                    </div>
                </header>

                <dl className="flex flex-col gap-2">
                    <DetailRow label={t('library.similarity')}>
                        {match.entity_similarity !== null ? Number(match.entity_similarity).toFixed(4) : '—'}
                    </DetailRow>
                    <DetailRow label={t('library.linked_rows')}>
                        {match.confirmed_count} / {match.linked_count}
                    </DetailRow>
                    <DetailRow label={t('library.sentences')}>
                        {(match.a_language_code || 'a').toUpperCase()} {match.a_total_sentences ?? '—'}
                        {' · '}
                        {(match.b_language_code || 'b').toUpperCase()} {match.b_total_sentences ?? '—'}
                    </DetailRow>
                    {match.created_at && (
                        <DetailRow label={t('library.created')}>
                            {new Date(match.created_at).toLocaleDateString()}
                        </DetailRow>
                    )}
                    <DetailRow label={t('library.work')}>
                        <Link
                            href={`/works/${work.id}`}
                            className="transition-colors hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)]"
                        >
                            {work.title}{work.author ? ` — ${work.author}` : ''}
                        </Link>
                    </DetailRow>
                </dl>

                <div className="flex items-center gap-2">
                    {/* The reader URL is match-anchored: the side rule (and the
                        language it reads first) lives in the reader page now. */}
                    <Link href={`/reader/works/${work.id}/alignments/${match.id}`} className={actionButtonClass}>
                        {t('library.read')}
                    </Link>
                    <Link href={`/bilinguals/simulator/${match.id}`} className={actionButtonClass}>
                        {t('library.simulator')}
                    </Link>
                    <Link href={`/works/${work.id}/alignments/${match.id}/edit`} className={actionButtonClass}>
                        {t('library.edit')}
                    </Link>
                    {match.status === 'completed' && (
                        /* A file download, not client-side navigation: the
                            endpoint answers with an attachment, so a plain
                            anchor (Inertia must not intercept). A book of an
                            unfinished alignment 404s server-side, so the
                            button only exists for completed matches. */
                        <a href={`/works/${work.id}/alignments/${match.id}/pdf`} className={actionButtonClass}>
                            {t('library.get_pdf')}
                        </a>
                    )}
                </div>
            </div>
        </div>
    );
}

function DetailRow({label, children}) {
    return (
        <div className="flex items-baseline gap-4">
            <dt className="w-32 shrink-0 font-mono text-[10px] uppercase tracking-[0.18em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                {label}
            </dt>
            <dd className="text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                {children}
            </dd>
        </div>
    );
}

ShowAlignment.layout = (page) => <Main children={page}/>;
