import React from 'react';
import { Head } from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx'
import Spinner from '../../Components/Spinner.jsx'
import Select from "../../Components/Forms/Select.jsx";
import Button from "../../Components/Forms/Button.jsx";
import Workplace from "./Components/Workplace.jsx";
import AI from "./Components/AI.jsx";
import TextContent from "./Components/TextContent.jsx";
import {popupFontSizeFor} from "../../Components/WordPopup.jsx";
import {useI18n} from '../../i18n';
import {getCsrfToken} from '../../lib/http';
import {loadPositions, savePositions} from '../../lib/simulatorPosition';
import {useUiSettingsAutosave} from '../../hooks/useUiSettingsAutosave';
import {patchWordMap, recordWordEvents, rowWordIds} from '../../lib/wordFamiliarity';
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

async function loadTextPage(filename, page, perPage = DEFAULT_PER_PAGE) {
    const token = getCsrfToken();
    const isAlignmentRunId = /^\d+$/.test(String(filename ?? ''));
    const body = isAlignmentRunId
        ? {entity_match_id: parseInt(String(filename), 10), page, per_page: perPage}
        : {filename, page, per_page: perPage};
    const res = await fetch('/text', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(token ? {'X-CSRF-TOKEN': token} : {}),
        },
        body: JSON.stringify(body),
    });
    const json = await res.json();
    const code = json?.data?.code ?? res.status;
    if (!res.ok || code !== 200) {
        const msg = json?.data?.data?.error ?? json?.message ?? t('bilinguals.request_failed', {status: res.status});
        throw new Error(msg);
    }
    const payload = json.data.data;
    return {
        rows: payload.rows ?? [],
        rowKeys: payload.row_keys ?? null,
        wordMaps: payload.word_maps ?? null,
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
    const otherSide = (side) => (side === 'a' ? 'b' : 'a');
    const [flipped, setFlipped] = React.useState(initialSaved?.flipped === true);
    const learningSide = flipped ? otherSide(defaultLearningSide) : defaultLearningSide;
    const baseSide = otherSide(learningSide);

    // A saved question is the user's customization and ships verbatim; null
    // means "render the template for the current sides".
    const [customQuestion, setCustomQuestion] = React.useState(props.currentQuestion ?? null);
    const effectiveQuestion = customQuestion ?? String(props.questionTemplate ?? '')
        .replaceAll(':base', languages[baseSide]?.name ?? languages[baseSide]?.code ?? '');

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
    let [currentText, setCurrentText] = React.useState(initialText)
    const [pending, setPending] = React.useState(false);
    const [aiAnswer, setAiAnswer] = React.useState('');
    const [aiError, setAiError] = React.useState(null);
    const [lastAskPayload, setLastAskPayload] = React.useState(null);
    const workplaceRef = React.useRef(null);
    const questionRef = React.useRef(null);
    const pendingWorkplaceFocusRef = React.useRef(false);

    const [rows, setRows] = React.useState([]);
    const [rowKeys, setRowKeys] = React.useState(null);
    const [wordMaps, setWordMaps] = React.useState(null);
    const [allTarget, setAllTarget] = React.useState(false);
    const [textMeta, setTextMeta] = React.useState(null);
    const [textPage, setTextPage] = React.useState(initialSaved?.page ?? 1);
    const [loadError, setLoadError] = React.useState(null);
    const [fontSize, setFontSize] = React.useState(props.fontSize ?? DEFAULT_FONT_SIZE);
    // Word-popup typography follows the page's font setting (ADR 0031).
    const popupFontSize = popupFontSizeFor(fontSize);
    // Stable identity so memoized WordText columns don't re-render on every
    // parent pass (the AI panel streams state updates ~20x/second).
    const explainConfig = React.useMemo(
        () => ({enabled: canUseAi, modelKey: props.explanationModelKey}),
        [canUseAi, props.explanationModelKey],
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
        question: customQuestion,
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
        positions.alignments = {
            ...(positions.alignments ?? {}),
            [String(currentText)]: {...(positions.alignments?.[String(currentText)] ?? {}), page},
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
            const {rows: nextRows, rowKeys: nextRowKeys, wordMaps: nextWordMaps, languages: nextLanguages, defaultLearningSide: nextDefaultSide, meta} = await loadTextPage(currentText, page, DEFAULT_PER_PAGE);
            setRows(nextRows);
            setRowKeys(nextRowKeys);
            setWordMaps(nextWordMaps);
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
            setRowKeys(null);
            setWordMaps(null);
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
    // restores the saved page and opened row, the flip is per-match too.
    const handleLoadText = React.useCallback(() => {
        const saved = loadPositions().alignments?.[String(currentText)] ?? null;
        const page = saved?.page ?? 1;
        setTextPage(page);
        setFlipped(saved?.flipped === true);
        return fetchPage(page);
    }, [fetchPage, currentText]);

    const changeText = (event) => {
        const value = event.target.value;
        setCurrentText(value);
        const positions = loadPositions();
        positions.currentText = String(value);
        savePositions(positions);
    };

    // Persisting the flip joins the per-match Working state (page + last
    // opened row) in the browser's position store.
    const setLearningSide = (side) => {
        const nextFlipped = side !== defaultLearningSide;
        if (nextFlipped === flipped) {
            return;
        }
        setFlipped(nextFlipped);
        const positions = loadPositions();
        const key = String(currentText);
        positions.alignments = {
            ...(positions.alignments ?? {}),
            [key]: {...(positions.alignments?.[key] ?? {}), flipped: nextFlipped},
        };
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
            positions.alignments = {
                ...(positions.alignments ?? {}),
                [key]: {
                    ...(positions.alignments?.[key] ?? {}),
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

    // Display order: column 0 is the learning target (hidden until revealed),
    // column 1 the base the Open/Ask actions and the workplace pair with.
    const shownRows = React.useMemo(() => (
        learningSide === 'a' ? rows : rows.map(([a, b]) => [b, a])
    ), [rows, learningSide]);

    // Word maps stay keyed by the match's actual sides; the display columns
    // index into them by the side currently playing each role.
    const targetWordMap = wordMaps?.[learningSide] ?? {};
    const baseWordMap = wordMaps?.[baseSide] ?? {};
    const targetHighlightable = !!(wordMaps?.highlightable?.[learningSide]);
    const baseHighlightable = !!(wordMaps?.highlightable?.[baseSide]);
    const targetExplainable = !!(wordMaps?.explainable?.[learningSide]);
    const baseExplainable = !!(wordMaps?.explainable?.[baseSide]);

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
        if (!targetHighlightable || !rowKeys) {
            return;
        }
        const index = n - 1 - rowOffset;
        if (index < 0 || index >= shownRows.length || !rowKeys[index]) {
            return;
        }
        const wordIds = rowWordIds(shownRows[index][0], targetWordMap);
        if (wordIds.length === 0) {
            return;
        }
        recordWordEvents([{row_key: rowKeys[index], kind: 'read', word_ids: wordIds}])
            .then(applyFamiliarity);
    }, [targetHighlightable, targetWordMap, rowKeys, shownRows, rowOffset, applyFamiliarity]);

    // Master target checkbox: reveal the whole column and credit every loaded
    // row's words in one batched request.
    const toggleAllTarget = (checked) => {
        setAllTarget(checked);
        if (!checked || !targetHighlightable || !rowKeys) {
            return;
        }
        const events = [];
        shownRows.forEach((row, index) => {
            const wordIds = rowWordIds(row[0], targetWordMap);
            if (rowKeys[index] && wordIds.length > 0) {
                events.push({row_key: rowKeys[index], kind: 'read', word_ids: wordIds});
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
        setCustomQuestion(event.target.value)
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

        // The display row's base column (index 1) pairs with the workplace —
        // whatever language plays the base after a toggle.
        const cellContent = String(row?.[1] ?? '').trim().replace('*', '');
        const workplaceText = String(overrides.workplaceText ?? workplaceRef.current?.value ?? '').trim().replace('*', '');
        const question = String(overrides.question ?? effectiveQuestion ?? '').trim();

        if (!cellContent || !workplaceText) {
            return;
        }

        const payload = {
            data: `${cellContent}\n${workplaceText}`,
            question,
        };

        await streamAsk(payload);
    };

    const retryAsk = async (overrides = {}) => {
        if (!lastAskPayload || pending) {
            return;
        }
        const payload = {...lastAskPayload, ...(overrides.question ? {question: overrides.question} : {})};
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
                                    onChange={() => setLearningSide(side)}
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
                            <svg className={panelToggleIconClass(showText)} aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6.03v13m0-13c-2.819-.831-4.715-1.076-8.029-1.023A.99.99 0 0 0 3 6v11c0 .563.466 1.014 1.03 1.007 3.122-.043 5.018.212 7.97 1.023m0-13c2.819-.831 4.715-1.076 8.029-1.023A.99.99 0 0 1 21 6v11c0 .563-.466 1.014-1.03 1.007-3.122-.043-5.018.212-7.97 1.023"/>
                            </svg>
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
                            <svg className={panelToggleIconClass(showWorkplace)} aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="m14.304 4.844 2.852 2.852M7 7H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-4.5m2.409-9.91a2.017 2.017 0 0 1 0 2.853l-6.844 6.844L8 14l.713-3.565 6.844-6.844a2.015 2.015 0 0 1 2.852 0Z"/>
                            </svg>
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
                                <svg className={panelToggleIconClass(showQuestion)} aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9.529 9.988a2.502 2.502 0 1 1 5 .191A2.441 2.441 0 0 1 12 12.582V14m-.01 3.008H12M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                </svg>
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
                            <svg className={panelToggleIconClass(highlightWords)} aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="m14.613 3.514 5.873 5.874a1 1 0 0 1 0 1.414l-7.172 7.172a1 1 0 0 1-.707.293H8.414a1 1 0 0 1-.707-.293L2.939 13.2a1 1 0 0 1 0-1.414L10.2 4.46a1 1 0 0 1 1.414 0Zm-2.6 11.5L19.5 7.5m-13 13H20"/>
                            </svg>
                            <Underline isActive={highlightWords}/>
                        </button>
                        <button
                            type="button"
                            className={tabClass(showAI)}
                            aria-label={t('bilinguals.ai')}
                            aria-pressed={showAI}
                            title={t('bilinguals.ai')}
                            onClick={() => setShowAI(!showAI)}
                        >
                            <svg className={panelToggleIconClass(showAI)} aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="m8 8-4 4 4 4m8 0 4-4-4-4m-2-3-4 14"/>
                            </svg>
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
                                rows={shownRows}
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
                                targetSide={learningSide}
                                baseSide={baseSide}
                                targetWordMap={targetWordMap}
                                baseWordMap={baseWordMap}
                                targetHighlightable={targetHighlightable}
                                baseHighlightable={baseHighlightable}
                                targetExplainable={targetExplainable}
                                baseExplainable={baseExplainable}
                                highlightWords={highlightWords}
                                onWordProgress={handleWordProgress}
                                rowKeys={rowKeys}
                                allTarget={allTarget}
                                onToggleAllTarget={toggleAllTarget}
                                popupFontSize={popupFontSize}
                                explain={explainConfig}
                            />
                        </>
                    }
                    {showWorkplace === true &&
                        <Workplace workplaceRef={workplaceRef} changeQuestion={changeQuestion} questionRef={questionRef} currentQuestion={effectiveQuestion} showQuestion={showQuestion} onToggleQuestion={() => setShowQuestion(!showQuestion)} canUseAi={canUseAi} height={workplaceHeight} onHeightChange={setWorkplaceHeight}/>
                    }
                </div>
                {showAI === true &&
                    <AI aiAnswer={aiAnswer} pending={pending} aiError={aiError} onRetry={retryAsk} canUseAi={canUseAi} answerModel={answerModel} width={aiPanelWidth} onWidthChange={setAiPanelWidth}/>
                }
            </div>
        </div>
    )
}

Bilinguals.layout = (page) => <Main children={page}/>
export default Bilinguals;
