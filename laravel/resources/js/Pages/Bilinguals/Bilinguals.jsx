import React from 'react';
import { Head } from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx'
import Spinner from '../../Components/Spinner.jsx'
import Select from "../../Components/Forms/Select.jsx";
import Button from "../../Components/Forms/Button.jsx";
import Workplace from "./Components/Workplace.jsx";
import AI from "./Components/AI.jsx";
import TextContent from "./Components/TextContent.jsx";
import {Icon} from "../../Components/icons.jsx";
import {popupFontSizeFor} from "../../Components/WordPopup.jsx";
import {t, useI18n} from '../../i18n';
import {getCsrfToken} from '../../lib/http';
import {loadPositions, savePositions} from '../../lib/simulatorPosition';
import {useUiSettingsAutosave} from '../../hooks/useUiSettingsAutosave';
import {useSideFlip} from '../../hooks/useSideFlip';
import {patchWordMap, recordWordEvents, rowWordIds} from '../../lib/wordFamiliarity';
import {rowsHaveAnnotation, sideTexts} from '../../lib/readingRows.mjs';
import {renderMarkdown} from '../../lib/markdown';

const DEFAULT_PER_PAGE = 50;
const DEFAULT_FONT_SIZE = 26;
const CONTROL_FONT_SCALE = 0.62;
const FONT_SIZE_STEP = 2;
const MIN_FONT_SIZE = 12;
const MAX_FONT_SIZE = 48;

const HAIRLINE = 'h-5 w-px bg-[var(--wbench-rule)] dark:bg-[var(--wbench-rule-night)]';

function panelToggleIconClass(active) {
    return `h-4 w-4 shrink-0 transition-colors ${active ? 'text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]' : 'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]'}`;
}

// The stress-marks toggle stays on persistently (autosaved preference), so
// an accent fill would read as a plain accent-colored icon — it keeps grey
// line-art always and the accent underline alone carries the on-state.
const pronunciationIconClass = panelToggleIconClass(false);

const tabClass = (isActive) => [
    'relative inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium tracking-wide transition-colors duration-200 rounded-sm',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]',
    isActive
        ? 'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]'
        : 'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]',
].join(' ');

const Underline = ({isActive}) => (
    <span
        aria-hidden="true"
        className={[
            'absolute left-1 right-1 -bottom-px h-[2px] bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)]',
            'transition-transform duration-300 origin-left',
            isActive ? 'scale-x-100' : 'scale-x-0',
        ].join(' ')}
        style={{transformOrigin: 'left center'}}
    />
);

const FontButton = ({onClick, label, children}) => (
    <button
        type="button"
        aria-label={label}
        onClick={onClick}
        className="h-7 w-7 flex items-center justify-center border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-deep-night)] text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)] hover:border-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] rounded-sm transition-colors duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
    >
        <span className="font-[var(--wbench-mono)] text-sm leading-none">{children}</span>
    </button>
);

function updateResizeableFontStyles(fontSize) {
    let styleElement = document.getElementById('resizeable-font-styles');
    if (!styleElement) {
        styleElement = document.createElement('style');
        styleElement.id = 'resizeable-font-styles';
        document.head.appendChild(styleElement);
    }
    const controlFontSize = Math.round(fontSize * CONTROL_FONT_SCALE);

    styleElement.textContent = `
        .target.resizeable_element,
        .base.resizeable_element,
        textarea.resizeable_element,
        #ai_answer_div {
            font-size: ${fontSize}px;
            line-height: 1.55;
        }

        .bilingual-control-resizeable {
            font-size: ${controlFontSize}px;
            line-height: 1;
        }

        .bilingual-control-resizeable input[type="checkbox"] {
            width: 1em !important;
            height: 1em !important;
            min-width: 1em;
            min-height: 1em;
        }
    `;
}

async function loadTextPage(entityMatchId, page, perPage = DEFAULT_PER_PAGE) {
    const token = getCsrfToken();
    const res = await fetch('/text', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(token ? {'X-CSRF-TOKEN': token} : {}),
        },
        body: JSON.stringify({entity_match_id: parseInt(String(entityMatchId), 10), page, per_page: perPage}),
    });
    const json = await res.json();
    const code = json?.data?.code ?? res.status;
    if (!res.ok || code !== 200) {
        const msg = json?.data?.data?.error ?? json?.message ?? t('bilinguals.request_failed', {status: res.status});
        throw new Error(msg);
    }
    const payload = json.data.data;
    // The reader's sibling shape (ADR 0060): word maps {a, b} with the
    // highlight/explain eligibility flags as their own keys. The wire stays
    // snake_case; meta is read as-is.
    return {
        rows: payload.rows ?? [],
        wordMaps: payload.word_maps ?? {},
        highlightable: payload.highlightable ?? {a: false, b: false},
        explainable: payload.explainable ?? {a: false, b: false},
        languages: payload.languages ?? null,
        defaultLearningSide: payload.default_learning_side ?? null,
        meta: payload.meta ?? {
            current_page: page,
            per_page: perPage,
            total: (payload.rows ?? []).length,
            last_page: 1,
        },
    };
}


const Bilinguals = (props) => {
    const { t } = useI18n()
    const answerModel = props.answerModel
    const canUseAi = props.canUseAi
    const errors = props.errors
    const textList = props.textList ?? []
    // Pinned from an alignment card the match is fixed by the URL; from the
    // Practice menu there is no pin — the alignment picker selects the text.
    const pinnedMatch = props.pinnedMatch ?? null

    const initialPositions = loadPositions();
    const savedTextExists = initialPositions.currentText != null
        && textList.some((item) => String(item.id) === String(initialPositions.currentText));
    const initialText = pinnedMatch
        ? String(pinnedMatch.id)
        : (savedTextExists ? String(initialPositions.currentText) : String(props.currentText ?? ''));
    const initialSaved = pinnedMatch || savedTextExists
        ? (initialPositions.alignments?.[initialText] ?? null)
        : null;

    // Which match side is the learning target by default comes from the
    // server's side rule; the per-device flip inverts it (Working state).
    // State, not props: on the picker entry they arrive with each loaded
    // match's POST /text response.
    const [languages, setLanguages] = React.useState(props.languages ?? {a: {code: null, name: null}, b: {code: null, name: null}});
    const [defaultLearningSide, setDefaultLearningSide] = React.useState(props.defaultLearningSide === 'b' ? 'b' : 'a');
    let [currentText, setCurrentText] = React.useState(initialText);
    // The per-device flip (Side swap, ADR 0037) is shared with the reader:
    // one flip per text, keyed mm:{matchId} in the side-flip store.
    const {firstSide, secondSide, toggleTo} = useSideFlip(defaultLearningSide, currentText ? `mm:${currentText}` : null);
    const learningSide = firstSide;
    const baseSide = secondSide;

    // The question is split: an admin-owned format template (shown read-only,
    // never editable) plus the user's editable task list. A saved task list is
    // the customization and ships verbatim; null means "show the default".
    const [customTasks, setCustomTasks] = React.useState(props.currentTasks ?? null);
    const effectiveTasks = customTasks ?? String(props.questionTemplates?.tasks ?? '');
    // The read-only template substitutes the current column language names on
    // every render, so it tracks the language toggle.
    const questionInfo = String(props.questionTemplates?.format ?? '')
        .replaceAll(':base', languages[baseSide]?.name ?? languages[baseSide]?.code ?? '')
        .replaceAll(':learning', languages[learningSide]?.name ?? languages[learningSide]?.code ?? '');
    // Bumped on reset to remount the uncontrolled tasks textarea with the
    // restored default.
    const [questionResetKey, setQuestionResetKey] = React.useState(0);

    // Legacy saved rows keyed the reveal halves 'en'/'ru'; map them onto the
    // positional target/base halves.
    const normalizeSavedRow = (saved) => (saved?.n
        ? {n: saved.n, target: !!(saved.target ?? saved.en), base: !!(saved.base ?? saved.ru)}
        : null);

    let [showWorkplace, setShowWorkplace] = React.useState(props.showWorkplace)
    let [showQuestion, setShowQuestion] = React.useState(props.showQuestion)
    let [showText, setShowText] = React.useState(props.showText)
    let [showAI, setShowAI] = React.useState(props.showAI)
    let [highlightWords, setHighlightWords] = React.useState(props.highlightWords ?? true)
    // Stress marks toggle (ADR 0052), same family as highlight words.
    let [showStress, setShowStress] = React.useState(props.stressMarks ?? false)
    // Phrasal verbs toggle (ADR 0057): dotted underlines on English hits.
    let [showPhrasal, setShowPhrasal] = React.useState(props.phrasalVerbs ?? false)
    const [pending, setPending] = React.useState(false);
    const [aiAnswer, setAiAnswer] = React.useState('');
    const [aiError, setAiError] = React.useState(null);
    const [lastAskPayload, setLastAskPayload] = React.useState(null);
    const workplaceRef = React.useRef(null);
    const questionRef = React.useRef(null);
    const pendingWorkplaceFocusRef = React.useRef(false);

    const [rows, setRows] = React.useState([]);
    const [wordMaps, setWordMaps] = React.useState(null);
    // Highlight/explain eligibility rides its own prop (the reader's
    // sibling shape), not inside the word maps — so the map state stays
    // pure {a, b} and progress updates have nothing to preserve.
    const [highlightable, setHighlightable] = React.useState({a: false, b: false});
    const [explainable, setExplainable] = React.useState({a: false, b: false});
    const [allTarget, setAllTarget] = React.useState(false);
    const [textMeta, setTextMeta] = React.useState(null);
    const [textPage, setTextPage] = React.useState(initialSaved?.page ?? 1);
    const [loadError, setLoadError] = React.useState(null);
    const [fontSize, setFontSize] = React.useState(props.fontSize ?? DEFAULT_FONT_SIZE);
    // Word-popup typography follows the page's font setting (ADR 0031).
    const popupFontSize = popupFontSizeFor(fontSize);
    // Stable identity so memoized WordText columns don't re-render on every
    // parent pass (the AI panel streams state updates ~20x/second). The
    // model display fields feed the word popup's Models used popup; the
    // answer label is simulator-only (the reader has no AI questions).
    const explainConfig = React.useMemo(
        () => ({
            enabled: canUseAi,
            modelKey: props.explanationModel?.id ?? null,
            modelLabel: props.explanationModel?.label ?? null,
            followsAnswer: props.explanationModel?.followsAnswer === true,
            answerLabel: props.answerModel?.label ?? null,
        }),
        [canUseAi, props.explanationModel, props.answerModel],
    );
    const [aiPanelWidth, setAiPanelWidth] = React.useState(props.aiPanelWidth ?? 560);
    const [workplaceHeight, setWorkplaceHeight] = React.useState(props.workplaceHeight ?? 168);
    const [checkedRows, setCheckedRows] = React.useState(() => {
        const saved = normalizeSavedRow(initialSaved?.row);
        return saved ? {[saved.n]: {target: saved.target, base: saved.base}} : {};
    });
    const pendingScrollRowRef = React.useRef(null);
    const initialLoadDoneRef = React.useRef(false);

    useUiSettingsAutosave('simulator', {
        font_size: fontSize,
        show_text: showText,
        show_workplace: showWorkplace,
        show_question: showQuestion,
        show_ai: showAI,
        highlight_words: highlightWords,
        stress_marks: showStress,
        phrasal_verbs: showPhrasal,
        question: customTasks,
        ai_panel_width: aiPanelWidth,
        workplace_height: workplaceHeight,
    });

    const changeFontSize = (direction) => {
        setFontSize((prev) => {
            const next = direction === '+'
                ? Math.min(prev + FONT_SIZE_STEP, MAX_FONT_SIZE)
                : Math.max(prev - FONT_SIZE_STEP, MIN_FONT_SIZE);
            return next;
        });
    };

    React.useEffect(() => {
        updateResizeableFontStyles(fontSize);
    }, [fontSize]);

    const persistPage = React.useCallback((page) => {
        const positions = loadPositions();
        positions.currentText = String(currentText);
        // The flip left the position store for the shared side-flip store
        // (useSideFlip); drop the key an older build may have written.
        const entry = {...(positions.alignments?.[String(currentText)] ?? {})};
        delete entry.flipped;
        positions.alignments = {
            ...(positions.alignments ?? {}),
            [String(currentText)]: {...entry, page},
        };
        savePositions(positions);
        return positions;
    }, [currentText]);

    const fetchPage = React.useCallback(async (page) => {
        if (!currentText) {
            return;
        }
        setLoadError(null);
        setPending(true);
        try {
            const {rows: nextRows, wordMaps: nextWordMaps, highlightable: nextHighlightable, explainable: nextExplainable, languages: nextLanguages, defaultLearningSide: nextDefaultSide, meta} = await loadTextPage(currentText, page, DEFAULT_PER_PAGE);
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
            const positions = persistPage(meta.current_page ?? page);
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
            setPending(false);
        }
    }, [currentText, persistPage]);

    // Word progress changed in a popup: recolor the word on both sides.
    const handleWordProgress = React.useCallback((key, status) => {
        setWordMaps((maps) => {
            if (!maps) {
                return maps;
            }
            const apply = (side) => (maps[side]?.[key] ? {...maps[side], [key]: {...maps[side][key], s: status}} : maps[side]);
            return {...maps, a: apply('a'), b: apply('b')};
        });
    }, []);

    React.useEffect(() => {
        if (rows.length > 0 && pendingScrollRowRef.current !== null) {
            const el = document.getElementById(`simulator-row-${pendingScrollRowRef.current}`);
            el?.scrollIntoView({block: 'center'});
            pendingScrollRowRef.current = null;
        }
    }, [rows]);

    React.useEffect(() => {
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
    const handleLoadText = React.useCallback(() => {
        const saved = loadPositions().alignments?.[String(currentText)] ?? null;
        const page = saved?.page ?? 1;
        setTextPage(page);
        return fetchPage(page);
    }, [fetchPage, currentText]);

    const changeText = (event) => {
        const value = event.target.value;
        setCurrentText(value);
        const positions = loadPositions();
        positions.currentText = String(value);
        savePositions(positions);
    };

    const onToggleRow = (n, side) => {
        if (side === 'target' && !checkedRows[n]?.target) {
            creditRead(n);
        }
        setCheckedRows((prev) => {
            const rowState = {...(prev[n] ?? {target: false, base: false}), [side]: !(prev[n]?.[side])};
            const next = {...prev, [n]: rowState};
            const open = rowState.target || rowState.base;
            const positions = loadPositions();
            const key = String(currentText);
            // The flip left the position store for the shared side-flip
            // store (useSideFlip); drop the key an older build may have
            // written.
            const entry = {...(positions.alignments?.[key] ?? {})};
            delete entry.flipped;
            positions.alignments = {
                ...(positions.alignments ?? {}),
                [key]: {
                    ...entry,
                    row: open ? {n, target: rowState.target, base: rowState.base} : null,
                },
            };
            savePositions(positions);
            return next;
        });
    };

    const goToPage = React.useCallback(() => {
        if (!textMeta || pending) {
            return;
        }
        const parsed = parseInt(String(textPage), 10);
        if (Number.isNaN(parsed)) {
            setTextPage(textMeta.current_page);
            return;
        }
        const clamped = Math.min(Math.max(1, parsed), textMeta.last_page);
        setTextPage(clamped);
        if (clamped !== textMeta.current_page) {
            fetchPage(clamped);
        }
    }, [textMeta, textPage, pending, fetchPage]);

    const rowOffset = textMeta
        ? ((textMeta.current_page - 1) * textMeta.per_page)
        : 0;

    // Display order: column 0 (firstSide) is the learning target (hidden
    // until revealed), column 1 (secondSide) the base the Open/Ask actions
    // and the workplace pair with. Rows stay canonical — the flip is just
    // which side each column shows.

    const hasStressedData = rowsHaveAnnotation(rows, 'stressed');
    const hasPhrasalData = rowsHaveAnnotation(rows, 'phrasal');

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
    const applyFamiliarity = React.useCallback((familiarity) => {
        if (!familiarity || Object.keys(familiarity).length === 0) {
            return;
        }
        setWordMaps((maps) => (maps
            ? {...maps, a: patchWordMap(maps.a, familiarity), b: patchWordMap(maps.b, familiarity)}
            : maps));
    }, []);

    // Revealing a row's target sentence credits its dictionary words a read
    // (+1, deduplicated per sentence pair server-side).
    const creditRead = React.useCallback((n) => {
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
    const toggleAllTarget = (checked) => {
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
    };

    const focusOnWorkplace = () => {
        if (workplaceRef.current) {
            workplaceRef.current.value = '';
            workplaceRef.current.focus();
        }
        if (!showWorkplace) {
            pendingWorkplaceFocusRef.current = true;
            setShowWorkplace(true);
        }
    };
    const changeQuestion = (event) => {
        setCustomTasks(event.target.value)
    }
    const resetQuestion = () => {
        setCustomTasks(null);
        setQuestionResetKey((key) => key + 1);
    }
    React.useEffect(() => {
        if (!showWorkplace || !pendingWorkplaceFocusRef.current) {
            return;
        }
        pendingWorkplaceFocusRef.current = false;
        focusOnWorkplace();
    }, [showWorkplace]);

    const streamAsk = async (payload) => {
        setLastAskPayload(payload);
        setPending(true);
        setAiError(null);
        setAiAnswer('');
        let markdown = '';
        let lastRender = 0;
        try {
            const token = getCsrfToken();
            const res = await fetch('/ai/question/stream', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                    ...(token ? {'X-CSRF-TOKEN': token} : {}),
                },
                body: JSON.stringify(payload),
            });

            if (!res.ok) {
                const json = await res.json().catch(() => null);
                throw new Error(json?.data?.data?.error ?? json?.message ?? t('bilinguals.request_failed', {status: res.status}));
            }

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const {done, value} = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, {stream: true});

                const events = buffer.split('\n\n');
                buffer = events.pop();

                for (const event of events) {
                    const line = event.trim();
                    if (!line.startsWith('data:')) continue;
                    const data = line.slice(5).trim();
                    if (data === '[DONE]') continue;

                    try {
                        const parsed = JSON.parse(data);
                        if (parsed.error) {
                            throw new Error(parsed.error);
                        }
                        if (parsed.text) {
                            markdown += parsed.text;
                            const now = Date.now();
                            if (now - lastRender > 50) {
                                lastRender = now;
                                setAiAnswer(renderMarkdown(markdown));
                            }
                        }
                    } catch (e) {
                        if (e instanceof SyntaxError) continue;
                        throw e;
                    }
                }
            }
            setAiAnswer(renderMarkdown(markdown));
        } catch (e) {
            if (markdown) setAiAnswer(renderMarkdown(markdown));
            setAiError(e instanceof Error ? e.message : t('bilinguals.couldnt_reach_model'));
        } finally {
            setPending(false);
        }
    };

    const ask = async (row, overrides = {}) => {
        if (pending) {
            return;
        }

        // The base column pairs with the workplace — whatever language plays
        // the base after a toggle.
        const cellContent = sideTexts(row, baseSide).trim().replace('*', '');
        const workplaceText = String(overrides.workplaceText ?? workplaceRef.current?.value ?? '').trim().replace('*', '');
        // Only the tasks travel; the server joins them with the admin's
        // format template using the current column language codes.
        const tasks = String(overrides.tasks ?? effectiveTasks ?? '').trim();

        if (!cellContent || !workplaceText) {
            return;
        }

        const payload = {
            data: `${cellContent}\n${workplaceText}`,
            tasks,
            base: languages[baseSide]?.code ?? null,
            learning: languages[learningSide]?.code ?? null,
        };

        await streamAsk(payload);
    };

    const retryAsk = async (overrides = {}) => {
        if (!lastAskPayload || pending) {
            return;
        }
        const payload = {...lastAskPayload, ...(overrides.tasks ? {tasks: overrides.tasks} : {})};
        await streamAsk(payload);
    };

    return (
        <div className="body w-full flex-1 min-h-0 flex flex-col overflow-hidden bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] font-[var(--wbench-sans)]">
            {/* Page-scoped stylesheet: the simulator's CSS used to load
                globally from app.blade.php, dragging :has() tables and
                ai-prose rules onto every Inertia page. */}
            <Head>
                <link href="/css/simulator.css" rel="stylesheet" type="text/css"/>
            </Head>
            <div className="flex-none border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)]">
                <div className="flex flex-1 flex-wrap items-center gap-3 px-4 sm:px-5 py-2">
                    <span className="font-[var(--wbench-mono)] text-[11px] tracking-[0.22em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] whitespace-nowrap">
                        {t('bilinguals.title')} <span className="text-[var(--wbench-rule)] dark:text-[var(--wbench-rule-night)]">·</span> {languages.a?.code ?? 'a'}&nbsp;↔&nbsp;{languages.b?.code ?? 'b'}
                    </span>
                    <span className={HAIRLINE} aria-hidden="true"/>
                    {pinnedMatch ? (
                        <span className="font-[var(--wbench-mono)] text-[11px] tracking-wide text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] max-w-[22rem] truncate whitespace-nowrap">
                            {pinnedMatch.text}
                        </span>
                    ) : (
                        <div className="flex items-center gap-2">
                            <Select value={currentText} onChange={changeText}
                                    items={textList}/>
                            <Button color="green" onClick={() => handleLoadText()} type='button'>{t('bilinguals.load')}</Button>
                        </div>
                    )}
                    <span className={HAIRLINE} aria-hidden="true"/>
                    <div
                        role="radiogroup"
                        aria-label={t('bilinguals.learning_language')}
                        className="flex items-center gap-0.5 border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] rounded-sm p-0.5"
                    >
                        {['a', 'b'].map((side) => (
                            <label
                                key={side}
                                title={t('bilinguals.learning_language')}
                                className={[
                                    'px-2 h-6 inline-flex items-center font-[var(--wbench-mono)] text-[11px] tracking-wide uppercase rounded-sm cursor-pointer select-none',
                                    'transition-colors duration-200 focus-within:outline-none focus-within:ring-2 focus-within:ring-[var(--wbench-accent)]',
                                    learningSide === side
                                        ? 'bg-[var(--wbench-accent)] text-white dark:bg-[var(--wbench-accent-night)]'
                                        : 'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]',
                                ].join(' ')}
                            >
                                <input
                                    type="radio"
                                    name="simulator-learning-language"
                                    value={side}
                                    checked={learningSide === side}
                                    onChange={() => toggleTo(side)}
                                    className="sr-only"
                                />
                                {languages[side]?.code ?? side}
                            </label>
                        ))}
                    </div>
                    <span className={HAIRLINE} aria-hidden="true"/>
                    <div className="flex items-center gap-1">
                        <FontButton aria-label={t('bilinguals.increase_font_size')} label={t('bilinguals.increase_font_size')} onClick={() => changeFontSize('+')}>+</FontButton>
                        <FontButton aria-label={t('bilinguals.decrease_font_size')} label={t('bilinguals.decrease_font_size')} onClick={() => changeFontSize('-')}>−</FontButton>
                    </div>
                    <div className="ml-auto flex items-end gap-0.5 border-b border-transparent">
                        <button
                            type="button"
                            className={tabClass(showText)}
                            aria-label={t('bilinguals.text')}
                            aria-pressed={showText}
                            title={t('bilinguals.text')}
                            onClick={() => setShowText(!showText)}
                        >
                            <Icon name="bookOpen" className={panelToggleIconClass(showText)}/>
                            <Underline isActive={showText}/>
                        </button>
                        <button
                            type="button"
                            className={tabClass(showWorkplace)}
                            aria-label={t('bilinguals.workplace')}
                            aria-pressed={showWorkplace}
                            title={t('bilinguals.workplace')}
                            onClick={() => setShowWorkplace(!showWorkplace)}
                        >
                            <Icon name="pencil" className={panelToggleIconClass(showWorkplace)}/>
                            <Underline isActive={showWorkplace}/>
                        </button>
                        {canUseAi && (
                            <button
                                type="button"
                                className={tabClass(showQuestion)}
                                aria-label={t('bilinguals.question')}
                                aria-pressed={showQuestion}
                                title={t('bilinguals.question')}
                                onClick={() => setShowQuestion(!showQuestion)}
                            >
                                <Icon name="questionMarkCircle" className={panelToggleIconClass(showQuestion)}/>
                                <Underline isActive={showQuestion}/>
                            </button>
                        )}
                        <button
                            type="button"
                            className={tabClass(highlightWords)}
                            aria-label={t('bilinguals.highlight_words')}
                            aria-pressed={highlightWords}
                            title={t('bilinguals.highlight_words')}
                            onClick={() => setHighlightWords(!highlightWords)}
                        >
                            <Icon name="highlighter" className={panelToggleIconClass(highlightWords)}/>
                            <Underline isActive={highlightWords}/>
                        </button>
                        {hasStressedData && (
                            <button
                                type="button"
                                className={tabClass(showStress)}
                                aria-label={t('bilinguals.stress_marks')}
                                aria-pressed={showStress}
                                title={t('bilinguals.stress_marks')}
                                onClick={() => setShowStress(!showStress)}
                            >
                                <Icon name="stress" className={pronunciationIconClass}/>
                                <Underline isActive={showStress}/>
                            </button>
                        )}
                        {hasPhrasalData && (
                            <button
                                type="button"
                                className={tabClass(showPhrasal)}
                                aria-label={t('bilinguals.phrasal_verbs')}
                                aria-pressed={showPhrasal}
                                title={t('bilinguals.phrasal_verbs')}
                                onClick={() => setShowPhrasal(!showPhrasal)}
                            >
                                <Icon name="phrasal" className={pronunciationIconClass}/>
                                <Underline isActive={showPhrasal}/>
                            </button>
                        )}
                        <button
                            type="button"
                            className={tabClass(showAI)}
                            aria-label={t('bilinguals.ai')}
                            aria-pressed={showAI}
                            title={t('bilinguals.ai')}
                            onClick={() => setShowAI(!showAI)}
                        >
                            <Icon name="codeBracket" className={panelToggleIconClass(showAI)}/>
                            <Underline isActive={showAI}/>
                        </button>
                    </div>
                </div>
            </div>
            <div className="relative flex-1 min-h-0 flex gap-0 overflow-hidden">
                <Spinner errors={errors} pending={pending}/>
                <div className="flex-1 min-h-0 flex flex-col bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] border-r border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] overflow-hidden">
                    {showText === true &&
                        <>
                            {textMeta && textMeta.last_page > 1 && (
                                <div
                                    className="flex-none flex flex-wrap items-center justify-between gap-2 px-4 sm:px-5 py-2 bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] font-[var(--wbench-mono)] text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                    <span className="tracking-wide">
                                        <span className="text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">{textMeta.current_page}</span>
                                        <span className="mx-1 opacity-50">/</span>
                                        {textMeta.last_page}
                                        <span className="ml-3 opacity-60">{t('bilinguals.rows_count', {total: textMeta.total})}</span>
                                    </span>
                                    <div className="flex items-center gap-2">
                                        <Button color="dark" size="xs" outline type="button"
                                                disabled={textMeta.current_page <= 1 || pending}
                                                onClick={() => fetchPage(textMeta.current_page - 1)}>{t('bilinguals.previous')}</Button>
                                        <input
                                            type="number"
                                            min={1}
                                            max={textMeta.last_page}
                                            value={textPage}
                                            onChange={(e) => setTextPage(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') {
                                                    e.preventDefault();
                                                    goToPage();
                                                }
                                            }}
                                            disabled={pending}
                                            aria-label={t('bilinguals.page_number')}
                                            className="w-14 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-2 py-1 text-center font-[var(--wbench-mono)] text-xs text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
                                        />
                                        <Button color="dark" size="xs" outline type="button"
                                                disabled={textMeta.current_page >= textMeta.last_page || pending}
                                                onClick={() => fetchPage(textMeta.current_page + 1)}>{t('bilinguals.next')}</Button>
                                    </div>
                                </div>
                            )}
                            <TextContent
                                ask={ask}
                                focusOnWorkplace={focusOnWorkplace}
                                rows={rows}
                                firstSide={firstSide}
                                secondSide={secondSide}
                                rowOffset={rowOffset}
                                pending={pending}
                                loadError={loadError}
                                hasText={!!currentText}
                                hasLoaded={!!textMeta}
                                canUseAi={canUseAi}
                                checkedRows={checkedRows}
                                onToggleRow={onToggleRow}
                                targetLanguage={languages[learningSide]}
                                baseLanguage={languages[baseSide]}
                                targetWordMap={targetWordMap}
                                baseWordMap={baseWordMap}
                                targetHighlightable={targetHighlightable}
                                baseHighlightable={baseHighlightable}
                                targetExplainable={targetExplainable}
                                baseExplainable={baseExplainable}
                                highlightWords={highlightWords}
                                showStress={showStress}
                                showPhrasal={showPhrasal}
                                onWordProgress={handleWordProgress}
                                allTarget={allTarget}
                                onToggleAllTarget={toggleAllTarget}
                                popupFontSize={popupFontSize}
                                explain={explainConfig}
                            />
                        </>
                    }
                    {showWorkplace === true &&
                        <Workplace workplaceRef={workplaceRef} changeQuestion={changeQuestion} questionRef={questionRef} currentQuestion={effectiveTasks} questionInfo={questionInfo} onResetQuestion={resetQuestion} questionResetKey={questionResetKey} canResetQuestion={customTasks !== null} showQuestion={showQuestion} onToggleQuestion={() => setShowQuestion(!showQuestion)} canUseAi={canUseAi} height={workplaceHeight} onHeightChange={setWorkplaceHeight}/>
                    }
                </div>
                {showAI === true &&
                    <AI aiAnswer={aiAnswer} pending={pending} aiError={aiError} onRetry={retryAsk} canUseAi={canUseAi} answerModel={answerModel} explanationModel={props.explanationModel ?? null} width={aiPanelWidth} onWidthChange={setAiPanelWidth}/>
                }
            </div>
        </div>
    )
}

Bilinguals.layout = (page) => <Main children={page}/>
export default Bilinguals;
