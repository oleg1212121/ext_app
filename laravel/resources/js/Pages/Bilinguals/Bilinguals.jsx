import React from 'react';
import { Link } from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx'
import Spinner from '../../Components/Spinner.jsx'
import Select from "../../Components/Forms/Select.jsx";
import SelectGroup from "../../Components/Forms/SelectGroup.jsx";
import Button from "../../Components/Forms/Button.jsx";
import Workplace from "./Components/Workplace.jsx";
import AI from "./Components/AI.jsx";
import TextContent from "./Components/TextContent.jsx";
import {useI18n} from '../../i18n';
import {getCsrfToken} from '../../lib/http';
import {loadPositions, savePositions} from '../../lib/simulatorPosition';
import {useUiSettingsAutosave} from '../../hooks/useUiSettingsAutosave';
import {marked} from 'marked';
import DOMPurify from 'dompurify';

marked.setOptions({breaks: true, gfm: true});

function normalizeArrowNotation(text) {
    return text.replace(/\$\\rightarrow\$|\\rightarrow|→|\$\\to\$|\$\\Rightarrow\$/g, '=>');
}

function markDoubleEquals(text) {
    if (!text) return text;
    const slots = [];
    const protectedText = text.replace(/(```[\s\S]*?```|~~~[\s\S]*?~~~|`[^`\n]+`)/g, (m) => {
        slots.push(m);
        return `\u0000${slots.length - 1}\u0000`;
    });
    const converted = protectedText.replace(/==([^\n=]+)==/g, '\u0001$1\u0001');
    return converted.replace(/\u0000(\d+)\u0000/g, (_m, i) => slots[Number(i)]);
}

function unmarkDoubleEquals(html) {
    return html.replace(/\u0001([\s\S]*?)\u0001/g, '<mark>$1</mark>');
}

const QUOTED_HTML = '&quot;(?:[^<&]|&(?!quot;))+&quot;|«(?:[^<&»]|&(?!quot;))+»|“(?:[^<&”]|&(?!quot;))+”|‘(?:[^<&’]|&(?!quot;))+’';
const CORRECTION_TOKEN = `(?:<(?:del|s|ins|u|b|strong|em|i|mark)\\b[^>]*>[^<]*</(?:del|s|ins|u|b|strong|em|i|mark)>|${QUOTED_HTML}|[^\\s<]+)`;

function highlightCorrections(html) {
    const pattern = new RegExp(`(${CORRECTION_TOKEN})\\s*(?:=&gt;|-&gt;)\\s*(${CORRECTION_TOKEN})|(<[^>]+>)`, 'g');
    return html.replace(pattern, (m, old, fresh, tag) => {
        if (tag) return tag;
        return `<span class="ai-correction"><span class="ai-correction-old">${old}</span><span class="ai-correction-arrow">→</span><span class="ai-correction-new">${fresh}</span></span>`;
    });
}

function highlightQuotes(html) {
    const pattern = new RegExp(`(<[^>]+>)|${QUOTED_HTML}`, 'g');
    return html.replace(pattern, (m, tag) => {
        if (tag) return tag;
        return `<mark class="ai-quote">${m}</mark>`;
    });
}

function highlightScores(html) {
    return html.replace(/(<[^>]+>)|\d{1,3}(?:[.,]\d+)?\s?%/g, (m, tag, offset, htmlString) => {
        if (tag) return tag;
        const previous = htmlString[offset - 1];
        if (previous && /[\d.,]/.test(previous)) return m;
        return `<mark class="ai-score">${m}</mark>`;
    });
}

function renderMarkdown(text) {
    if (!text) return '';
    const html = marked.parse(markDoubleEquals(normalizeArrowNotation(text)));
    const slots = [];
    const protectedHtml = html.replace(/<(pre|code|kbd|samp)\b[^>]*>[\s\S]*?<\/\1>/gi, (m) => {
        slots.push(m);
        return `\u0000${slots.length - 1}\u0000`;
    });
    const highlighted = highlightScores(highlightQuotes(highlightCorrections(unmarkDoubleEquals(protectedHtml))));
    return DOMPurify.sanitize(highlighted.replace(/\u0000(\d+)\u0000/g, (_m, i) => slots[Number(i)]));
}

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
        .eng.resizeable_element,
        .rus.resizeable_element,
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
        meta: payload.meta ?? {
            current_page: page,
            per_page: perPage,
            total: (payload.rows ?? []).length,
            last_page: 1,
        },
    };
}


const Bilinguals = (props) => {
    const { t } = useI18n();
    const aiModels = props.aiModels
    const canUseAi = props.canUseAi
    const textList = props.textList
    const errors = props.errors

    const initialPositions = loadPositions();
    const savedTextExists = initialPositions.currentText != null
        && textList.some((item) => String(item.id) === String(initialPositions.currentText));
    const initialText = savedTextExists ? String(initialPositions.currentText) : props.currentText;
    const initialSaved = savedTextExists
        ? (initialPositions.alignments?.[String(initialPositions.currentText)] ?? null)
        : null;

    let [showWorkplace, setShowWorkplace] = React.useState(props.showWorkplace)
    let [showQuestion, setShowQuestion] = React.useState(props.showQuestion)
    let [showText, setShowText] = React.useState(props.showText)
    let [showAI, setShowAI] = React.useState(props.showAI)
    let [currentText, setCurrentText] = React.useState(initialText)
    let [currentModel, setCurrentModel] = React.useState(props.currentModel)
    let [currentQuestion, setCurrentQuestion] = React.useState(props.currentQuestion)
    const [pending, setPending] = React.useState(false);
    const [aiAnswer, setAiAnswer] = React.useState('');
    const [aiError, setAiError] = React.useState(null);
    const [lastAskPayload, setLastAskPayload] = React.useState(null);
    const workplaceRef = React.useRef(null);
    const questionRef = React.useRef(null);
    const pendingWorkplaceFocusRef = React.useRef(false);

    const [rows, setRows] = React.useState([]);
    const [textMeta, setTextMeta] = React.useState(null);
    const [textPage, setTextPage] = React.useState(initialSaved?.page ?? 1);
    const [loadError, setLoadError] = React.useState(null);
    const [fontSize, setFontSize] = React.useState(props.fontSize ?? DEFAULT_FONT_SIZE);
    const [aiPanelWidth, setAiPanelWidth] = React.useState(props.aiPanelWidth ?? 560);
    const [workplaceHeight, setWorkplaceHeight] = React.useState(props.workplaceHeight ?? 168);
    const [checkedRows, setCheckedRows] = React.useState(
        () => initialSaved?.row
            ? {[initialSaved.row.n]: {en: !!initialSaved.row.en, ru: !!initialSaved.row.ru}}
            : {}
    );
    const pendingScrollRowRef = React.useRef(null);
    const initialLoadDoneRef = React.useRef(false);

    useUiSettingsAutosave('simulator', {
        font_size: fontSize,
        show_text: showText,
        show_workplace: showWorkplace,
        show_question: showQuestion,
        show_ai: showAI,
        model: currentModel,
        question: currentQuestion,
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
            const {rows: nextRows, meta} = await loadTextPage(currentText, page, DEFAULT_PER_PAGE);
            setRows(nextRows);
            setTextMeta(meta);
            setTextPage(meta.current_page ?? page);
            const positions = persistPage(meta.current_page ?? page);
            setCheckedRows({});
            const saved = positions.alignments?.[String(currentText)]?.row;
            const pageStart = ((meta.current_page ?? page) - 1) * (meta.per_page ?? DEFAULT_PER_PAGE);
            if (saved && saved.n > pageStart && saved.n <= pageStart + (meta.per_page ?? DEFAULT_PER_PAGE)) {
                setCheckedRows({[saved.n]: {en: !!saved.en, ru: !!saved.ru}});
                pendingScrollRowRef.current = saved.n;
            }
        } catch (e) {
            setRows([]);
            setTextMeta(null);
            setLoadError(e instanceof Error ? e.message : t('bilinguals.failed_to_load_text'));
        } finally {
            setPending(false);
        }
    }, [currentText, persistPage]);

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

    const handleLoadText = React.useCallback(() => {
        const saved = loadPositions().alignments?.[String(currentText)] ?? null;
        const page = saved?.page ?? 1;
        setTextPage(page);
        if (saved?.row) {
            setCheckedRows({[saved.row.n]: {en: !!saved.row.en, ru: !!saved.row.ru}});
            pendingScrollRowRef.current = saved.row.n;
        } else {
            setCheckedRows({});
        }
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
        setCheckedRows((prev) => {
            const rowState = {...(prev[n] ?? {en: false, ru: false}), [side]: !(prev[n]?.[side])};
            const next = {...prev, [n]: rowState};
            const open = rowState.en || rowState.ru;
            const positions = loadPositions();
            const key = String(currentText);
            positions.alignments = {
                ...(positions.alignments ?? {}),
                [key]: {
                    ...(positions.alignments?.[key] ?? {}),
                    row: open ? {n, en: rowState.en, ru: rowState.ru} : null,
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
        setCurrentQuestion(event.target.value)
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

        const cellContent = String(row?.[1] ?? '').trim().replace('*', '');
        const workplaceText = String(overrides.workplaceText ?? workplaceRef.current?.value ?? '').trim().replace('*', '');
        const question = String(overrides.question ?? questionRef.current?.value ?? '').trim();

        if (!cellContent || !workplaceText) {
            return;
        }

        const payload = {
            data: `${cellContent}\n${workplaceText}`,
            question: overrides.question ?? currentQuestion,
            model: overrides.model ?? currentModel,
        };

        await streamAsk(payload);
    };

    const retryAsk = async (overrides = {}) => {
        if (!lastAskPayload || pending) {
            return;
        }
        const payload = {...lastAskPayload, ...(overrides.question ? {question: overrides.question} : {}), ...(overrides.model ? {model: overrides.model} : {})};
        await streamAsk(payload);
    };

    return (
        <div className="body w-full flex-1 min-h-0 flex flex-col overflow-hidden bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] font-[var(--wbench-sans)]">
            <div className="flex-none border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)]">
                <div className="flex flex-1 flex-wrap items-center gap-3 px-4 sm:px-5 py-2">
                    <span className="font-[var(--wbench-mono)] text-[11px] tracking-[0.22em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] whitespace-nowrap">
                        {t('bilinguals.title')} <span className="text-[var(--wbench-rule)] dark:text-[var(--wbench-rule-night)]">·</span> en&nbsp;↔&nbsp;ru
                    </span>
                    <span className={HAIRLINE} aria-hidden="true"/>
                    {Object.keys(aiModels).length > 0 ? (
                        <SelectGroup value={currentModel} onChange={(e) => setCurrentModel(e.target.value)}
                                     groups={aiModels}/>
                    ) : (
                        <Link
                            href="/profile"
                            className="font-[var(--wbench-mono)] text-[11px] tracking-wide text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] hover:underline"
                        >
                            {t('bilinguals.add_api_key')}
                        </Link>
                    )}
                    <span className={HAIRLINE} aria-hidden="true"/>
                    <div className="flex items-center gap-2">
                        <Select value={currentText} onChange={changeText}
                                items={textList}/>
                                <Button color="green" onClick={() => handleLoadText()} type='button'>{t('bilinguals.load')}</Button>
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
                        {canUseAi && (
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
                        )}
                    </div>
                </div>
            </div>
            <Spinner errors={errors} pending={pending}/>
            <div className="flex-1 min-h-0 flex gap-0 overflow-hidden">
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
                            <TextContent ask={ask} focusOnWorkplace={focusOnWorkplace} rows={rows} rowOffset={rowOffset} pending={pending} loadError={loadError} hasText={!!currentText} canUseAi={canUseAi} checkedRows={checkedRows} onToggleRow={onToggleRow}/>
                        </>
                    }
                    {showWorkplace === true &&
                        <Workplace workplaceRef={workplaceRef} changeQuestion={changeQuestion} questionRef={questionRef} currentQuestion={currentQuestion} showQuestion={showQuestion} onToggleQuestion={() => setShowQuestion(!showQuestion)} canUseAi={canUseAi} height={workplaceHeight} onHeightChange={setWorkplaceHeight}/>
                    }
                </div>
                {showAI === true &&
                    <AI aiAnswer={aiAnswer} pending={pending} aiError={aiError} onRetry={retryAsk} width={aiPanelWidth} onWidthChange={setAiPanelWidth}/>
                }
            </div>
        </div>
    )
}

Bilinguals.layout = (page) => <Main children={page}/>
export default Bilinguals;
