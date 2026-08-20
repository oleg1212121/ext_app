import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {
    DndContext,
    PointerSensor,
    KeyboardSensor,
    closestCorners,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import {sortableKeyboardCoordinates} from '@dnd-kit/sortable';
import PairRow from './components/PairRow.jsx';
import UnmatchedSection from './components/UnmatchedSection.jsx';
import NeedsReviewSection from './components/NeedsReviewSection.jsx';
import Pagination from './components/Pagination.jsx';
import {alignmentsApi} from './components/api.js';
import Main from '../../Layouts/Main.jsx';

const ROW_PER_PAGE_OPTIONS = [10, 25, 50, 100];

function buildContainers({rows, unmatchedEn, unmatchedRu}) {
    const containers = {};

    rows.forEach((row) => {
        containers[`row:${row.id}:en`] = row.en_sentences.map((s) => s.key);
        containers[`row:${row.id}:ru`] = row.ru_sentences.map((s) => s.key);
    });

    containers['unmatched:en'] = unmatchedEn.items.map((s) => s.key);
    containers['unmatched:ru'] = unmatchedRu.items.map((s) => s.key);

    return containers;
}

function buildLookup({rows, unmatchedEn, unmatchedRu}) {
    const all = [];

    rows.forEach((row) => {
        row.en_sentences.forEach((s) => all.push({...s, lang: 'en', row_id: row.id}));
        row.ru_sentences.forEach((s) => all.push({...s, lang: 'ru', row_id: row.id}));
    });

    unmatchedEn.items.forEach((s) => all.push({...s, lang: 'en', row_id: null}));
    unmatchedRu.items.forEach((s) => all.push({...s, lang: 'ru', row_id: null}));

    const lookup = new Map();
    let enCounter = 0;
    let ruCounter = 0;

    all
        .sort((a, b) => a.order - b.order)
        .filter((s) => s.lang === 'en')
        .forEach((s) => { lookup.set(s.key, {...s, display_order: ++enCounter}); });

    all
        .sort((a, b) => a.order - b.order)
        .filter((s) => s.lang === 'ru')
        .forEach((s) => { lookup.set(s.key, {...s, display_order: ++ruCounter}); });

    return lookup;
}

export default function Show({match: initialMatch, rows: initialRows, rows_meta: initialRowsMeta, unmatched_en: initialUnmatchedEn, unmatched_ru: initialUnmatchedRu, needs_review: initialNeedsReview}) {
    const [data, setData] = useState(() => ({
        match: initialMatch,
        rows: initialRows,
        rowsMeta: initialRowsMeta,
        unmatchedEn: initialUnmatchedEn,
        unmatchedRu: initialUnmatchedRu,
        needsReview: initialNeedsReview,
    }));

    const lastServer = useRef(data);
    const [containers, setContainers] = useState(() => buildContainers(data));

    const [tableBusy, setTableBusy] = useState(false);
    const [tableError, setTableError] = useState(null);
    const [poolBusy, setPoolBusy] = useState({en: false, ru: false});
    const [poolError, setPoolError] = useState({en: null, ru: null});
    const [actionBusy, setActionBusy] = useState(false);
    const [actionError, setActionError] = useState(null);
    const [needsReviewBusy, setNeedsReviewBusy] = useState(false);
    const [needsReviewOpen, setNeedsReviewOpen] = useState(false);
    const [highlightedRowId, setHighlightedRowId] = useState(null);

    const [editing, setEditing] = useState(null);
    const [adding, setAdding] = useState(null);
    const [addDraft, setAddDraft] = useState('');
    const [unmatchedOpen, setUnmatchedOpen] = useState(false);
    const [activeId, setActiveId] = useState(null);

    const activeContainer = useRef(null);

    const lookup = useMemo(() => buildLookup(data), [data]);
    const {match, rows, rowsMeta, unmatchedEn, unmatchedRu, needsReview} = data;

    const applyData = useCallback((next) => {
        setData(next);
        setContainers(buildContainers(next));
        lastServer.current = next;
    }, []);

    const loadRows = useCallback(async (page, perPage) => {
        setTableBusy(true);
        setTableError(null);

        try {
            const res = await alignmentsApi.rows(initialMatch.id, page, perPage);
            applyData({...lastServer.current, rows: res.rows, rowsMeta: res.meta, match: res.match});
        } catch (error) {
            setTableError(error.message);
        } finally {
            setTableBusy(false);
        }
    }, [initialMatch.id, applyData]);

    const loadUnmatched = useCallback(async (lang, page) => {
        setPoolBusy((prev) => ({...prev, [lang]: true}));
        setPoolError((prev) => ({...prev, [lang]: null}));

        try {
            let res = await alignmentsApi.unmatched(initialMatch.id, lang, page);
            const lastPage = Math.max(res.meta.last_page, 1);
            const finalPage = Math.min(page, lastPage);

            if (finalPage !== page) {
                res = await alignmentsApi.unmatched(initialMatch.id, lang, finalPage);
            }

            const key = lang === 'en' ? 'unmatchedEn' : 'unmatchedRu';
            applyData({...lastServer.current, [key]: res});
        } catch (error) {
            setPoolError((prev) => ({...prev, [lang]: error.message}));
        } finally {
            setPoolBusy((prev) => ({...prev, [lang]: false}));
        }
    }, [initialMatch.id, applyData]);

    const loadNeedsReview = useCallback(async (page) => {
        setNeedsReviewBusy(true);

        try {
            const res = await alignmentsApi.needsReview(initialMatch.id, page);
            applyData({...lastServer.current, needsReview: res});
        } catch {
            // keep the last list on failure
        } finally {
            setNeedsReviewBusy(false);
        }
    }, [initialMatch.id, applyData]);

    useEffect(() => {
        if (highlightedRowId === null) {
            return;
        }

        const element = document.querySelector(`[data-row-id="${highlightedRowId}"]`);

        if (element) {
            element.scrollIntoView({block: 'center', behavior: 'smooth'});
        }
    }, [highlightedRowId, rows]);

    const jumpToRow = useCallback(async (item, page) => {
        if (tableBusy || actionBusy) {
            return;
        }

        const {current_page, per_page} = lastServer.current.rowsMeta;

        if (page !== current_page) {
            await loadRows(page, per_page);
        }

        setHighlightedRowId(item.id);
        window.setTimeout(() => setHighlightedRowId((prev) => (prev === item.id ? null : prev)), 2500);
    }, [tableBusy, actionBusy, loadRows]);

    const applyMutation = useCallback(async (res) => {
        const base = lastServer.current;
        let rows = base.rows;

        if (res.deleted_rows?.length) {
            rows = rows.filter((row) => !res.deleted_rows.includes(row.id));
        }

        if (res.rows?.length) {
            const incoming = res.rows;
            rows = rows.filter((row) => typeof row.id === 'number');
            rows = rows.map((row) => incoming.find((next) => next.id === row.id) ?? row);
            incoming.forEach((row) => {
                if (!rows.some((existing) => existing.id === row.id)) {
                    rows.push(row);
                }
            });
        }

        const nextRowsMeta = {
            ...base.rowsMeta,
            total: res.match.linked_count ?? base.rowsMeta.total,
            last_page: Math.max(Math.ceil((res.match.linked_count ?? base.rowsMeta.total) / base.rowsMeta.per_page), 1),
        };

        applyData({...base, rows, rowsMeta: nextRowsMeta, match: res.match});

        for (const lang of res.unmatched_changed ?? []) {
            const key = lang === 'en' ? 'unmatchedEn' : 'unmatchedRu';
            await loadUnmatched(lang, lastServer.current[key].meta.current_page);
        }

        await loadNeedsReview(lastServer.current.needsReview.meta.current_page);
    }, [applyData, loadUnmatched, loadNeedsReview]);

    const runMutation = useCallback(async (request) => {
        setActionBusy(true);
        setActionError(null);

        try {
            const res = await request();
            await applyMutation(res);
        } catch (error) {
            applyData(lastServer.current);
            setActionError(error.message);
        } finally {
            setActionBusy(false);
        }
    }, [applyData, applyMutation]);

    const withRowSentence = useCallback((prev, key, mutate) => ({
        ...prev,
        rows: prev.rows.map((row) => ({
            ...row,
            en_sentences: row.en_sentences.map((s) => (s.key === key ? mutate(s) : s)),
            ru_sentences: row.ru_sentences.map((s) => (s.key === key ? mutate(s) : s)),
        })),
        unmatchedEn: {...prev.unmatchedEn, items: prev.unmatchedEn.items.map((s) => (s.key === key ? mutate(s) : s))},
        unmatchedRu: {...prev.unmatchedRu, items: prev.unmatchedRu.items.map((s) => (s.key === key ? mutate(s) : s))},
    }), []);

    const onAddStart = useCallback((row, lang) => {
        if (actionBusy) {
            return;
        }

        setEditing(null);
        setAdding({rowId: row.id, lang});
        setAddDraft('');
    }, [actionBusy]);

    const onAddChange = useCallback((value) => setAddDraft(value), []);
    const onAddCancel = useCallback(() => {
        setAdding(null);
        setAddDraft('');
    }, []);

    const onAddCommit = useCallback(async (lang) => {
        const content = addDraft.trim();

        if (!content || !adding) {
            return;
        }

        const rowId = adding.rowId;
        const tmpKey = `tmp-${Date.now()}`;
        const tmpSentence = {key: tmpKey, id: null, content, order: null, pending: true};

        setData((prev) => ({
            ...prev,
            rows: prev.rows.map((row) => {
                if (row.id !== rowId) {
                    return row;
                }

                const field = lang === 'en' ? 'en_sentences' : 'ru_sentences';

                return {...row, [field]: [...row[field], tmpSentence]};
            }),
        }));

        setContainers((prev) => {
            const key = `row:${rowId}:${lang}`;

            return {...prev, [key]: [...(prev[key] ?? []), tmpKey]};
        });

        setAdding(null);
        setAddDraft('');

        await runMutation(() => alignmentsApi.addSentence(initialMatch.id, {lang, meaning_match_id: rowId, content}));
    }, [addDraft, adding, initialMatch.id, runMutation]);

    const onStartEdit = useCallback((key, lang) => {
        if (actionBusy) {
            return;
        }

        const item = lookup.get(key);

        if (!item) {
            return;
        }

        setAdding(null);
        setEditing({key, lang, draft: item.content});
    }, [actionBusy, lookup]);

    const onEditChange = useCallback((value) => {
        setEditing((prev) => (prev ? {...prev, draft: value} : prev));
    }, []);

    const onCancelEdit = useCallback(() => setEditing(null), []);

    const onCommitEdit = useCallback(async () => {
        if (!editing) {
            return;
        }

        const content = editing.draft.trim();

        if (!content) {
            return;
        }

        const item = lookup.get(editing.key);

        if (!item || typeof item.id !== 'number') {
            return;
        }

        setData((prev) => withRowSentence(prev, editing.key, (s) => ({...s, content})));
        setEditing(null);

        await runMutation(
            () => alignmentsApi.updateSentence(initialMatch.id, item.id, {lang: editing.lang, content}),
        );
    }, [editing, lookup, initialMatch.id, runMutation, withRowSentence]);

    const onUnlink = useCallback(async (item) => {
        if (actionBusy) {
            return;
        }

        setEditing(null);
        setData((prev) => ({
            ...prev,
            rows: prev.rows.map((row) => ({
                ...row,
                en_sentences: row.en_sentences.filter((s) => s.key !== item.key),
                ru_sentences: row.ru_sentences.filter((s) => s.key !== item.key),
            })),
        }));

        setContainers((prev) => {
            const next = {};
            for (const [key, items] of Object.entries(prev)) {
                next[key] = items.filter((id) => id !== item.key);
            }

            return next;
        });

        await runMutation(
            () => alignmentsApi.unlinkSentence(initialMatch.id, item.id, item.lang),
        );
    }, [actionBusy, initialMatch.id, runMutation]);

    const onRemove = useCallback(async (item) => {
        if (actionBusy) {
            return;
        }

        setEditing(null);
        setData((prev) => {
            const key = item.lang === 'en' ? 'unmatchedEn' : 'unmatchedRu';

            return {...prev, [key]: {...prev[key], items: prev[key].items.filter((s) => s.key !== item.key)}};
        });

        setContainers((prev) => {
            const containerKey = `unmatched:${item.lang}`;

            return {...prev, [containerKey]: (prev[containerKey] ?? []).filter((id) => id !== item.key)};
        });

        await runMutation(
            () => alignmentsApi.destroyUnmatched(initialMatch.id, item.id, item.lang),
        );
    }, [actionBusy, initialMatch.id, runMutation]);

    const onCreateBelow = useCallback(async (row) => {
        if (actionBusy) {
            return;
        }

        setEditing(null);
        const tmpId = `tmp-${Date.now()}`;
        const tmpRow = {key: `mm-${tmpId}`, id: tmpId, order: row.order + 0.5, similarity: null, en_sentences: [], ru_sentences: []};

        setData((prev) => {
            const index = prev.rows.findIndex((existing) => existing.id === row.id);
            const rows = [...prev.rows];
            rows.splice(index + 1, 0, tmpRow);

            return {...prev, rows};
        });

        setContainers((prev) => ({
            ...prev,
            [`row:${tmpId}:en`]: [],
            [`row:${tmpId}:ru`]: [],
        }));

        await runMutation(() => alignmentsApi.createRow(initialMatch.id, row.id));
    }, [actionBusy, initialMatch.id, runMutation]);

    const onDeleteRow = useCallback(async (row) => {
        if (actionBusy) {
            return;
        }

        setEditing(null);
        setData((prev) => ({...prev, rows: prev.rows.filter((existing) => existing.id !== row.id)}));

        setContainers((prev) => {
            const next = {...prev};
            delete next[`row:${row.id}:en`];
            delete next[`row:${row.id}:ru`];

            return next;
        });

        await runMutation(() => alignmentsApi.deleteRow(initialMatch.id, row.id));
    }, [actionBusy, initialMatch.id, runMutation]);

    const onApprove = useCallback(async (row) => {
        if (actionBusy) {
            return;
        }

        setEditing(null);
        setData((prev) => ({
            ...prev,
            rows: prev.rows.map((existing) => (existing.id === row.id ? {...existing, similarity: 1} : existing)),
        }));

        await runMutation(() => alignmentsApi.approveRow(initialMatch.id, row.id));
    }, [actionBusy, initialMatch.id, runMutation]);

    const containerOf = useCallback((id) => {
        if (typeof id === 'string' && id.startsWith('row:')) {
            return id;
        }

        if (typeof id === 'string' && id.startsWith('unmatched:')) {
            return id;
        }

        for (const [containerKey, items] of Object.entries(containers)) {
            if (items.includes(id)) {
                return containerKey;
            }
        }

        return null;
    }, [containers]);

    const sensors = useSensors(
        useSensor(PointerSensor, {activationConstraint: {distance: 6}}),
        useSensor(KeyboardSensor, {coordinateGetter: sortableKeyboardCoordinates}),
    );

    const onDragStart = useCallback(({active}) => {
        setActiveId(active.id);
        setEditing(null);
        setAdding(null);
        activeContainer.current = containerOf(active.id);
    }, [containerOf]);

    const onDragOver = useCallback(({active, over}) => {
        const overId = over?.id;

        if (!overId || active.id === overId) {
            return;
        }

        const activeContainerKey = containerOf(active.id);
        const overContainerKey = containerOf(overId);

        if (!activeContainerKey || !overContainerKey || activeContainerKey === overContainerKey) {
            return;
        }

        setContainers((prev) => {
            const activeItems = prev[activeContainerKey] ?? [];
            const overItems = prev[overContainerKey] ?? [];

            let overIndex;

            if (activeContainerKey.startsWith('row:') && overContainerKey.startsWith('row:')) {
                const srcRowId = Number(activeContainerKey.split(':')[1]);
                const tgtRowId = Number(overContainerKey.split(':')[1]);
                const srcRow = rows.find((r) => r.id === srcRowId);
                const tgtRow = rows.find((r) => r.id === tgtRowId);

                if (srcRow && tgtRow) {
                    overIndex = srcRow.order < tgtRow.order ? 0 : overItems.length;
                } else {
                    overIndex = overId === overContainerKey ? overItems.length : Math.max(overItems.indexOf(overId), 0);
                }
            } else {
                overIndex = overId === overContainerKey ? overItems.length : Math.max(overItems.indexOf(overId), 0);
            }

            return {
                ...prev,
                [activeContainerKey]: activeItems.filter((id) => id !== active.id),
                [overContainerKey]: [...overItems.slice(0, overIndex), active.id, ...overItems.slice(overIndex)],
            };
        });
    }, [containerOf, rows]);

    const onDragEnd = useCallback(async ({active, over}) => {
        const overId = over?.id;
        const item = lookup.get(active.id);
        const sourceContainer = activeContainer.current;
        const targetContainer = overId ? containerOf(active.id) : null;

        setActiveId(null);
        activeContainer.current = null;

        if (!overId || !targetContainer || !item || typeof item.id !== 'number') {
            setContainers(buildContainers(lastServer.current));

            return;
        }

        if (sourceContainer === targetContainer) {
            const items = containers[targetContainer] ?? [];
            const oldIndex = items.indexOf(active.id);
            const newIndex = overId === targetContainer ? items.length : Math.max(items.indexOf(overId), 0);

            if (oldIndex !== -1 && oldIndex !== newIndex) {
                const reordered = [...items];
                reordered.splice(oldIndex, 1);
                reordered.splice(newIndex, 0, active.id);
                setContainers((prev) => ({...prev, [targetContainer]: reordered}));
            }
        }

        const targetItems = containers[targetContainer] ?? [];
        const toRowId = targetContainer.startsWith('row:') ? Number(targetContainer.split(':')[1]) : null;
        const index = sourceContainer === targetContainer
            ? (overId === targetContainer ? targetItems.length : Math.max(targetItems.indexOf(overId), 0))
            : Math.max(targetItems.indexOf(active.id), 0);

        await runMutation(
            () => alignmentsApi.moveSentence(initialMatch.id, {
                lang: item.lang,
                sentence_id: item.id,
                to_row_id: toRowId,
                index,
            }),
        );
    }, [containerOf, containers, initialMatch.id, lookup, runMutation]);

    const anyBusy = tableBusy || actionBusy || poolBusy.en || poolBusy.ru;

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCorners}
            onDragStart={onDragStart}
            onDragOver={onDragOver}
            onDragEnd={onDragEnd}
            onDragCancel={() => {
                setActiveId(null);
                activeContainer.current = null;
                setContainers(buildContainers(lastServer.current));
            }}
        >
            <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
                <div className="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                    <header className="flex flex-wrap items-end justify-between gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                        <div>
                            <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                Alignments
                            </p>
                            <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                {match.en_entity_name || 'EN'} ↔ {match.ru_entity_name || 'RU'}
                            </h1>
                            <p className="mt-1 font-mono text-[10px] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                sim {match.entity_similarity !== null ? Number(match.entity_similarity).toFixed(4) : '—'} · {match.status} · {match.linked_count} linked / {match.confirmed_count} confirmed
                            </p>
                        </div>
                        <p className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            EN {match.en_total_sentences} · RU {match.ru_total_sentences}
                        </p>
                    </header>

                    {actionError && (
                        <p className="border border-[var(--wbench-danger)]/40 dark:border-[var(--wbench-danger-night)]/40 px-3 py-2 font-mono text-[11px] text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)]">
                            {actionError} — changes reverted
                        </p>
                    )}

                    <div className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                        {tableError && (
                            <div className="px-3 py-8 text-center">
                                <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                    Could not load pairs.
                                </p>
                                <p className="mt-1 font-mono text-[11px] text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)]">
                                    {tableError}
                                </p>
                            </div>
                        )}

                        {!tableError && rows.length === 0 && (
                            <div className="px-3 py-12 text-center">
                                <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                    No pairs yet.
                                </p>
                                <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                    Run the alignment pipeline or drag sentences from Unmatched into a new pair.
                                </p>
                            </div>
                        )}

                        {!tableError && rows.length > 0 && (
                            <>
                                {rows.map((row, index) => (
                                    <PairRow
                                        key={row.key}
                                        row={row}
                                        position={(rowsMeta.current_page - 1) * rowsMeta.per_page + index + 1}
                                        enKeys={containers[`row:${row.id}:en`] ?? []}
                                        ruKeys={containers[`row:${row.id}:ru`] ?? []}
                                        lookup={lookup}
                                        editing={editing}
                                        adding={adding}
                                        draft={addDraft}
                                        busy={actionBusy}
                                        highlighted={highlightedRowId === row.id}
                                        onAddStart={onAddStart}
                                        onAddChange={onAddChange}
                                        onAddCommit={onAddCommit}
                                        onAddCancel={onAddCancel}
                                        onStartEdit={onStartEdit}
                                        onEditChange={onEditChange}
                                        onCommitEdit={onCommitEdit}
                                        onCancelEdit={onCancelEdit}
                                        onUnlink={onUnlink}
                                        onCreateBelow={onCreateBelow}
                                        onDelete={onDeleteRow}
                                        onApprove={onApprove}
                                    />
                                ))}

                                <Pagination
                                    meta={rowsMeta}
                                    perPageOptions={ROW_PER_PAGE_OPTIONS}
                                    busy={tableBusy}
                                    onPage={(page) => loadRows(page, rowsMeta.per_page)}
                                    onPerPage={(perPage) => loadRows(1, perPage)}
                                />
                            </>
                        )}
                    </div>

                    <UnmatchedSection
                        expanded={unmatchedOpen}
                        onToggle={() => setUnmatchedOpen((prev) => !prev)}
                        enKeys={containers['unmatched:en'] ?? []}
                        ruKeys={containers['unmatched:ru'] ?? []}
                        lookup={lookup}
                        unmatchedEn={unmatchedEn}
                        unmatchedRu={unmatchedRu}
                        busy={actionBusy}
                        editing={editing}
                        onStartEdit={onStartEdit}
                        onEditChange={onEditChange}
                        onCommitEdit={onCommitEdit}
                        onCancelEdit={onCancelEdit}
                        onRemove={onRemove}
                        onPageChange={(lang, page) => loadUnmatched(lang, page)}
                    />

                    <NeedsReviewSection
                        expanded={needsReviewOpen}
                        onToggle={() => setNeedsReviewOpen((prev) => !prev)}
                        items={needsReview.items}
                        meta={needsReview.meta}
                        busy={needsReviewBusy}
                        rowsPerPage={rowsMeta.per_page}
                        onPageChange={(page) => loadNeedsReview(page)}
                        onRowClick={jumpToRow}
                    />
                </div>
            </div>
        </DndContext>
    );
}

Show.layout = (page) => <Main children={page}/>;
