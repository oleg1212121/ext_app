import {useSortable} from '@dnd-kit/sortable';
import {CSS} from '@dnd-kit/utilities';

const iconBtn = [
    'inline-flex h-7 w-7 items-center justify-center rounded-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]',
    'hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)]',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]',
    'disabled:opacity-40 disabled:cursor-not-allowed',
].join(' ');

const DragHandle = ({attributes, listeners, dragging}) => (
    <button
        type="button"
        aria-label="Drag to move"
        title="Drag to move"
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

const EditIcon = () => (
    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
        <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/>
    </svg>
);

const CheckIcon = () => (
    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
        <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
    </svg>
);

const XIcon = () => (
    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
    </svg>
);

const UnlinkIcon = () => (
    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
        <path strokeLinecap="round" strokeLinejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/>
    </svg>
);

const TrashIcon = () => (
    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
        <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
    </svg>
);

const DEFAULT_CONTENT = '';

export default function SentenceItem({item, lang, editing, draft, busy, onStartEdit, onChangeDraft, onCommitEdit, onCancelEdit, onUnlink, onRemove}) {
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
            <DragHandle attributes={attributes} listeners={listeners} dragging={isDragging}/>

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
                        placeholder="Sentence text…"
                        className="min-w-0 flex-1 resize-none rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-2 py-1 font-serif text-[15px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] focus:outline-none focus:ring-1 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)]"
                    />
                    <button type="button" onClick={onCommitEdit} disabled={busy} aria-label="Save" title="Save (⌘+Enter)" className={`${iconBtn} text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]`}>
                        <CheckIcon/>
                    </button>
                    <button type="button" onClick={onCancelEdit} disabled={busy} aria-label="Cancel" title="Cancel (Esc)" className={iconBtn}>
                        <XIcon/>
                    </button>
                </div>
            ) : (
                <p className="min-w-0 flex-1 whitespace-pre-wrap break-words font-serif text-[15px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                    {item.content || DEFAULT_CONTENT}
                </p>
            )}

            {!editing && (
                <span className="flex shrink-0 items-center opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100">
                    <button type="button" onClick={() => onStartEdit(item.key, lang)} aria-label="Edit sentence" title="Edit" className={iconBtn}>
                        <EditIcon/>
                    </button>
                    {showUnlink && (
                        <button type="button" onClick={() => onUnlink(item)} aria-label="Unlink sentence" title="Unlink — move to unmatched" className={iconBtn}>
                            <UnlinkIcon/>
                        </button>
                    )}
                    {showRemove && (
                        <button type="button" onClick={() => onRemove(item)} aria-label="Delete sentence" title="Delete permanently" className={`${iconBtn} hover:text-[var(--wbench-danger)] dark:hover:text-[var(--wbench-danger-night)]`}>
                            <TrashIcon/>
                        </button>
                    )}
                </span>
            )}
        </div>
    );
}
