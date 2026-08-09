import {useDroppable} from '@dnd-kit/core';
import {SortableContext, verticalListSortingStrategy} from '@dnd-kit/sortable';
import SentenceItem from './SentenceItem.jsx';

function UnmatchedPool({lang, containerKey, keys, lookup, meta, busy, editing, onStartEdit, onEditChange, onCommitEdit, onCancelEdit, onRemove, onPageChange}) {
    const {setNodeRef, isOver} = useDroppable({id: containerKey});
    const sentences = keys.map((key) => lookup.get(key)).filter(Boolean);

    return (
        <div className={['flex flex-col border-r border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] last:border-r-0', isOver ? 'bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)]' : ''].join(' ')}>
            <div className="flex items-center justify-between border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-3 py-1.5">
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {lang} · {meta.total}
                </span>
                <span className="font-mono text-[10px] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {meta.current_page} / {Math.max(meta.last_page, 1)}
                </span>
            </div>

            <div ref={setNodeRef} className="flex min-h-[64px] flex-col px-2 py-1.5">
                <SortableContext items={keys} strategy={verticalListSortingStrategy}>
                    {sentences.map((sentence) => (
                        <SentenceItem
                            key={sentence.key}
                            item={sentence}
                            lang={lang}
                            editing={editing?.key === sentence.key}
                            draft={editing?.key === sentence.key ? (editing.draft ?? '') : ''}
                            busy={busy}
                            onStartEdit={() => onStartEdit(sentence.key, lang)}
                            onChangeDraft={onEditChange}
                            onCommitEdit={onCommitEdit}
                            onCancelEdit={onCancelEdit}
                            onRemove={onRemove}
                        />
                    ))}
                </SortableContext>

                {sentences.length === 0 && (
                    <p className="px-1 pb-1 font-mono text-[10px] text-[var(--wbench-ink-soft)]/60 dark:text-[var(--wbench-ink-soft-night)]/60">
                        none
                    </p>
                )}
            </div>

            <div className="mt-auto flex items-center justify-between border-t border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-2 py-1">
                <button
                    type="button"
                    onClick={() => onPageChange(lang, meta.current_page - 1)}
                    disabled={meta.current_page <= 1 || busy}
                    aria-label="Previous unmatched page"
                    className="inline-flex h-6 items-center px-1.5 font-mono text-[11px] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)] disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                >
                    ←
                </button>
                <button
                    type="button"
                    onClick={() => onPageChange(lang, meta.current_page + 1)}
                    disabled={meta.current_page >= meta.last_page || busy}
                    aria-label="Next unmatched page"
                    className="inline-flex h-6 items-center px-1.5 font-mono text-[11px] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)] disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                >
                    →
                </button>
            </div>
        </div>
    );
}

export default function UnmatchedSection({expanded, enKeys, ruKeys, lookup, unmatchedEn, unmatchedRu, busy, editing, onToggle, onStartEdit, onEditChange, onCommitEdit, onCancelEdit, onRemove, onPageChange}) {
    return (
        <section className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
            <button
                type="button"
                onClick={onToggle}
                className="flex w-full items-center justify-between gap-2 bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] px-3 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
                aria-expanded={expanded}
            >
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    Unmatched
                </span>
                <span className="font-mono text-[10px] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    EN {unmatchedEn.meta.total} / RU {unmatchedRu.meta.total}
                </span>
            </button>

            {expanded && (
                <div className="grid grid-cols-1 sm:grid-cols-2">
                    <UnmatchedPool
                        lang="en"
                        containerKey="unmatched:en"
                        keys={enKeys}
                        lookup={lookup}
                        meta={unmatchedEn.meta}
                        busy={busy}
                        editing={editing}
                        onStartEdit={onStartEdit}
                        onEditChange={onEditChange}
                        onCommitEdit={onCommitEdit}
                        onCancelEdit={onCancelEdit}
                        onRemove={onRemove}
                        onPageChange={onPageChange}
                    />
                    <UnmatchedPool
                        lang="ru"
                        containerKey="unmatched:ru"
                        keys={ruKeys}
                        lookup={lookup}
                        meta={unmatchedRu.meta}
                        busy={busy}
                        editing={editing}
                        onStartEdit={onStartEdit}
                        onEditChange={onEditChange}
                        onCommitEdit={onCommitEdit}
                        onCancelEdit={onCancelEdit}
                        onRemove={onRemove}
                        onPageChange={onPageChange}
                    />
                </div>
            )}
        </section>
    );
}
