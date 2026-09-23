import {Link} from '@inertiajs/react';
import {useI18n} from '../i18n';
import {STATUS_BADGE, similarityClass} from '../lib/alignmentDisplay.js';

const langTagClass = 'font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]';

/**
 * One entity match on a work's Alignments tab. The card body links to the
 * alignment editor (a stretched link behind the content); the footer buttons
 * — simulator and reader — sit above it and navigate directly.
 */
export default function AlignmentCard({run}) {
    const {t} = useI18n();
    const progress = Math.min((run.confirmed_count ?? 0) / Math.max(run.linked_count ?? 0, 1), 1);

    return (
        <div className="relative flex min-h-32 flex-col gap-3 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-5 transition-colors hover:border-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)]">
            <Link
                href={`/alignments/${run.id}`}
                className="after:absolute after:inset-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
            >
                <span className="sr-only">{t('library.open_alignment_editor')}</span>
            </Link>

            <div className="flex flex-col gap-1">
                <div className="flex items-baseline gap-2">
                    <span className={langTagClass}>{(run.a_language_code || 'a').toUpperCase()}</span>
                    <span className="font-serif text-[15px] leading-snug tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                        {run.a_entity_name || '—'}
                    </span>
                </div>
                <div className="flex items-baseline gap-2">
                    <span className={langTagClass}>{(run.b_language_code || 'b').toUpperCase()}</span>
                    <span className="font-serif text-[15px] leading-snug tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                        {run.b_entity_name || '—'}
                    </span>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className={`font-mono text-sm ${similarityClass(run.entity_similarity)}`}>
                    {run.entity_similarity !== null ? Number(run.entity_similarity).toFixed(4) : '—'}
                </span>
                <span className="flex items-center gap-1.5">
                    <span className="h-1 w-16 overflow-hidden rounded-full bg-[var(--wbench-rule)] dark:bg-[var(--wbench-rule-night)]">
                        <span
                            className="block h-full bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)] transition-[width] duration-300"
                            style={{width: `${Math.round(progress * 100)}%`}}
                        />
                    </span>
                    <span className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {run.confirmed_count} / {run.linked_count}
                    </span>
                </span>
                <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 font-mono text-[10px] uppercase tracking-[0.18em] ${STATUS_BADGE[run.status] ?? STATUS_BADGE.pending}`}>
                    {run.status}
                </span>
            </div>

            <p className="font-mono text-[10px] uppercase tracking-[0.18em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                {(run.a_language_code || 'a').toUpperCase()} {run.a_total_sentences ?? '—'}
                {' · '}
                {(run.b_language_code || 'b').toUpperCase()} {run.b_total_sentences ?? '—'}
                {run.created_at ? ` · ${new Date(run.created_at).toLocaleDateString()}` : ''}
            </p>

            <div className="relative z-10 mt-auto flex items-center gap-2 pt-1">
                <Link
                    href={`/bilinguals/simulator/${run.id}`}
                    className="inline-flex h-8 items-center border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-3 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] transition-colors hover:border-[var(--wbench-accent)] hover:text-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                >
                    {t('library.simulator')}
                </Link>
                {run.reader_target && (
                    <Link
                        href={`/reader/${run.reader_target.entity_id}`}
                        className="inline-flex h-8 items-center border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-3 font-sans text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] transition-colors hover:border-[var(--wbench-accent)] hover:text-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                    >
                        {t('library.read_lang', {lang: (run.reader_target.lang || '').toUpperCase()})}
                    </Link>
                )}
            </div>
        </div>
    );
}
