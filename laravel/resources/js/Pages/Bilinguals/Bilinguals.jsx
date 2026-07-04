import React from 'react';
import Main from '../../Layouts/Main.jsx'
import Spinner from '../../Components/Spinner.jsx'
import Select from "../../Components/Forms/Select.jsx";
import SelectGroup from "../../Components/Forms/SelectGroup.jsx";
import Button from "../../Components/Forms/Button.jsx";
import Workplace from "./Components/Workplace.jsx";
import AI from "./Components/AI.jsx";
import TextContent from "./Components/TextContent.jsx";

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

const DEFAULT_PER_PAGE = 50;
const DEFAULT_FONT_SIZE = 30;
const CONTROL_FONT_SCALE = 0.65;
const FONT_SIZE_STEP = 2;
const MIN_FONT_SIZE = 10;
const MAX_FONT_SIZE = 72;

function panelToggleIconClass(active) {
    return `h-6 w-6 shrink-0 ${active ? 'text-white' : 'text-gray-700 dark:text-gray-300'}`;
}

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
        ? {en_ru_entity_match_id: parseInt(String(filename), 10), page, per_page: perPage}
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
        const msg = json?.data?.data?.error ?? json?.message ?? `Request failed (${res.status})`;
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
    const aiModels = props.aiModels
    const textList = props.textList
    const errors = props.errors
    let [showWorkplace, setShowWorkplace] = React.useState(props.showWorkplace)
    let [showQuestion, setShowQuestion] = React.useState(props.showQuestion)
    let [showText, setShowText] = React.useState(props.showText)
    let [showAI, setShowAI] = React.useState(props.showAI)
    let [currentText, setCurrentText] = React.useState(props.currentText)
    let [currentModel, setCurrentModel] = React.useState(props.currentModel)
    let [currentQuestion, setCurrentQuestion] = React.useState(props.currentQuestion)
    const [pending, setPending] = React.useState(false);
    const [aiAnswer, setAiAnswer] = React.useState('');
    const workplaceRef = React.useRef(null);
    const questionRef = React.useRef(null);
    const pendingWorkplaceFocusRef = React.useRef(false);

    const [rows, setRows] = React.useState([]);
    const [textMeta, setTextMeta] = React.useState(null);
    const [textPage, setTextPage] = React.useState(1);
    const [loadError, setLoadError] = React.useState(null);
    const [fontSize, setFontSize] = React.useState(DEFAULT_FONT_SIZE);

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
        } catch (e) {
            setRows([]);
            setTextMeta(null);
            setLoadError(e instanceof Error ? e.message : 'Failed to load text');
        } finally {
            setPending(false);
        }
    }, [currentText]);

    const handleLoadText = React.useCallback(() => {
        setTextPage(1);
        return fetchPage(1);
    }, [fetchPage]);

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

    const ask = async (row) => {
        if (pending) {
            return;
        }

        const cellContent = String(row?.[1] ?? '').trim().replace('*', '');
        const workplaceText = String(workplaceRef.current?.value ?? '').trim().replace('*', '');
        const question = String(questionRef.current?.value ?? '').trim();

        if (!cellContent || !workplaceText) {
            return;
        }

        setPending(true);
        try {
            const token = getCsrfToken();
            const res = await fetch('/ai/question', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(token ? {'X-CSRF-TOKEN': token} : {}),
                },
                body: JSON.stringify({
                    data: `${cellContent}\n${workplaceText}`,
                    question: currentQuestion,
                    model: currentModel,
                }),
            });
            const json = await res.json();
            if (json?.data?.code === 200) {
                setAiAnswer(json.data.answer ?? '');
            } else {
                setAiAnswer('');
            }
        } catch {
            setAiAnswer('');
        } finally {
            setPending(false);
        }
    };
    return (
        <>
            <div className="body w-full flex-1 min-h-0 flex flex-col overflow-hidden bg-orange-100 dark:bg-gray-900">
                <div
                    className="flex-none bg-white dark:bg-gray-800 border-b-2 border-gray-400 dark:border-gray-600 shadow-md">
                    <div className="flex items-center gap-3 px-4 py-3">
                        <div className="flex items-center gap-2">
                            <SelectGroup value={currentModel} onChange={(e) => setCurrentModel(e.target.value)}
                                         groups={aiModels}/>
                        </div>
                        <div className="flex items-center gap-2">
                            <Select value={currentText} onChange={(e) => setCurrentText(e.target.value)}
                                    items={textList}/>
                            <Button color="green" onClick={() => handleLoadText()} type='button'>Load</Button>
                        </div>
                        <div className="h-6 w-px bg-gray-400 dark:bg-gray-600"></div>
                        <div className="flex items-center gap-1">
                            <button
                                type="button"
                                aria-label="Increase font size"
                                className="w-8 h-8 flex items-center justify-center bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 cursor-pointer text-gray-700 dark:text-gray-200 rounded transition"
                                onClick={() => changeFontSize('+')}
                            >
                                <span className="text-lg font-semibold">+</span>
                            </button>
                            <button
                                type="button"
                                aria-label="Decrease font size"
                                className="w-8 h-8 flex items-center justify-center bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 cursor-pointer text-gray-700 dark:text-gray-200 rounded transition"
                                onClick={() => changeFontSize('-')}
                            >
                                <span className="text-lg font-semibold">−</span>
                            </button>
                        </div>
                        <div className="h-6 w-px bg-gray-400 dark:bg-gray-600"></div>
                        <div className="flex items-center gap-1">
                            <Button
                                color="dark"
                                type="button"
                                outline={!showText}
                                aria-label="Text"
                                aria-pressed={showText}
                                title="Text"
                                className="!p-2"
                                onClick={() => setShowText(!showText)}
                            >
                                <svg className={panelToggleIconClass(showText)} aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6.03v13m0-13c-2.819-.831-4.715-1.076-8.029-1.023A.99.99 0 0 0 3 6v11c0 .563.466 1.014 1.03 1.007 3.122-.043 5.018.212 7.97 1.023m0-13c2.819-.831 4.715-1.076 8.029-1.023A.99.99 0 0 1 21 6v11c0 .563-.466 1.014-1.03 1.007-3.122-.043-5.018.212-7.97 1.023"/>
                                </svg>
                            </Button>
                            <Button
                                color="dark"
                                type="button"
                                outline={!showWorkplace}
                                aria-label="Workplace"
                                aria-pressed={showWorkplace}
                                title="Workplace"
                                className="!p-2"
                                onClick={() => setShowWorkplace(!showWorkplace)}
                            >
                                <svg className={panelToggleIconClass(showWorkplace)} aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="m14.304 4.844 2.852 2.852M7 7H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-4.5m2.409-9.91a2.017 2.017 0 0 1 0 2.853l-6.844 6.844L8 14l.713-3.565 6.844-6.844a2.015 2.015 0 0 1 2.852 0Z"/>
                                </svg>
                            </Button>
                            <Button
                                color="dark"
                                type="button"
                                outline={!showQuestion}
                                aria-label="Question"
                                aria-pressed={showQuestion}
                                title="Question"
                                className="!p-2"
                                onClick={() => setShowQuestion(!showQuestion)}
                            >
                                <svg className={panelToggleIconClass(showQuestion)} aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9.529 9.988a2.502 2.502 0 1 1 5 .191A2.441 2.441 0 0 1 12 12.582V14m-.01 3.008H12M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                </svg>
                            </Button>
                            <Button
                                color="dark"
                                type="button"
                                outline={!showAI}
                                aria-label="AI"
                                aria-pressed={showAI}
                                title="AI"
                                className="!p-2"
                                onClick={() => setShowAI(!showAI)}
                            >
                                <svg className={panelToggleIconClass(showAI)} aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="m8 8-4 4 4 4m8 0 4-4-4-4m-2-3-4 14"/>
                                </svg>
                            </Button>
                        </div>
                    </div>
                </div>
                <Spinner errors={errors} pending={pending}/>
                <div className="flex-1 min-h-0 flex gap-0 overflow-hidden bg-orange-100 dark:bg-gray-800">
                    <div
                        className="flex-1 min-h-0 flex flex-col bg-orange-100 dark:bg-gray-800 border-r-2 border-gray-400 dark:border-gray-600 overflow-hidden shadow-sm">
                        {showText === true &&
                            <>
                                {textMeta && textMeta.last_page > 1 && (
                                    <div
                                        className="flex-none flex items-center justify-between gap-2 px-4 py-2 bg-white dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600 text-sm text-gray-700 dark:text-gray-300">
                                        <span>Page {textMeta.current_page} of {textMeta.last_page} ({textMeta.total} rows)</span>
                                        <div className="flex items-center gap-2">
                                            <Button color="dark" size="xs" outline type="button"
                                                    disabled={textMeta.current_page <= 1 || pending}
                                                    onClick={() => fetchPage(textMeta.current_page - 1)}>Previous</Button>
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
                                                aria-label="Page number"
                                                className="w-16 rounded border border-gray-300 bg-white px-2 py-1 text-center text-sm text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 disabled:opacity-50"
                                            />
                                            <Button color="dark" size="xs" outline type="button"
                                                    disabled={textMeta.current_page >= textMeta.last_page || pending}
                                                    onClick={() => fetchPage(textMeta.current_page + 1)}>Next</Button>
                                        </div>
                                    </div>
                                )}
                                <TextContent ask={ask} focusOnWorkplace={focusOnWorkplace} rows={rows} rowOffset={rowOffset}/>
                            </>
                        }
                        {showWorkplace === true &&
                            <Workplace workplaceRef={workplaceRef} changeQuestion={changeQuestion} questionRef={questionRef} currentQuestion={currentQuestion} showQuestion={showQuestion}/>
                        }
                    </div>
                    {showAI === true &&
                        <AI aiAnswer={aiAnswer}/>
                    }
                </div>
            </div>

        </>
    )
}

Bilinguals.layout = (page) => <Main children={page}/>
export default Bilinguals;
