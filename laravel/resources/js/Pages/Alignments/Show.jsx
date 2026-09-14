import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {
    DndContext,
    PointerSensor,
    KeyboardSensor,
    pointerWithin,
    rectIntersection,
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
import {useI18n} from '../../i18n';

const ROW_PER_PAGE_OPTIONS = [10, 25, 50, 100];

// Drop-slot droppable ids look like "slot:<containerKey>:#<n>", where n is the
// boundary to drop on: 0 = first position, keys.length = after the last.
const isSlotId = (id) => typeof id === 'string' && id.startsWith('slot:');

function parseSlotIndex(id) {
    const hash = id.lastIndexOf(':#');

    if (hash === -1) {
        return null;
    }

    return Number(id.slice(hash + 2));
}

function buildContainers({rows, unmatchedA, unmatchedB}) {
    const containers = {};

    rows.forEach((row) => {
        containers[`row:${row.id}:a`] = row.a_sentences.map((s) => s.key);
        containers[`row:${row.id}:b`] = row.b_sentences.map((s) => s.key);
    });

    containers['unmatched:a'] = unmatchedA.items.map((s) => s.key);
    containers['unmatched:b'] = unmatchedB.items.map((s) => s.key);

    return containers;
}

function buildLookup({rows, unmatchedA, unmatchedB, sentencesBefore}) {
    const all = [];

    rows.forEach((row) => {
        row.a_sentences.forEach((s) => all.push({...s, side: 'a', row_id: row.id}));
        row.b_sentences.forEach((s) => all.push({...s, side: 'b', row_id: row.id}));
    });

    unmatchedA.items.forEach((s) => all.push({...s, side: 'a', row_id: null}));
    unmatchedB.items.forEach((s) => all.push({...s, side: 'b', row_id: null}));

    const lookup = new Map();
    let aCounter = sentencesBefore?.a ?? 0;
    let bCounter = sentencesBefore?.b ?? 0;

    all
        .sort((a, b) => a.order - b.order)
        .filter((s) => s.side === 'a')
        .forEach((s) => { lookup.set(s.key, {...s, display_order: ++aCounter}); });

    all
        .sort((a, b) => a.order - b.order)
        .filter((s) => s.side === 'b')
        .forEach((s) => { lookup.set(s.key, {...s, display_order: ++bCounter}); });

    return lookup;
}

export default function Show({match: initialMatch, rows: initialRows, rows_meta: initialRowsMeta, sentences_before: initialSentencesBefore, unmatched_a: initialUnmatchedA, unmatched_b: initialUnmatchedB, needs_review: initialNeedsReview}) {
    const {t} = useI18n();
    const [data, setData] = useState(() => ({
        match: initialMatch,
        rows: initialRows,
        rowsMeta: initialRowsMeta,
        sentencesBefore: initialSentencesBefore,
        unmatchedA: initialUnmatchedA,
        unmatchedB: initialUnmatchedB,
        needsReview: initialNeedsReview,
    }));

    const lastServer = useRef(data);
    const [containers, setContainers] = useState(() => buildContainers(data));

    useEffect(() => {
        console.log('[alignments-editor] build v9 — 2026-09-05');
    }, []);

    const [tableBusy, setTableBusy] = useState(false);
    const [tableError, setTableError] = useState(null);
    const [poolBusy, setPoolBusy] = useState({a: false, b: false});
    const [poolError, setPoolError] = useState({a: null, b: null});
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
    // Row id after which a freshly created row must be inserted once the
    // mutation response arrives — resolved at apply time, not dispatch time.
    const newRowAnchor = useRef(null);
    // During a drag the containers are deliberately FROZEN — no onDragOver
    // mutation — so nothing in the layout shifts under the pointer and `over`
    // (a drop slot) can't flip-flop into the React #185 loop. The highlighted
    // slot is the drop feedback. Kept as the last valid collision target so a
    // no-collision frame still reports the item's own slot.
    const lastOverId = useRef(null);

    const lookup = useMemo(() => buildLookup(data), [data]);
    const {match, rows, rowsMeta, sentencesBefore, unmatchedA, unmatchedB, needsReview} = data;

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
            applyData({...lastServer.current, rows: res.rows, rowsMeta: res.meta, sentencesBefore: res.sentences_before, match: res.match});
        } catch (error) {
            setTableError(error.message);
        } finally {
            setTableBusy(false);
        }
    }, [initialMatch.id, applyData]);

    const loadUnmatched = useCallback(async (side, page) => {
        setPoolBusy((prev) => ({...prev, [side]: true}));
        setPoolError((prev) => ({...prev, [side]: null}));

        try {
            let res = await alignmentsApi.unmatched(initialMatch.id, side, page);
            const lastPage = Math.max(res.meta.last_page, 1);
            const finalPage = Math.min(page, lastPage);

            if (finalPage !== page) {
                res = await alignmentsApi.unmatched(initialMatch.id, side, finalPage);
            }

            const key = side === 'a' ? 'unmatchedA' : 'unmatchedB';
            applyData({...lastServer.current, [key]: res});
        } catch (error) {
            setPoolError((prev) => ({...prev, [side]: error.message}));
        } finally {
            setPoolBusy((prev) => ({...prev, [side]: false}));
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
            if (newRowAnchor.current !== null) {
                const anchorIndex = rows.findIndex((existing) => existing.id === newRowAnchor.current);
                let insertIndex = anchorIndex < 0 ? rows.length : anchorIndex + 1;
                incoming.forEach((row) => {
                    if (!rows.some((existing) => existing.id === row.id)) {
                        rows.splice(insertIndex, 0, row);
                        insertIndex++;
                    }
                });
                newRowAnchor.current = null;
            } else {
                incoming.forEach((row) => {
                    if (!rows.some((existing) => existing.id === row.id)) {
                        rows.push(row);
                    }
                });
            }
        }

        const nextRowsMeta = {
            ...base.rowsMeta,
            total: res.match.linked_count ?? base.rowsMeta.total,
            last_page: Math.max(Math.ceil((res.match.linked_count ?? base.rowsMeta.total) / base.rowsMeta.per_page), 1),
        };

        applyData({...base, rows, rowsMeta: nextRowsMeta, match: res.match});

        for (const side of res.unmatched_changed ?? []) {
            const key = side === 'a' ? 'unmatchedA' : 'unmatchedB';
            await loadUnmatched(side, lastServer.current[key].meta.current_page);
        }

        await loadNeedsReview(lastServer.current.needsReview.meta.current_page);
    }, [applyData, loadUnmatched, loadNeedsReview]);

    const runMutation = useCallback(async (request, insertAfterRowId = null) => {
        setActionBusy(true);
        setActionError(null);
        newRowAnchor.current = insertAfterRowId;

        try {
            const res = await request();
            await applyMutation(res);
        } catch (error) {
            newRowAnchor.current = null;
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
            a_sentences: row.a_sentences.map((s) => (s.key === key ? mutate(s) : s)),
            b_sentences: row.b_sentences.map((s) => (s.key === key ? mutate(s) : s)),
        })),
        unmatchedA: {...prev.unmatchedA, items: prev.unmatchedA.items.map((s) => (s.key === key ? mutate(s) : s))},
        unmatchedB: {...prev.unmatchedB, items: prev.unmatchedB.items.map((s) => (s.key === key ? mutate(s) : s))},
    }), []);

    const onAddStart = useCallback((row, side) => {
        if (actionBusy) {
            return;
        }

        setEditing(null);
        setAdding({rowId: row.id, side});
        setAddDraft('');
    }, [actionBusy]);

    const onAddChange = useCallback((value) => setAddDraft(value), []);
    const onAddCancel = useCallback(() => {
        setAdding(null);
        setAddDraft('');
    }, []);

    const onAddCommit = useCallback(async (side) => {
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

                const field = side === 'a' ? 'a_sentences' : 'b_sentences';

                return {...row, [field]: [...row[field], tmpSentence]};
            }),
        }));

        setContainers((prev) => {
            const key = `row:${rowId}:${side}`;

            return {...prev, [key]: [...(prev[key] ?? []), tmpKey]};
        });

        setAdding(null);
        setAddDraft('');

        await runMutation(() => alignmentsApi.addSentence(initialMatch.id, {side, meaning_match_id: rowId, content}));
    }, [addDraft, adding, initialMatch.id, runMutation]);

    const onStartEdit = useCallback((key, side) => {
        if (actionBusy) {
            return;
        }

        const item = lookup.get(key);

        if (!item) {
            return;
        }

        setAdding(null);
        setEditing({key, side, draft: item.content});
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
            () => alignmentsApi.updateSentence(initialMatch.id, item.id, {side: editing.side, content}),
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
                a_sentences: row.a_sentences.filter((s) => s.key !== item.key),
                b_sentences: row.b_sentences.filter((s) => s.key !== item.key),
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
            () => alignmentsApi.unlinkSentence(initialMatch.id, item.id, item.side),
        );
    }, [actionBusy, initialMatch.id, runMutation]);

    const onRemove = useCallback(async (item) => {
        if (actionBusy) {
            return;
        }

        setEditing(null);
        setData((prev) => {
            const key = item.side === 'a' ? 'unmatchedA' : 'unmatchedB';

            return {...prev, [key]: {...prev[key], items: prev[key].items.filter((s) => s.key !== item.key)}};
        });

        setContainers((prev) => {
            const containerKey = `unmatched:${item.side}`;

            return {...prev, [containerKey]: (prev[containerKey] ?? []).filter((id) => id !== item.key)};
        });

        await runMutation(
            () => alignmentsApi.destroyUnmatched(initialMatch.id, item.id, item.side),
        );
    }, [actionBusy, initialMatch.id, runMutation]);

    const onCreateBelow = useCallback(async (row) => {
        if (actionBusy) {
            return;
        }

        setEditing(null);
        const tmpId = `tmp-${Date.now()}`;
        const tmpRow = {key: `mm-${tmpId}`, id: tmpId, order: row.order + 0.5, similarity: null, a_sentences: [], b_sentences: []};

        setData((prev) => {
            const index = prev.rows.findIndex((existing) => existing.id === row.id);
            const rows = [...prev.rows];
            rows.splice(index + 1, 0, tmpRow);

            return {...prev, rows};
        });

        setContainers((prev) => ({
            ...prev,
            [`row:${tmpId}:a`]: [],
            [`row:${tmpId}:b`]: [],
        }));

        await runMutation(() => alignmentsApi.createRow(initialMatch.id, row.id), row.id);
    }, [actionBusy, initialMatch.id, runMutation]);

    const onDeleteRow = useCallback(async (row) => {
        if (actionBusy) {
            return;
        }

        setEditing(null);
        setData((prev) => ({...prev, rows: prev.rows.filter((existing) => existing.id !== row.id)}));

        setContainers((prev) => {
            const next = {...prev};
            delete next[`row:${row.id}:a`];
            delete next[`row:${row.id}:b`];

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
        if (typeof id === 'string' && isSlotId(id)) {
            const hash = id.lastIndexOf(':#');

            return hash === -1 ? null : id.slice(5, hash);
        }

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

    // Collisions come from the visible drop slots (`slot:<containerKey>:#<n>`)
    // plus the sentence items (the keyboard path uses item targets). The
    // container lists stay frozen while dragging, so `over` is stable for a
    // still pointer and the highlight tracks the exact drop spot.
    const collisionDetection = useCallback((args) => {
        let collisions = pointerWithin(args);

        if (collisions.length === 0) {
            collisions = rectIntersection(args);
        }

        const overId = collisions.length > 0 ? collisions[0].id : null;

        if (overId !== null) {
            lastOverId.current = overId;

            return [{id: overId}];
        }

        return lastOverId.current !== null ? [{id: lastOverId.current}] : [];
    }, []);

    const sensors = useSensors(
        useSensor(PointerSensor, {activationConstraint: {distance: 6}}),
        useSensor(KeyboardSensor, {coordinateGetter: sortableKeyboardCoordinates}),
    );

    const onDragStart = useCallback(({active}) => {
        setActiveId(active.id);
        setEditing(null);
        setAdding(null);
        activeContainer.current = containerOf(active.id);
        lastOverId.current = null;
    }, [containerOf]);

    const onDragEnd = useCallback(async ({active, over}) => {
        const overId = over?.id;
        const item = lookup.get(active.id);
        const targetContainer = overId ? containerOf(overId) : null;

        setActiveId(null);
        activeContainer.current = null;
        lastOverId.current = null;

        if (!overId || !targetContainer || !item || typeof item.id !== 'number') {
            setContainers(buildContainers(lastServer.current));

            return;
        }

        if (overId === active.id) {
            // Dropped back onto the sentence itself — nothing changed.
            return;
        }

        const targetItems = containers[targetContainer] ?? [];
        const others = targetItems.filter((id) => id !== active.id);
        let index;

        if (isSlotId(overId)) {
            // Drop slot: the boundary IS the insertion index among the
            // column's other sentences (slot #0 = first, #n = after the n-th
            // other sentence = the real last position).
            const slotN = parseSlotIndex(overId) ?? 0;
            index = Math.min(slotN, others.length);
        } else if (overId === targetContainer) {
            // Safety fallback: hovered the column itself → first position.
            index = 0;
        } else {
            // Keyboard path: `over` is the sentence under the pointer.
            const overIndex = targetItems.indexOf(overId);
            const activeIndex = targetItems.indexOf(active.id);

            if (overIndex === -1) {
                index = Math.max(activeIndex, 0);
            } else if (activeIndex !== -1 && activeIndex < overIndex) {
                index = Math.max(overIndex - 1, 0);
            } else {
                index = overIndex;
            }
        }

        const reordered = [...others.slice(0, index), active.id, ...others.slice(index)];

        setContainers((prev) => {
            const source = containerOf(active.id);
            const next = {...prev, [targetContainer]: reordered};

            if (source && source !== targetContainer) {
                next[source] = (next[source] ?? []).filter((id) => id !== active.id);
            }

            return next;
        });

        // Keyboard path may point past the last item; clamp for the API.
        index = Math.min(index, others.length);

        const toRowId = targetContainer.startsWith('row:') ? Number(targetContainer.split(':')[1]) : null;

        await runMutation(
            () => alignmentsApi.moveSentence(initialMatch.id, {
                side: item.side,
                sentence_id: item.id,
                to_row_id: toRowId,
                index,
            }),
        );
    }, [containerOf, containers, initialMatch.id, lookup, runMutation]);

    const sideLabels = {
        a: (match.a_language_code || 'a').toUpperCase(),
        b: (match.b_language_code || 'b').toUpperCase(),
    };

    const anyBusy = tableBusy || actionBusy || poolBusy.a || poolBusy.b;

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={collisionDetection}
            onDragStart={onDragStart}
            onDragEnd={onDragEnd}
            onDragCancel={() => {
                setActiveId(null);
                activeContainer.current = null;
                lastOverId.current = null;
                setContainers(buildContainers(lastServer.current));
            }}
        >
            <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
                <div className="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                    <header className="flex flex-wrap items-end justify-between gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                        <div>
                            <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                {t('alignments.title')}
                            </p>
                            <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                {match.a_entity_name || 'A'} ↔ {match.b_entity_name || 'B'}
                            </h1>
                            <p className="mt-1 font-mono text-[10px] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                {t('alignments.sim')} {match.entity_similarity !== null ? Number(match.entity_similarity).toFixed(4) : '—'} · {match.status} · {t('alignments.linked_confirmed', {linked: match.linked_count, confirmed: match.confirmed_count})}
                                {match.work_title ? ` · ${match.work_title}` : ''}
                            </p>
                        </div>
                        <p className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {(match.a_language_code || 'a').toUpperCase()} {match.a_total_sentences} · {(match.b_language_code || 'b').toUpperCase()} {match.b_total_sentences}
                        </p>
                    </header>

                    {actionError && (
                        <p className="border border-[var(--wbench-danger)]/40 dark:border-[var(--wbench-danger-night)]/40 px-3 py-2 font-mono text-[11px] text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)]">
                            {actionError} {t('alignments.changes_reverted')}
                        </p>
                    )}

                    <div className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                        {tableError && (
                            <div className="px-3 py-8 text-center">
                                <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                    {t('alignments.load_error')}
                                </p>
                                <p className="mt-1 font-mono text-[11px] text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)]">
                                    {tableError}
                                </p>
                            </div>
                        )}

                        {!tableError && rows.length === 0 && (
                            <div className="px-3 py-12 text-center">
                                <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                    {t('alignments.no_pairs_yet')}
                                </p>
                                <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                    {t('alignments.no_pairs_hint')}
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
                                        aKeys={containers[`row:${row.id}:a`] ?? []}
                                        sideLabels={sideLabels}
                                        bKeys={containers[`row:${row.id}:b`] ?? []}
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
                        aKeys={containers['unmatched:a'] ?? []}
                        sideLabels={sideLabels}
                        bKeys={containers['unmatched:b'] ?? []}
                        lookup={lookup}
                        unmatchedA={unmatchedA}
                        unmatchedB={unmatchedB}
                        busy={actionBusy}
                        editing={editing}
                        onStartEdit={onStartEdit}
                        onEditChange={onEditChange}
                        onCommitEdit={onCommitEdit}
                        onCancelEdit={onCancelEdit}
                        onRemove={onRemove}
                        onPageChange={(side, page) => loadUnmatched(side, page)}
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
