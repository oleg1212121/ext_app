import {useDroppable} from '@dnd-kit/core';
import {SortableContext, verticalListSortingStrategy} from '@dnd-kit/sortable';
import SentenceItem from './SentenceItem.jsx';

const railBtn = [
    'inline-flex h-7 items-center px-2.5 font-mono text-[11px] uppercase tracking-[0.14em]',
    'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]',
    'border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] rounded-sm',
    'hover:border-[var(--wbench-accent)] hover:text-[var(--wbench-accent)]',
    'dark:hover:border-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-accent-night)]',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]',
    'disabled:opacity-40 disabled:cursor-not-allowed',
].join(' ');

function SentenceColumn({lang, containerKey, keys, lookup, adding, draft, busy, editing, onAddStart, onAddChange, onAddCommit, onAddCancel, onStartEdit, onEditChange, onCommitEdit, onCancelEdit, onUnlink}) {
    const {setNodeRef, isOver} = useDroppable({id: containerKey});

    const sentences = keys.map((key) => lookup.get(key)).filter(Boolean);

    return (
        <div
            ref={setNodeRef}
            className={[
                'flex min-h-[64px] flex-col px-2 py-1.5 transition-colors',
                isOver ? 'bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)]' : '',
            ].join(' ')}
        >
            <div className="flex items-center justify-between px-1 pb-1">
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {lang}
                </span>
                <button
                    type="button"
                    onClick={onAddStart}
                    disabled={busy}
                    className={[
                        'font-mono text-[10px] uppercase tracking-[0.14em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]',
                        'hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)]',
                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm px-1 py-0.5',
                        'disabled:opacity-40 disabled:cursor-not-allowed',
                    ].join(' ')}
                >
                    + Add
                </button>
            </div>

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
                        onUnlink={onUnlink}
                    />
                ))}
            </SortableContext>

            {adding && (
                <div className="mt-1 flex flex-col gap-1 rounded-sm border border-dashed border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] p-1.5">
                    <textarea
                        value={draft}
                        onChange={(e) => onAddChange(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                                e.preventDefault();
                                onAddCommit(lang);
                            }
                            if (e.key === 'Escape') {
                                e.preventDefault();
                                onAddCancel();
                            }
                        }}
                        disabled={busy}
                        rows={2}
                        autoFocus
                        placeholder={`New ${lang} sentence…`}
                        className="min-w-0 flex-1 resize-none rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-2 py-1 font-serif text-[15px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] focus:outline-none focus:ring-1 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)]"
                    />
                    <div className="flex justify-end gap-1">
                        <button type="button" onClick={() => onAddCommit(lang)} disabled={busy} aria-label="Save" className="h-7 px-2.5 rounded-sm bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)] text-[var(--wbench-paper)] dark:text-[var(--wbench-paper-night)] font-mono text-[11px] uppercase tracking-[0.14em] hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] disabled:opacity-40">
                            Save
                        </button>
                        <button type="button" onClick={onAddCancel} disabled={busy} aria-label="Cancel" className="h-7 px-2.5 rounded-sm font-mono text-[11px] uppercase tracking-[0.14em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] disabled:opacity-40">
                            Cancel
                        </button>
                    </div>
                </div>
            )}

            {sentences.length === 0 && !adding && (
                <p className="px-1 pb-1 font-mono text-[10px] text-[var(--wbench-ink-soft)]/60 dark:text-[var(--wbench-ink-soft-night)]/60">
                    empty — drop or add a sentence
                </p>
            )}
        </div>
    );
}

export default function PairRow({row, enKeys, ruKeys, lookup, editing, adding, draft, busy, onAddStart, onAddChange, onAddCommit, onAddCancel, onStartEdit, onEditChange, onCommitEdit, onCancelEdit, onUnlink, onCreateBelow, onDelete, onApprove}) {
    return (
        <section className="border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] last:border-b-0">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] px-3 py-1.5">
                <div className="flex items-center gap-3">
                    <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        #{row.order}
                    </span>
                    {row.similarity !== null && (
                        <span className="font-mono text-[10px] tabular-nums text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            sim {Number(row.similarity).toFixed(4)}
                        </span>
                    )}
                </div>

                <div className="flex items-center gap-1.5">
                    <button type="button" onClick={() => onApprove(row)} disabled={busy} title="Approve" aria-label="Approve pair" className={railBtn}>
                        Approve
                    </button>
                    <button type="button" onClick={() => onCreateBelow(row)} disabled={busy} className={railBtn}>
                        Create below
                    </button>
                    <button type="button" onClick={() => onDelete(row)} disabled={busy} aria-label="Delete pair" title="Delete pair — sentences move to unmatched" className={`${railBtn} hover:border-[var(--wbench-danger)] hover:text-[var(--wbench-danger)] dark:hover:border-[var(--wbench-danger-night)] dark:hover:text-[var(--wbench-danger-night)]`}>
                        Delete
                    </button>
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2">
                <div className="border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] sm:border-b-0 sm:border-r sm:border-r-[var(--wbench-rule)] sm:dark:border-r-[var(--wbench-rule-night)]">
                    <SentenceColumn
                        lang="en"
                        containerKey={`row:${row.id}:en`}
                        keys={enKeys}
                        lookup={lookup}
                        adding={adding?.lang === 'en'}
                        draft={draft}
                        busy={busy}
                        editing={editing}
                        onAddStart={() => onAddStart(row, 'en')}
                        onAddChange={onAddChange}
                        onAddCommit={onAddCommit}
                        onAddCancel={onAddCancel}
                        onStartEdit={onStartEdit}
                        onEditChange={onEditChange}
                        onCommitEdit={onCommitEdit}
                        onCancelEdit={onCancelEdit}
                        onUnlink={onUnlink}
                    />
                </div>
                <div>
                    <SentenceColumn
                        lang="ru"
                        containerKey={`row:${row.id}:ru`}
                        keys={ruKeys}
                        lookup={lookup}
                        adding={adding?.lang === 'ru'}
                        draft={draft}
                        busy={busy}
                        editing={editing}
                        onAddStart={() => onAddStart(row, 'ru')}
                        onAddChange={onAddChange}
                        onAddCommit={onAddCommit}
                        onAddCancel={onAddCancel}
                        onStartEdit={onStartEdit}
                        onEditChange={onEditChange}
                        onCommitEdit={onCommitEdit}
                        onCancelEdit={onCancelEdit}
                        onUnlink={onUnlink}
                    />
                </div>
            </div>
        </section>
    );
}
