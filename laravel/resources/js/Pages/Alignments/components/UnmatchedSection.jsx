import {Fragment} from 'react';
import {useDndContext} from '@dnd-kit/core';
import {SortableContext, verticalListSortingStrategy} from '@dnd-kit/sortable';
import SentenceItem from './SentenceItem.jsx';
import DropSlot from './DropSlot.jsx';
import Pagination from './Pagination.jsx';
import {useI18n} from '../../../i18n';

function UnmatchedPool({side, sideLabel, containerKey, keys, lookup, meta, busy, editing, onStartEdit, onEditChange, onCommitEdit, onCancelEdit, onRemove, onPageChange}) {
    const {t} = useI18n();
    const {active} = useDndContext();
    const sentences = keys.map((key) => lookup.get(key)).filter(Boolean);
    const activeIndex = active != null ? keys.indexOf(active.id) : -1;
    const isAdjacentToActive = (index) => activeIndex !== -1 && (index === activeIndex || index === activeIndex + 1);

    return (
        <div className="flex flex-col border-r border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] last:border-r-0">
            <div className="flex items-center justify-between border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-3 py-1.5">
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {sideLabel ?? side} · {meta.total}
                </span>
            </div>

            <div className="flex min-h-[64px] flex-col px-2 py-1.5">
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
                                onRemove={onRemove}
                            />
                        </Fragment>
                    ))}
                    <DropSlot slotId={`slot:${containerKey}:#${keys.length}`} standalone={keys.length === 0}/>
                </SortableContext>

                {sentences.length === 0 && (
                    <p className="px-1 pb-1 font-mono text-[10px] text-[var(--wbench-ink-soft)]/60 dark:text-[var(--wbench-ink-soft-night)]/60">
                        {t('alignments.none')}
                    </p>
                )}
            </div>

            <div className="mt-auto">
                <Pagination
                    meta={meta}
                    busy={busy}
                    showTotal={false}
                    onPage={(page) => onPageChange(side, page)}
                    onPerPage={null}
                />
            </div>
        </div>
    );
}

export default function UnmatchedSection({expanded, aKeys, bKeys, sideLabels, lookup, unmatchedA, unmatchedB, busy, editing, onToggle, onStartEdit, onEditChange, onCommitEdit, onCancelEdit, onRemove, onPageChange}) {
    const {t} = useI18n();
    return (
        <section className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
            <button
                type="button"
                onClick={onToggle}
                className="flex w-full items-center justify-between gap-2 bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] px-3 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
                aria-expanded={expanded}
            >
                <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    {t('alignments.unmatched')}
                </span>
                <span className="font-mono text-[10px] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                    EN {unmatchedA.meta.total} / RU {unmatchedB.meta.total}
                </span>
            </button>

            {expanded && (
                <div className="grid grid-cols-1 sm:grid-cols-2">
                    <UnmatchedPool
                        side="a"
                        sideLabel={sideLabels.a}
                        containerKey="unmatched:a"
                        keys={aKeys}
                        lookup={lookup}
                        meta={unmatchedA.meta}
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
                        side="b"
                        sideLabel={sideLabels.b}
                        containerKey="unmatched:b"
                        keys={bKeys}
                        lookup={lookup}
                        meta={unmatchedB.meta}
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
