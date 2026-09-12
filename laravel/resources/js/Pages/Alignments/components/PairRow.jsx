import {Fragment} from 'react';
import {useDndContext} from '@dnd-kit/core';
import {SortableContext, verticalListSortingStrategy} from '@dnd-kit/sortable';
import SentenceItem from './SentenceItem.jsx';
import DropSlot from './DropSlot.jsx';
import {useI18n} from '../../../i18n';

const railBtn = [
    'inline-flex h-7 items-center px-2.5 font-mono text-[11px] uppercase tracking-[0.14em]',
    'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]',
    'border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] rounded-sm',
    'hover:border-[var(--wbench-accent)] hover:text-[var(--wbench-accent)]',
    'dark:hover:border-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-accent-night)]',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]',
    'disabled:opacity-40 disabled:cursor-not-allowed',
].join(' ');

function SentenceColumn({side, sideLabel, containerKey, keys, lookup, adding, draft, busy, editing, onAddStart, onAddChange, onAddCommit, onAddCancel, onStartEdit, onEditChange, onCommitEdit, onCancelEdit, onUnlink}) {
    const {t} = useI18n();
    const {active} = useDndContext();
    const sentences = keys.map((key) => lookup.get(key)).filter(Boolean);
    // The dragged sentence still occupies its home slot in the frozen list, so
    // the two drop zones directly above and below it only mean "keep it" and
    // "move it one down" — confusing. Render them as inert spacers.
    const activeIndex = active != null ? keys.indexOf(active.id) : -1;
    const isAdjacentToActive = (index) => activeIndex !== -1 && (index === activeIndex || index === activeIndex + 1);

    return (
        <div className="flex min-h-[64px] flex-col px-2 py-1.5">
            <div className="flex items-center justify-between px-1 pb-1">
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {sideLabel ?? side}
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
                    {t('alignments.add')}
                </button>
            </div>

            <SortableContext items={keys} strategy={verticalListSortingStrategy}>
                {sentences.map((sentence, index) => (
                    <Fragment key={sentence.key}>
                        <DropSlot slotId={`slot:${containerKey}:#${index}`} inert={isAdjacentToActive(index)}/>
                        <SentenceItem
                            item={sentence}
                            side={side}
                            editing={editing?.key === sentence.key}
                            draft={editing?.key === sentence.key ? (editing.draft ?? '') : ''}
                            busy={busy}
                            onStartEdit={() => onStartEdit(sentence.key, side)}
                            onChangeDraft={onEditChange}
                            onCommitEdit={onCommitEdit}
                            onCancelEdit={onCancelEdit}
                            onUnlink={onUnlink}
                        />
                    </Fragment>
                ))}
                <DropSlot slotId={`slot:${containerKey}:#${keys.length}`} standalone={keys.length === 0}/>
            </SortableContext>

            {adding && (
                <div className="mt-1 flex flex-col gap-1 rounded-sm border border-dashed border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] p-1.5">
                    <textarea
                        value={draft}
                        onChange={(e) => onAddChange(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                                e.preventDefault();
                                onAddCommit(side);
                            }
                            if (e.key === 'Escape') {
                                e.preventDefault();
                                onAddCancel();
                            }
                        }}
                        disabled={busy}
                        rows={2}
                        autoFocus
                        placeholder={t('alignments.new_sentence', {side})}
                        className="min-w-0 flex-1 resize-none rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-2 py-1 font-serif text-[15px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] focus:outline-none focus:ring-1 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)]"
                    />
                    <div className="flex justify-end gap-1">
                        <button type="button" onClick={() => onAddCommit(side)} disabled={busy} aria-label={t('alignments.save')} className="h-7 px-2.5 rounded-sm bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)] text-[var(--wbench-paper)] dark:text-[var(--wbench-paper-night)] font-mono text-[11px] uppercase tracking-[0.14em] hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] disabled:opacity-40">
                            {t('alignments.save')}
                        </button>
                        <button type="button" onClick={onAddCancel} disabled={busy} aria-label={t('alignments.cancel')} className="h-7 px-2.5 rounded-sm font-mono text-[11px] uppercase tracking-[0.14em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] disabled:opacity-40">
                            {t('alignments.cancel')}
                        </button>
                    </div>
                </div>
            )}

            {sentences.length === 0 && !adding && (
                <p className="px-1 pb-1 font-mono text-[10px] text-[var(--wbench-ink-soft)]/60 dark:text-[var(--wbench-ink-soft-night)]/60">
                    {t('alignments.empty_column')}
                </p>
            )}
        </div>
    );
}

export default function PairRow({row, position, aKeys, bKeys, sideLabels, lookup, editing, adding, draft, busy, highlighted, onAddStart, onAddChange, onAddCommit, onAddCancel, onStartEdit, onEditChange, onCommitEdit, onCancelEdit, onUnlink, onCreateBelow, onDelete, onApprove}) {
    const {t} = useI18n();
    return (
        <section
            data-row-id={row.id}
            className={[
                'border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] last:border-b-0 transition-shadow',
                highlighted ? 'ring-2 ring-[var(--wbench-accent)] dark:ring-[var(--wbench-accent-night)]' : '',
            ].join(' ')}
        >
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] px-3 py-1.5">
                <div className="flex items-center gap-3">
                    <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        #{position}
                    </span>
                    {row.similarity !== null && (
                        <span className="font-mono text-[10px] tabular-nums text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('alignments.sim')} {Number(row.similarity).toFixed(4)}
                        </span>
                    )}
                </div>

                <div className="flex items-center gap-1.5">
                    <button type="button" onClick={() => onApprove(row)} disabled={busy} title={t('alignments.approve')} aria-label={t('alignments.approve_pair')} className={railBtn}>
                        {t('alignments.approve')}
                    </button>
                    <button type="button" onClick={() => onCreateBelow(row)} disabled={busy} className={railBtn}>
                        {t('alignments.create_below')}
                    </button>
                    <button type="button" onClick={() => onDelete(row)} disabled={busy} aria-label={t('alignments.delete_pair')} title={t('alignments.delete_pair_hint')} className={`${railBtn} hover:border-[var(--wbench-danger)] hover:text-[var(--wbench-danger)] dark:hover:border-[var(--wbench-danger-night)] dark:hover:text-[var(--wbench-danger-night)]`}>
                        {t('alignments.delete')}
                    </button>
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2">
                <div className="border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] sm:border-b-0 sm:border-r sm:border-r-[var(--wbench-rule)] sm:dark:border-r-[var(--wbench-rule-night)]">
                    <SentenceColumn
                        side="a"
                        sideLabel={sideLabels.a}
                        containerKey={`row:${row.id}:a`}
                        keys={aKeys}
                        lookup={lookup}
                        adding={adding?.side === 'a' && adding?.rowId === row.id}
                        draft={draft}
                        busy={busy}
                        editing={editing}
                        onAddStart={() => onAddStart(row, 'a')}
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
                        side="b"
                        sideLabel={sideLabels.b}
                        containerKey={`row:${row.id}:b`}
                        keys={bKeys}
                        lookup={lookup}
                        adding={adding?.side === 'b' && adding?.rowId === row.id}
                        draft={draft}
                        busy={busy}
                        editing={editing}
                        onAddStart={() => onAddStart(row, 'b')}
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
