import {useSortable} from '@dnd-kit/sortable';
import {CSS} from '@dnd-kit/utilities';
import {CheckIcon, EditIcon, TrashIcon, UnlinkIcon, XIcon} from './icons.jsx';
import {useI18n} from '../../../i18n';

const iconBtn = [
    'inline-flex h-7 w-7 items-center justify-center rounded-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]',
    'hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)]',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]',
    'disabled:opacity-40 disabled:cursor-not-allowed',
].join(' ');

const DragHandle = ({attributes, listeners, dragging, label}) => (
    <button
        type="button"
        aria-label={label}
        title={label}
        className={[
            'group/drag inline-flex w-5 shrink-0 cursor-grab items-center justify-center rounded-sm py-2',
            'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]',
            dragging ? 'cursor-grabbing' : '',
        ].join(' ')}
        {...attributes}
        {...listeners}
    >
        <span className="flex flex-col gap-[3px]" aria-hidden="true">
            <span className="h-px w-3 bg-[var(--wbench-rule)] transition-colors group-hover/drag:bg-[var(--wbench-accent)] dark:bg-[var(--wbench-rule-night)] dark:group-hover/drag:bg-[var(--wbench-accent-night)]"/>
            <span className="h-px w-3 bg-[var(--wbench-rule)] transition-colors group-hover/drag:bg-[var(--wbench-accent)] dark:bg-[var(--wbench-rule-night)] dark:group-hover/drag:bg-[var(--wbench-accent-night)]"/>
        </span>
    </button>
);

const DEFAULT_CONTENT = '';

export default function SentenceItem({item, side, editing, draft, busy, onStartEdit, onChangeDraft, onCommitEdit, onCancelEdit, onUnlink, onRemove}) {
    const {t} = useI18n();
    const {attributes, listeners, setNodeRef, transform, transition, isDragging} = useSortable({id: item.key});

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
    };

    const showUnlink = typeof onUnlink === 'function';
    const showRemove = typeof onRemove === 'function';

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={[
                'group flex items-start gap-1 rounded-sm border border-transparent py-1 pl-0.5 pr-1',
                isDragging
                    ? 'z-10 border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] opacity-90 shadow-sm'
                    : 'hover:bg-[var(--wbench-paper-deep)] dark:hover:bg-[var(--wbench-paper-deep-night)]',
            ].join(' ')}
        >
            <DragHandle attributes={attributes} listeners={listeners} dragging={isDragging} label={t('alignments.drag_to_move')}/>

            <span className="mt-1.5 w-9 shrink-0 text-right font-mono text-[10px] tabular-nums text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                {item.display_order}
            </span>

            {editing ? (
                <div className="flex flex-1 items-start gap-1">
                    <textarea
                        value={draft}
                        onChange={(e) => onChangeDraft(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                                e.preventDefault();
                                onCommitEdit();
                            }
                            if (e.key === 'Escape') {
                                e.preventDefault();
                                onCancelEdit();
                            }
                        }}
                        disabled={busy}
                        rows={2}
                        autoFocus
                        placeholder={t('alignments.sentence_placeholder')}
                        className="min-w-0 flex-1 resize-none rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-2 py-1 font-serif text-[15px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] focus:outline-none focus:ring-1 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)]"
                    />
                    <button type="button" onClick={onCommitEdit} disabled={busy} aria-label={t('alignments.save')} title={t('alignments.save_hint')} className={`${iconBtn} text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]`}>
                        <CheckIcon/>
                    </button>
                    <button type="button" onClick={onCancelEdit} disabled={busy} aria-label={t('alignments.cancel')} title={t('alignments.cancel_hint')} className={iconBtn}>
                        <XIcon/>
                    </button>
                </div>
            ) : (
                <div className="min-w-0 flex-1">
                    {item.image && (
                        <a href={item.image.url} target="_blank" rel="noreferrer" className="mb-1 inline-block max-w-full">
                            <img
                                src={item.image.url}
                                alt={item.content || t('alignments.illustration')}
                                loading="lazy"
                                decoding="async"
                                className="max-h-28 w-auto max-w-full rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]"
                            />
                        </a>
                    )}
                    {(item.content !== '' || !item.image) && (
                        <p className="min-w-0 whitespace-pre-wrap break-words font-serif text-[15px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {item.content || DEFAULT_CONTENT}
                        </p>
                    )}
                </div>
            )}

            {!editing && (
                <span className="flex shrink-0 items-center opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100">
                    <button type="button" onClick={() => onStartEdit(item.key, side)} aria-label={t('alignments.edit_sentence')} title={t('alignments.edit')} className={iconBtn}>
                        <EditIcon/>
                    </button>
                    {showUnlink && (
                        <button type="button" onClick={() => onUnlink(item)} aria-label={t('alignments.unlink_sentence')} title={t('alignments.unlink_hint')} className={iconBtn}>
                            <UnlinkIcon/>
                        </button>
                    )}
                    {showRemove && (
                        <button type="button" onClick={() => onRemove(item)} aria-label={t('alignments.delete_sentence')} title={t('alignments.delete_permanently')} className={`${iconBtn} hover:text-[var(--wbench-danger)] dark:hover:text-[var(--wbench-danger-night)]`}>
                            <TrashIcon/>
                        </button>
                    )}
                </span>
            )}
        </div>
    );
}
