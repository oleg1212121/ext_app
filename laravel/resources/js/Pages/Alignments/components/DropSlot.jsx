import {useDndContext, useDroppable} from '@dnd-kit/core';
import {useI18n} from '../../../i18n';

export default function DropSlot({slotId, standalone, inert}) {
    const {active} = useDndContext();
    const {t} = useI18n();
    const {setNodeRef, isOver} = useDroppable({id: slotId});

    const dragging = active != null;
    const highlighted = !inert && isOver && (dragging || standalone);

    return (
        <div
            ref={inert ? undefined : setNodeRef}
            className={[
                'flex items-center justify-center rounded-sm border border-dashed',
                standalone ? 'my-1 min-h-9' : 'my-0.5 h-2',
                inert
                    ? 'border-transparent'
                    : highlighted
                        ? 'border-[var(--wbench-accent)] dark:border-[var(--wbench-accent-night)] bg-[var(--wbench-accent)]/30 dark:bg-[var(--wbench-accent-night)]/30'
                        : 'border-[var(--wbench-rule)]/50 dark:border-[var(--wbench-rule-night)]/50',
            ].join(' ')}
        >
            {highlighted && (
                <span className="font-mono text-[9px] uppercase tracking-[0.2em] text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]">
                    {standalone ? t('alignments.drop_here') : t('alignments.drop')}
                </span>
            )}
        </div>
    );
}