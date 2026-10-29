import {Link} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';
import {useI18n} from '../../i18n';
import {STATUS_BADGE, similarityClass} from '../../lib/alignmentDisplay.js';
import SimplePagination from '../../Components/SimplePagination.jsx';

const langTagClass = 'font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]';

const actionButtonClass = 'inline-flex h-9 items-center border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-4 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] transition-colors hover:border-[var(--wbench-accent)] hover:text-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm';

const originalChipClass = 'inline-flex items-center rounded-full border border-[var(--wbench-accent)]/40 dark:border-[var(--wbench-accent-night)]/40 px-2 py-0.5 font-mono text-[10px] uppercase tracking-[0.18em] text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]';

/**
 * Read-only detail page behind an alignment card, mirroring the entity
 * detail page's layout: action buttons in the header, the pair's metadata
 * as a card grid, and the bilingual text itself as read-only side-by-side
 * rows (one per meaning match, the work's original language on the left —
 * the book-PDF rule) with the shared prev/next pagination. Viewing only:
 * the editor is reached through its Edit button.
 */
export default function ShowAlignment({work, match, rows = [], rows_meta, columns}) {
    const {t} = useI18n();
    const progress = Math.min((match.confirmed_count ?? 0) / Math.max(match.linked_count ?? 0, 1), 1);
    const pageOffset = ((rows_meta?.current_page ?? 1) - 1) * (rows_meta?.per_page ?? 20);

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
                    <div className="flex flex-wrap items-end justify-between gap-3">
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
                            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
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
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {/* The reader URL is match-anchored: the side rule (and the
                                language it reads first) lives in the reader page now. */}
                            <Link href={`/reader/works/${work.id}/alignments/${match.id}`} className={actionButtonClass}>
                                {t('library.read')}
                            </Link>
                            <Link href={`/bilinguals/simulator/${match.id}`} className={actionButtonClass}>
                                {t('library.simulator')}
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
                            <Link href={`/works/${work.id}/alignments/${match.id}/edit`} className={actionButtonClass}>
                                {t('library.edit')}
                            </Link>
                        </div>
                    </div>
                </header>

                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <DetailCard label={t('library.similarity')}>
                        {match.entity_similarity !== null ? Number(match.entity_similarity).toFixed(4) : '—'}
                    </DetailCard>
                    <DetailCard label={t('library.linked_rows')}>
                        {match.confirmed_count} / {match.linked_count}
                    </DetailCard>
                    <DetailCard label={t('library.sentences')}>
                        {(match.a_language_code || 'a').toUpperCase()} {match.a_total_sentences ?? '—'}
                        {' · '}
                        {(match.b_language_code || 'b').toUpperCase()} {match.b_total_sentences ?? '—'}
                    </DetailCard>
                    {match.created_at && (
                        <DetailCard label={t('library.created')}>
                            {new Date(match.created_at).toLocaleDateString()}
                        </DetailCard>
                    )}
                    <DetailCard label={t('library.work')}>
                        <Link
                            href={`/works/${work.id}`}
                            className="transition-colors hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)]"
                        >
                            {work.title}{work.author ? ` — ${work.author}` : ''}
                        </Link>
                    </DetailCard>
                </dl>

                <section className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                    <div className="flex items-center justify-between border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-3">
                        <h2 className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('library.meaning_matches')}
                        </h2>
                        <span className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {rows_meta?.total ?? rows.length} {t('library.total')}
                        </span>
                    </div>
                    {rows.length === 0 ? (
                        <p className="px-5 py-12 text-center text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('library.no_rows_hint')}
                        </p>
                    ) : (
                        <>
                            {/* Side-by-side column header (desktop): original language left.
                                On mobile the rows stack, so each cell carries its own
                                language tag instead. */}
                            <div className="hidden border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] sm:grid sm:grid-cols-2">
                                <ColumnHeader column={columns?.left}/>
                                <ColumnHeader column={columns?.right} divided/>
                            </div>
                            <ol className="divide-y divide-[var(--wbench-rule)] dark:divide-[var(--wbench-rule-night)]">
                                {rows.map((row, index) => (
                                    <ReadOnlyRow key={row.id} row={row} position={pageOffset + index + 1} columns={columns}/>
                                ))}
                            </ol>
                        </>
                    )}
                </section>

                <SimplePagination meta={rows_meta} baseUrl={`/works/${work.id}/alignments/${match.id}`}/>
            </div>
        </div>
    );
}

function ColumnHeader({column, divided = false}) {
    const {t} = useI18n();

    return (
        <div className={[
            'flex min-w-0 items-center gap-2 px-5 py-2',
            divided ? 'sm:border-l sm:border-l-[var(--wbench-rule)] sm:dark:border-l-[var(--wbench-rule-night)]' : '',
        ].join(' ')}>
            <span className={langTagClass}>{(column?.code || '—').toUpperCase()}</span>
            <span className="min-w-0 truncate font-mono text-[10px] uppercase tracking-[0.18em] text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                {column?.entity_name || '—'}
            </span>
            {column?.is_original && (
                <span className={originalChipClass}>
                    {t('library.original')}
                </span>
            )}
        </div>
    );
}

function ReadOnlyRow({row, position, columns}) {
    return (
        <li>
            <div className="bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] px-5 py-1">
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    #{String(position).padStart(3, '0')}
                </span>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2">
                <ReadOnlyCell
                    sentences={row.left_sentences}
                    column={columns?.left}
                    className="border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] sm:border-b-0 sm:border-r sm:border-r-[var(--wbench-rule)] sm:dark:border-r-[var(--wbench-rule-night)]"
                />
                <ReadOnlyCell sentences={row.right_sentences} column={columns?.right}/>
            </div>
        </li>
    );
}

function ReadOnlyCell({sentences, column, className = ''}) {
    return (
        <div className={`min-w-0 px-5 py-3 ${className}`}>
            <span className={`${langTagClass} sm:hidden`}>{(column?.code || '—').toUpperCase()}</span>
            {sentences.length === 0 ? (
                <p className="text-sm text-[var(--wbench-ink-soft)]/60 dark:text-[var(--wbench-ink-soft-night)]/60">—</p>
            ) : (
                sentences.map((sentence) => (
                    sentence.content !== '' ? (
                        <p
                            key={sentence.id}
                            className="whitespace-pre-line font-serif text-[15px] leading-relaxed text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]"
                        >
                            {sentence.content}
                        </p>
                    ) : sentence.has_image ? (
                        // Image-only sentences (ADR 0050): the same textual
                        // placeholder the book PDF prints for them.
                        <p key={sentence.id} className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            [illustration]
                        </p>
                    ) : null
                ))
            )}
        </div>
    );
}

function DetailCard({label, children}) {
    return (
        <div className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-4">
            <dt className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                {label}
            </dt>
            <dd className="mt-1 text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                {children}
            </dd>
        </div>
    );
}

ShowAlignment.layout = (page) => <Main children={page}/>;
