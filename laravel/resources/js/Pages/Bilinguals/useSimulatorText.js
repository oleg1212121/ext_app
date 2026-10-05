import {useCallback, useEffect, useRef, useState} from 'react';
import {useI18n} from '../../i18n';
import {useSideFlip} from '../../hooks/useSideFlip';
import {loadPositions, writeCurrentText, writePosition} from '../../lib/simulatorPosition';
import {DEFAULT_PER_PAGE, clampPage, fetchTextPage} from '../../lib/simulatorText.mjs';
import {patchWordMap, recordWordEvents, rowWordIds} from '../../lib/wordFamiliarity';
import {sideTexts} from '../../lib/readingRows.mjs';

// Legacy saved rows keyed the reveal halves 'en'/'ru'; map them onto the
// positional target/base halves.
function normalizeSavedRow(saved) {
    return saved?.n
        ? {n: saved.n, target: !!(saved.target ?? saved.en), base: !!(saved.base ?? saved.ru)}
        : null;
}

// The simulator's text engine: which match is open, its loaded pages
// (Reading rows, ADR 0060), the position store, the side flip, and the
// word-familiarity read crediting. Owns textPending alone. The page only
// wires the returned state into the toolbar and the TextContent columns.
export function useSimulatorText({pinnedMatch, textList, fallbackTextId, initialLanguages, initialDefaultSide}) {
    const {t} = useI18n();

    // Position restore: a pinned match wins; otherwise the last text the
    // store knows (only if the picker still lists it); else the picker's
    // default. The saved page + opened row restore on the first load.
    const initialPositions = loadPositions();
    const savedTextExists = initialPositions.currentText != null
        && textList.some((item) => String(item.id) === String(initialPositions.currentText));
    const initialText = pinnedMatch
        ? String(pinnedMatch.id)
        : (savedTextExists ? String(initialPositions.currentText) : String(fallbackTextId ?? ''));
    const initialSaved = pinnedMatch || savedTextExists
        ? (initialPositions.alignments?.[initialText] ?? null)
        : null;

    const [currentText, setCurrentText] = useState(initialText);
    // Which match side is the learning target by default comes from the
    // server's side rule; the per-device flip inverts it (Working state).
    // State, not props: on the picker entry they arrive with each loaded
    // match's POST /text response.
    const [languages, setLanguages] = useState(initialLanguages ?? {a: {code: null, name: null}, b: {code: null, name: null}});
    const [defaultLearningSide, setDefaultLearningSide] = useState(initialDefaultSide === 'b' ? 'b' : 'a');
    // The per-device flip (Side swap, ADR 0037) is shared with the reader:
    // one flip per text, keyed mm:{matchId} in the side-flip store.
    const {firstSide, secondSide, toggleTo} = useSideFlip(defaultLearningSide, currentText ? `mm:${currentText}` : null);
    const learningSide = firstSide;
    const baseSide = secondSide;

    const [rows, setRows] = useState([]);
    const [wordMaps, setWordMaps] = useState(null);
    // Highlight/explain eligibility rides its own key (the reader's
    // sibling shape), not inside the word maps — so the map state stays
    // pure {a, b} and progress updates have nothing to preserve.
    const [highlightable, setHighlightable] = useState({a: false, b: false});
    const [explainable, setExplainable] = useState({a: false, b: false});
    const [allTarget, setAllTarget] = useState(false);
    const [textMeta, setTextMeta] = useState(null);
    const [textPage, setTextPage] = useState(initialSaved?.page ?? 1);
    const [loadError, setLoadError] = useState(null);
    const [textPending, setTextPending] = useState(false);
    const [checkedRows, setCheckedRows] = useState(() => {
        const saved = normalizeSavedRow(initialSaved?.row);
        return saved ? {[saved.n]: {target: saved.target, base: saved.base}} : {};
    });
    const pendingScrollRowRef = useRef(null);
    const initialLoadDoneRef = useRef(false);

    const rowOffset = textMeta
        ? ((textMeta.current_page - 1) * textMeta.per_page)
        : 0;

    // Word maps stay keyed by the match's actual sides; the display columns
    // index into them by the side currently playing each role. Eligibility
    // flags arrive per side from the server (the reader's sibling shape).
    const targetWordMap = wordMaps?.[learningSide] ?? {};
    const baseWordMap = wordMaps?.[baseSide] ?? {};
    const targetHighlightable = !!highlightable[learningSide];
    const baseHighlightable = !!highlightable[baseSide];
    const targetExplainable = !!explainable[learningSide];
    const baseExplainable = !!explainable[baseSide];

    // Apply {wordId: familiarity} results from the familiarity API: recolor
    // every occurrence of the touched words on both sides.
    const applyFamiliarity = useCallback((familiarity) => {
        if (!familiarity || Object.keys(familiarity).length === 0) {
            return;
        }
        setWordMaps((maps) => (maps
            ? {...maps, a: patchWordMap(maps.a, familiarity), b: patchWordMap(maps.b, familiarity)}
            : maps));
    }, []);

    // Word progress changed in a popup: recolor the word on both sides.
    const onWordProgress = useCallback((key, status) => {
        setWordMaps((maps) => {
            if (!maps) {
                return maps;
            }
            const apply = (side) => (maps[side]?.[key] ? {...maps[side], [key]: {...maps[side][key], s: status}} : maps[side]);
            return {...maps, a: apply('a'), b: apply('b')};
        });
    }, []);

    // Revealing a row's target sentence credits its dictionary words a read
    // (+1, deduplicated per sentence pair server-side).
    const creditRead = useCallback((n) => {
        if (!targetHighlightable) {
            return;
        }
        const index = n - 1 - rowOffset;
        const row = rows[index];
        if (!row) {
            return;
        }
        const wordIds = rowWordIds(sideTexts(row, learningSide), targetWordMap);
        if (wordIds.length === 0) {
            return;
        }
        recordWordEvents([{row_key: row.key, kind: 'read', word_ids: wordIds}])
            .then(applyFamiliarity);
    }, [targetHighlightable, targetWordMap, rows, learningSide, rowOffset, applyFamiliarity]);

    // Master target checkbox: reveal the whole column and credit every loaded
    // row's words in one batched request.
    const toggleAllTarget = useCallback((checked) => {
        setAllTarget(checked);
        if (!checked || !targetHighlightable) {
            return;
        }
        const events = [];
        rows.forEach((row) => {
            const wordIds = rowWordIds(sideTexts(row, learningSide), targetWordMap);
            if (wordIds.length > 0) {
                events.push({row_key: row.key, kind: 'read', word_ids: wordIds});
            }
        });
        if (events.length === 0) {
            return;
        }
        recordWordEvents(events).then(applyFamiliarity);
    }, [targetHighlightable, rows, learningSide, targetWordMap, applyFamiliarity]);

    const fetchPage = useCallback(async (page) => {
        if (!currentText) {
            return;
        }
        setLoadError(null);
        setTextPending(true);
        try {
            const {rows: nextRows, wordMaps: nextWordMaps, highlightable: nextHighlightable, explainable: nextExplainable, languages: nextLanguages, defaultLearningSide: nextDefaultSide, meta} = await fetchTextPage(
                currentText,
                page,
                DEFAULT_PER_PAGE,
                (status) => t('bilinguals.request_failed', {status}),
            );
            setRows(nextRows);
            setWordMaps(nextWordMaps);
            setHighlightable(nextHighlightable);
            setExplainable(nextExplainable);
            if (nextLanguages) {
                setLanguages(nextLanguages);
            }
            if (nextDefaultSide) {
                setDefaultLearningSide(nextDefaultSide);
            }
            setAllTarget(false);
            setTextMeta(meta);
            setTextPage(meta.current_page ?? page);
            const positions = writePosition(currentText, {page: meta.current_page ?? page});
            setCheckedRows({});
            const saved = normalizeSavedRow(positions.alignments?.[String(currentText)]?.row);
            const pageStart = ((meta.current_page ?? page) - 1) * (meta.per_page ?? DEFAULT_PER_PAGE);
            if (saved && saved.n > pageStart && saved.n <= pageStart + (meta.per_page ?? DEFAULT_PER_PAGE)) {
                setCheckedRows({[saved.n]: {target: saved.target, base: saved.base}});
                pendingScrollRowRef.current = saved.n;
            }
        } catch (e) {
            setRows([]);
            setWordMaps(null);
            setHighlightable({a: false, b: false});
            setExplainable({a: false, b: false});
            setAllTarget(false);
            setTextMeta(null);
            setLoadError(e instanceof Error ? e.message : t('bilinguals.failed_to_load_text'));
        } finally {
            setTextPending(false);
        }
    }, [currentText, t]);

    useEffect(() => {
        if (rows.length > 0 && pendingScrollRowRef.current !== null) {
            const el = document.getElementById(`simulator-row-${pendingScrollRowRef.current}`);
            el?.scrollIntoView({block: 'center'});
            pendingScrollRowRef.current = null;
        }
    }, [rows]);

    useEffect(() => {
        if (currentText && !initialLoadDoneRef.current) {
            initialLoadDoneRef.current = true;
            pendingScrollRowRef.current = initialSaved?.row?.n ?? null;
            fetchPage(initialSaved?.page ?? 1);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Picker entry: Load fetches the selected match in place; fetchPage
    // restores the saved page and opened row (the flip follows the selected
    // match through the side-flip store).
    const handleLoadText = useCallback(() => {
        const saved = loadPositions().alignments?.[String(currentText)] ?? null;
        const page = saved?.page ?? 1;
        setTextPage(page);
        return fetchPage(page);
    }, [fetchPage, currentText]);

    const changeText = useCallback((event) => {
        const value = event.target.value;
        setCurrentText(value);
        writeCurrentText(value);
    }, []);

    const onToggleRow = useCallback((n, side) => {
        if (side === 'target' && !checkedRows[n]?.target) {
            creditRead(n);
        }
        setCheckedRows((prev) => {
            const rowState = {...(prev[n] ?? {target: false, base: false}), [side]: !(prev[n]?.[side])};
            const next = {...prev, [n]: rowState};
            const open = rowState.target || rowState.base;
            writePosition(currentText, {row: open ? {n, target: rowState.target, base: rowState.base} : null});
            return next;
        });
    }, [checkedRows, creditRead, currentText]);

    const goToPage = useCallback(() => {
        if (!textMeta || textPending) {
            return;
        }
        const parsed = parseInt(String(textPage), 10);
        if (Number.isNaN(parsed)) {
            setTextPage(textMeta.current_page);
            return;
        }
        const clamped = clampPage(parsed, textMeta.last_page);
        setTextPage(clamped);
        if (clamped !== textMeta.current_page) {
            fetchPage(clamped);
        }
    }, [textMeta, textPage, textPending, fetchPage]);

    return {
        // Text identity + sides
        currentText,
        languages,
        learningSide,
        baseSide,
        firstSide,
        secondSide,
        toggleTo,
        // Loaded content
        rows,
        textMeta,
        textPage,
        loadError,
        rowOffset,
        checkedRows,
        allTarget,
        // Projected display maps (post-flip)
        targetWordMap,
        baseWordMap,
        targetHighlightable,
        baseHighlightable,
        targetExplainable,
        baseExplainable,
        // Loading
        textPending,
        // Actions
        changeText,
        handleLoadText,
        fetchPage,
        goToPage,
        setTextPage,
        onToggleRow,
        onWordProgress,
        toggleAllTarget,
    };
}
