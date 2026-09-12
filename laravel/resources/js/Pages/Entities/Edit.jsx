import {useEffect, useRef, useState, useCallback, Fragment} from 'react';
import {useForm, Link} from '@inertiajs/react';
import {
    DndContext,
    PointerSensor,
    KeyboardSensor,
    useSensor,
    useSensors,
    closestCenter,
} from '@dnd-kit/core';
import {
    SortableContext,
    sortableKeyboardCoordinates,
    verticalListSortingStrategy,
    useSortable,
} from '@dnd-kit/sortable';
import {CSS} from '@dnd-kit/utilities';
import Main from '../../Layouts/Main.jsx';
import Pagination from '../Alignments/components/Pagination.jsx';
import {useI18n} from '../../i18n';

const PER_PAGE_OPTIONS = [10, 25, 50, 100];

const fieldBase = [
    'mt-1 block w-full rounded-sm border px-3 py-2 text-sm shadow-sm',
    'bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]',
    'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]',
    'focus:outline-none focus:ring-2 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)] focus:border-transparent',
].join(' ');

const InputLabel = ({htmlFor, children}) => (
    <label htmlFor={htmlFor} className="block text-sm font-medium text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
        {children}
    </label>
);

const InputError = ({messages = []}) => {
    if (!messages.length) return null;
    return <p className="mt-2 text-sm text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)]">{messages.join(' ')}</p>;
};

const PrimaryButton = ({children, disabled = false, type = 'submit', onClick}) => (
    <button
        type={type}
        disabled={disabled}
        onClick={onClick}
        className={[
            'inline-flex items-center px-4 py-2 rounded-sm text-sm font-medium transition-colors',
            'bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)]',
            'text-white dark:text-[var(--wbench-ink-night)]',
            'hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] dark:focus-visible:ring-[var(--wbench-accent-night)]',
            'disabled:opacity-50',
        ].join(' ')}
    >
        {children}
    </button>
);

const GhostButton = ({children, disabled = false, onClick, title}) => (
    <button
        type="button"
        onClick={onClick}
        disabled={disabled}
        title={title}
        className={[
            'inline-flex items-center px-3 py-1.5 rounded-sm text-sm transition-colors border',
            'border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
            'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]',
            'hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)] hover:border-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)]',
            'disabled:opacity-50',
        ].join(' ')}
    >
        {children}
    </button>
);

const DragHandle = ({attributes, listeners, dragging}) => {
    const {t} = useI18n();
    return (
    <button
        type="button"
        aria-label={t('entities.drag_to_move')}
        title={t('entities.drag_to_move')}
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
};

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

const PlusIcon = () => (
    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
        <path strokeLinecap="round" strokeLinejoin="round" d="M12 5v14M5 12h14"/>
    </svg>
);

const TrashIcon = () => (
    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
        <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
    </svg>
);

const iconBtn = [
    'inline-flex h-7 w-7 items-center justify-center rounded-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]',
    'hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)]',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]',
    'disabled:opacity-40 disabled:cursor-not-allowed',
].join(' ');

const AddForm = ({draft, type, sentenceTypes, busy, submitLabel, onDraftChange, onTypeChange, onSubmit, onCancel}) => {
    const {t} = useI18n();
    return (
    <div className="space-y-2 border-t border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] px-4 py-3">
        <textarea
            value={draft}
            onChange={(e) => onDraftChange(e.target.value)}
            onKeyDown={(e) => {
                if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); onSubmit(); }
                if (e.key === 'Escape') { e.preventDefault(); onCancel(); }
            }}
            disabled={busy}
            rows={2}
            autoFocus
            placeholder={t('entities.sentence_text_placeholder')}
            className="min-w-0 w-full resize-none rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-2 py-1 font-serif text-[15px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] focus:outline-none focus:ring-1 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)]"
        />
        <div className="flex items-end gap-3">
            <div>
                <InputLabel htmlFor="add-type-inline">{t('entities.type')}</InputLabel>
                <select
                    id="add-type-inline"
                    value={type}
                    onChange={(e) => onTypeChange(e.target.value)}
                    required
                    disabled={busy}
                    className={[fieldBase, 'w-auto'].join(' ')}
                >
                    <option value="" disabled>{t('entities.select')}</option>
                    {sentenceTypes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
            </div>
            <PrimaryButton type="button" disabled={busy || !draft.trim() || !type} onClick={onSubmit}>{submitLabel}</PrimaryButton>
            <GhostButton onClick={onCancel} disabled={busy}>{t('entities.cancel')}</GhostButton>
        </div>
    </div>
    );
};

function SortableSentence({sentence, displayOrder, sentenceTypes, editingId, editDraft, editType, busyId, addingAfterId, addDraft, addType, onStartEdit, onChangeDraft, onChangeType, onCommitEdit, onCancelEdit, onDelete, onAddStart, onAddDraftChange, onAddTypeChange, onAddSubmit, onAddCancel}) {
    const {attributes, listeners, setNodeRef, transform, transition, isDragging} = useSortable({id: sentence.id});
    const {t} = useI18n();
    const editing = editingId === sentence.id;
    const busy = busyId === sentence.id;

    const style = {transform: CSS.Transform.toString(transform), transition};

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={[
                'group flex items-start gap-2 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-4 py-3',
                isDragging ? 'z-10 opacity-80 bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] shadow-sm' : '',
            ].join(' ')}
        >
            <DragHandle attributes={attributes} listeners={listeners} dragging={isDragging}/>

            <span className="mt-1.5 w-12 shrink-0 text-right font-mono text-[10px] tabular-nums text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                {String(displayOrder).padStart(3, '0')}
            </span>

            {editing ? (
                <div className="flex flex-1 flex-col gap-2">
                    <textarea
                        value={editDraft}
                        onChange={(e) => onChangeDraft(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); onCommitEdit(sentence); }
                            if (e.key === 'Escape') { e.preventDefault(); onCancelEdit(); }
                        }}
                        disabled={busy}
                        rows={2}
                        autoFocus
                        className="min-w-0 flex-1 resize-none rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-2 py-1 font-serif text-[15px] leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] focus:outline-none focus:ring-1 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)]"
                    />
                    <div className="flex items-center gap-2">
                        <select
                            value={editType}
                            onChange={(e) => onChangeType(e.target.value)}
                            disabled={busy}
                            className="rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-2 py-1 text-xs text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] focus:outline-none focus:ring-1 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)]"
                        >
                            {sentenceTypes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </select>
                        <button type="button" onClick={() => onCommitEdit(sentence)} disabled={busy} aria-label={t('entities.save')} title={t('entities.save_cmd_enter')} className={`${iconBtn} text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]`}>
                            <CheckIcon/>
                        </button>
                        <button type="button" onClick={onCancelEdit} disabled={busy} aria-label={t('entities.cancel')} title={t('entities.cancel_esc')} className={iconBtn}>
                            <XIcon/>
                        </button>
                    </div>
                </div>
            ) : (
                <>
                    <div className="min-w-0 flex-1">
                        <p className="whitespace-pre-wrap break-words text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {sentence.content}
                        </p>
                        {sentence.type && (
                            <p className="mt-0.5 font-mono text-[10px] uppercase tracking-[0.18em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                {sentence.type}
                            </p>
                        )}
                    </div>
                    <span className="flex shrink-0 items-center opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100">
                        <button type="button" onClick={() => onStartEdit(sentence)} aria-label={t('entities.edit_sentence')} title={t('entities.edit')} className={iconBtn}>
                            <EditIcon/>
                        </button>
                        <button type="button" onClick={() => onAddStart(sentence)} aria-label={t('entities.add_sentence_below')} title={t('entities.add_sentence_below')} className={iconBtn}>
                            <PlusIcon/>
                        </button>
                        <button type="button" onClick={() => onDelete(sentence)} disabled={busy} aria-label={t('entities.delete_sentence')} title={t('entities.delete_permanently')} className={`${iconBtn} hover:text-[var(--wbench-danger)] dark:hover:text-[var(--wbench-danger-night)]`}>
                            <TrashIcon/>
                        </button>
                    </span>
                </>
            )}
        </div>
    );
}

export default function Edit({lang, language, entity, sentenceTypes = [], alignmentCount, sentencesEndpoint}) {
    const {t} = useI18n();
    const {data, setData, patch, processing, errors, reset} = useForm({
        name: entity.name ?? '',
        description: entity.description ?? '',
    });

    const [sentences, setSentences] = useState([]);
    const [loading, setLoading] = useState(true);
    const [busyId, setBusyId] = useState(null);
    const [editingId, setEditingId] = useState(null);
    const [editDraft, setEditDraft] = useState('');
    const [editType, setEditType] = useState('');

    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(25);
    const [meta, setMeta] = useState(null);
    const [beforeFirstId, setBeforeFirstId] = useState(null);

    const [addingAfterId, setAddingAfterId] = useState(null);
    const [addingFirst, setAddingFirst] = useState(false);
    const [addContent, setAddContent] = useState('');
    const [addType, setAddType] = useState('');
    const [addError, setAddError] = useState('');

    const sensors = useSensors(
        useSensor(PointerSensor, {activationConstraint: {distance: 5}}),
        useSensor(KeyboardSensor, {coordinateGetter: sortableKeyboardCoordinates}),
    );

    const csrfToken = useRef(document.querySelector('meta[name="csrf-token"]')?.content).current;

    const loadSentences = useCallback(async (targetPage, targetPerPage) => {
        setLoading(true);
        try {
            const res = await fetch(`${sentencesEndpoint}?page=${targetPage}&per_page=${targetPerPage}`, {headers: {'Accept': 'application/json'}});
            if (res.ok) {
                const json = await res.json();
                setSentences(json.sentences ?? []);
                setMeta(json.meta ?? null);
                setBeforeFirstId(json.before_first_id ?? null);
                if (json.meta) {
                    setPage(json.meta.current_page);
                    setPerPage(json.meta.per_page);
                }
            }
        } finally {
            setLoading(false);
        }
    }, [sentencesEndpoint]);

    useEffect(() => { loadSentences(1, 25); }, [loadSentences]);

    const submitMetadata = (e) => {
        e.preventDefault();
        patch(`/entities/${lang}/${entity.id}`, {preserveScroll: true});
    };

    const onStartEdit = (sentence) => {
        setEditingId(sentence.id);
        setEditDraft(sentence.content);
        setEditType(String(sentence.sentence_type_id));
        setAddError('');
    };

    const onCancelEdit = () => {
        setEditingId(null);
        setEditDraft('');
        setEditType('');
    };

    const onCommitEdit = async (sentence) => {
        setBusyId(sentence.id);
        setAddError('');
        try {
            const res = await fetch(`/entities/${lang}/${entity.id}/sentences/${sentence.id}`, {
                method: 'PATCH',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                body: JSON.stringify({content: editDraft, sentence_type_id: parseInt(editType, 10)}),
            });
            if (res.ok) {
                const json = await res.json();
                setSentences((prev) => prev.map((s) => s.id === json.sentence.id ? json.sentence : s));
                onCancelEdit();
            } else if (res.status === 422) {
                const json = await res.json();
                setAddError(json.errors?.content?.[0] || json.errors?.sentence_type_id?.[0] || t('entities.validation_error'));
            }
        } finally {
            setBusyId(null);
        }
    };

    const onDelete = async (sentence) => {
        if (!confirm(t('entities.delete_sentence_confirm'))) return;
        setBusyId(sentence.id);
        setAddError('');
        try {
            const res = await fetch(`/entities/${lang}/${entity.id}/sentences/${sentence.id}?page=${page}&per_page=${perPage}`, {
                method: 'DELETE',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken},
            });
            if (res.ok) {
                const json = await res.json();
                setSentences(json.sentences ?? []);
                setMeta(json.meta ?? null);
                setBeforeFirstId(json.before_first_id ?? null);
                if (json.meta) {
                    setPage(json.meta.current_page);
                    setPerPage(json.meta.per_page);
                }
            }
        } finally {
            setBusyId(null);
        }
    };

    const onDragEnd = async (e) => {
        const {active, over} = e;
        if (!over || active.id === over.id) return;

        const oldIndex = sentences.findIndex((s) => s.id === active.id);
        const newIndex = sentences.findIndex((s) => s.id === over.id);
        if (oldIndex === -1 || newIndex === -1) return;

        let afterSentenceId;
        if (oldIndex < newIndex) {
            afterSentenceId = sentences[newIndex].id;
        } else {
            afterSentenceId = newIndex > 0 ? sentences[newIndex - 1].id : (beforeFirstId ?? 0);
        }

        setBusyId(active.id);
        setAddError('');
        try {
            const res = await fetch(`/entities/${lang}/${entity.id}/sentences/reorder?page=${page}&per_page=${perPage}`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                body: JSON.stringify({sentence_id: active.id, after_sentence_id: afterSentenceId}),
            });
            if (res.ok) {
                const json = await res.json();
                setSentences(json.sentences ?? []);
                setMeta(json.meta ?? null);
                setBeforeFirstId(json.before_first_id ?? null);
                if (json.meta) {
                    setPage(json.meta.current_page);
                    setPerPage(json.meta.per_page);
                }
            }
        } finally {
            setBusyId(null);
        }
    };

    const onAddStart = (sentence) => {
        if (busyId) return;
        setEditingId(null);
        setAddingFirst(false);
        setAddingAfterId(sentence.id);
        setAddContent('');
        setAddType('');
        setAddError('');
    };

    const onAddStartFirst = () => {
        if (busyId) return;
        setAddingAfterId(null);
        setAddingFirst(true);
        setAddContent('');
        setAddType('');
        setAddError('');
    };

    const onAddCancel = () => {
        setAddingAfterId(null);
        setAddingFirst(false);
        setAddContent('');
        setAddType('');
    };

    const onAddSubmit = async (afterSentenceId) => {
        setBusyId('add');
        setAddError('');
        try {
            const res = await fetch(`/entities/${lang}/${entity.id}/sentences?page=${page}&per_page=${perPage}`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                body: JSON.stringify({
                    content: addContent,
                    sentence_type_id: parseInt(addType, 10),
                    after_sentence_id: afterSentenceId,
                }),
            });
            if (res.ok) {
                const json = await res.json();
                setSentences(json.sentences ?? []);
                setMeta(json.meta ?? null);
                setBeforeFirstId(json.before_first_id ?? null);
                if (json.meta) {
                    setPage(json.meta.current_page);
                    setPerPage(json.meta.per_page);
                }
                setAddContent('');
                setAddType('');
                setAddingAfterId(null);
                setAddingFirst(false);
            } else if (res.status === 422) {
                const json = await res.json();
                setAddError(json.errors?.content?.[0] || json.errors?.sentence_type_id?.[0] || t('entities.validation_error'));
            }
        } finally {
            setBusyId(null);
        }
    };

    const addBusy = busyId === 'add';

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-4xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <Link
                        href={`/entities/${lang}/${entity.id}`}
                        className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                    >
                        ← {t('entities.back_to', {name: entity.name})}
                    </Link>
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('entities.edit_entity')}
                        </p>
                        <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {entity.name}
                        </h1>
                    </div>
                </header>

                <form onSubmit={submitMetadata} className="space-y-5 border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] p-5">
                    <div>
                        <InputLabel htmlFor="name">{t('entities.name')}</InputLabel>
                        <input
                            id="name"
                            type="text"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                            className={[fieldBase, errors.name ? 'border-[var(--wbench-danger)] dark:border-[var(--wbench-danger-night)]' : 'border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]'].join(' ')}
                        />
                        <InputError messages={errors.name ? [errors.name] : []}/>
                    </div>
                    <div>
                        <InputLabel htmlFor="description">{t('entities.description')}</InputLabel>
                        <textarea
                            id="description"
                            rows={3}
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            className={[fieldBase, errors.description ? 'border-[var(--wbench-danger)] dark:border-[var(--wbench-danger-night)]' : 'border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]'].join(' ')}
                        />
                        <InputError messages={errors.description ? [errors.description] : []}/>
                    </div>
                    <div className="flex items-center gap-3">
                        <PrimaryButton disabled={processing}>{t('entities.save_metadata')}</PrimaryButton>
                        <Link href={`/entities/${lang}/${entity.id}`} className="font-sans text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]">
                            {t('entities.cancel')}
                        </Link>
                    </div>
                </form>

                <section className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                    <div className="flex items-center justify-between border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-3">
                        <h2 className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('entities.sentences')}
                        </h2>
                        {alignmentCount > 0 && (
                            <span className="font-mono text-[10px] uppercase tracking-[0.18em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                {t('entities.part_of')} {alignmentCount} {alignmentCount > 1 ? t('entities.alignments') : t('entities.alignment')}
                            </span>
                        )}
                    </div>

                    <InputError messages={addError ? [addError] : []}/>

                    {loading ? (
                        <p className="px-5 py-12 text-center text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('entities.loading_sentences')}
                        </p>
                    ) : sentences.length === 0 && !addingFirst ? (
                        <div className="px-5 py-12 text-center">
                            <p className="text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                {t('entities.no_sentences_yet')}
                            </p>
                            <button
                                type="button"
                                onClick={onAddStartFirst}
                                className="mt-3 inline-flex items-center gap-1 font-mono text-[11px] uppercase tracking-[0.14em] text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] hover:opacity-80 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                            >
                                <PlusIcon/> {t('entities.add_first_sentence')}
                            </button>
                        </div>
                    ) : (
                        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                            <SortableContext items={sentences.map((s) => s.id)} strategy={verticalListSortingStrategy}>
                                <div className="divide-y divide-[var(--wbench-rule)] dark:divide-[var(--wbench-rule-night)]">
                                    {sentences.map((sentence, index) => (
                                        <Fragment key={sentence.id}>
                                            <SortableSentence
                                                sentence={sentence}
                                                displayOrder={(page - 1) * perPage + index + 1}
                                                sentenceTypes={sentenceTypes}
                                                editingId={editingId}
                                                editDraft={editDraft}
                                                editType={editType}
                                                busyId={busyId}
                                                addingAfterId={addingAfterId}
                                                addDraft={addContent}
                                                addType={addType}
                                                onStartEdit={onStartEdit}
                                                onChangeDraft={setEditDraft}
                                                onChangeType={setEditType}
                                                onCommitEdit={onCommitEdit}
                                                onCancelEdit={onCancelEdit}
                                                onDelete={onDelete}
                                                onAddStart={onAddStart}
                                                onAddDraftChange={setAddContent}
                                                onAddTypeChange={setAddType}
                                                onAddSubmit={() => onAddSubmit(sentence.id)}
                                                onAddCancel={onAddCancel}
                                            />
                                            {addingAfterId === sentence.id && (
                                                <AddForm
                                                    draft={addContent}
                                                    type={addType}
                                                    sentenceTypes={sentenceTypes}
                                                    busy={addBusy}
                                                    submitLabel={t('entities.insert_below')}
                                                    onDraftChange={setAddContent}
                                                    onTypeChange={setAddType}
                                                    onSubmit={() => onAddSubmit(sentence.id)}
                                                    onCancel={onAddCancel}
                                                />
                                            )}
                                        </Fragment>
                                    ))}
                                </div>
                            </SortableContext>

                            {addingFirst && (
                                <AddForm
                                    draft={addContent}
                                    type={addType}
                                    sentenceTypes={sentenceTypes}
                                    busy={addBusy}
                                    submitLabel={t('entities.add_sentence')}
                                    onDraftChange={setAddContent}
                                    onTypeChange={setAddType}
                                    onSubmit={() => onAddSubmit(null)}
                                    onCancel={onAddCancel}
                                />
                            )}

                            {meta && (
                                <Pagination
                                    meta={meta}
                                    perPageOptions={PER_PAGE_OPTIONS}
                                    busy={loading || addBusy}
                                    onPage={(targetPage) => loadSentences(targetPage, perPage)}
                                    onPerPage={(targetPerPage) => loadSentences(1, targetPerPage)}
                                />
                            )}
                        </DndContext>
                    )}
                </section>
            </div>
        </div>
    );
}

Edit.layout = (page) => <Main children={page}/>;
