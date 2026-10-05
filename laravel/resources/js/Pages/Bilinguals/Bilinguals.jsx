import React from 'react';
import { Head } from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx'
import Spinner from '../../Components/Spinner.jsx'
import Select from "../../Components/Forms/Select.jsx";
import Button from "../../Components/Forms/Button.jsx";
import Workplace from "./Components/Workplace.jsx";
import AI from "./Components/AI.jsx";
import TextContent from "./Components/TextContent.jsx";
import ReadingSideRadiogroup from "../../Components/ReadingSideRadiogroup.jsx";
import AnnotationToggle, { PanelToggleTab } from "../../Components/AnnotationToggle.jsx";
import PageInput from "../../Components/PageInput.jsx";
import {popupFontSizeFor} from "../../Components/WordPopup.jsx";
import {useI18n} from '../../i18n';
import {useUiSettingsAutosave} from '../../hooks/useUiSettingsAutosave';
import {useDragResize} from '../../hooks/useDragResize';
import {useFontSize} from '../../hooks/useFontSize';
import {useSimulatorText} from './useSimulatorText';
import {useAiStream} from './useAiStream';
import {useAssessmentQuestion} from './useAssessmentQuestion';
import {rowsHaveAnnotation, sideTexts} from '../../lib/readingRows.mjs';
import {buildAskPayload, stripLegacyAsterisk} from '../../lib/aiAsk.mjs';
import {updateResizeableFontStyles} from '../../lib/simulatorFontStyles';

const DEFAULT_FONT_SIZE = 26;
const FONT_SIZE_STEP = 2;
const MIN_FONT_SIZE = 12;
const MAX_FONT_SIZE = 48;

const HAIRLINE = 'h-5 w-px bg-[var(--wbench-rule)] dark:bg-[var(--wbench-rule-night)]';

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

// The simulator page is layout + toolbar + wiring: the text engine (rows,
// paging, position store, side flip, familiarity), the AI stream engine,
// and the assessment question engine each live in their own hook.
const Bilinguals = (props) => {
    const { t } = useI18n()
    const answerModel = props.answerModel
    const canUseAi = props.canUseAi
    const errors = props.errors
    const textList = props.textList ?? []
    // Pinned from an alignment card the match is fixed by the URL; from the
    // Practice menu there is no pin — the alignment picker selects the text.
    const pinnedMatch = props.pinnedMatch ?? null

    const {
        currentText, languages, learningSide, baseSide, firstSide, secondSide, toggleTo,
        rows, textMeta, textPage, loadError, rowOffset, checkedRows, allTarget,
        targetWordMap, baseWordMap, targetHighlightable, baseHighlightable, targetExplainable, baseExplainable,
        textPending,
        changeText, handleLoadText, fetchPage, commitPage,
        onToggleRow, onWordProgress, toggleAllTarget,
    } = useSimulatorText({
        pinnedMatch,
        textList,
        fallbackTextId: props.currentText,
        initialLanguages: props.languages,
        initialDefaultSide: props.defaultLearningSide,
    });

    const {
        customTasks, effectiveTasks, questionInfo, questionResetKey,
        changeQuestion, resetQuestion, canResetQuestion,
    } = useAssessmentQuestion({
        format: props.questionTemplates?.format,
        defaultTasks: props.questionTemplates?.tasks,
        initialTasks: props.currentTasks,
        languages,
        learningSide,
        baseSide,
    });

    const {aiAnswer, aiError, aiPending, streamAsk, retryAsk} = useAiStream();

    let [showWorkplace, setShowWorkplace] = React.useState(props.showWorkplace)
    let [showQuestion, setShowQuestion] = React.useState(props.showQuestion)
    let [showText, setShowText] = React.useState(props.showText)
    let [showAI, setShowAI] = React.useState(props.showAI)
    let [highlightWords, setHighlightWords] = React.useState(props.highlightWords ?? true)
    // Stress marks toggle (ADR 0052), same family as highlight words.
    let [showStress, setShowStress] = React.useState(props.stressMarks ?? false)
    // Phrasal verbs toggle (ADR 0057): dotted underlines on English hits.
    let [showPhrasal, setShowPhrasal] = React.useState(props.phrasalVerbs ?? false)

    const workplaceRef = React.useRef(null);
    const pendingWorkplaceFocusRef = React.useRef(false);

    const {fontSize, adjust: adjustFontSize} = useFontSize({
        initial: props.fontSize ?? DEFAULT_FONT_SIZE,
        min: MIN_FONT_SIZE,
        max: MAX_FONT_SIZE,
        step: FONT_SIZE_STEP,
    });
    // Word-popup typography follows the page's font setting (ADR 0031).
    const popupFontSize = popupFontSizeFor(fontSize);
    // Panel sizes: the shared drag hook owns state + drag mechanics; the
    // autosave persists them as UI settings. The AI panel has no max; the
    // workplace clamps at 60% of the viewport height.
    const {size: aiPanelWidth, startDrag: startAiPanelDrag} = useDragResize({axis: 'x', min: 280, initial: props.aiPanelWidth ?? 560});
    const {size: workplaceHeight, startDrag: startWorkplaceDrag} = useDragResize({
        axis: 'y',
        min: 80,
        max: () => Math.round(window.innerHeight * 0.6),
        initial: props.workplaceHeight ?? 168,
    });

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

    React.useEffect(() => {
        updateResizeableFontStyles(fontSize);
    }, [fontSize]);

    // Assemble the ask payload from the three engines: the base column pairs
    // with the workplace — whatever language plays the base after a toggle.
    const ask = async (row, overrides = {}) => {
        if (aiPending) {
            return;
        }
        const cellContent = stripLegacyAsterisk(sideTexts(row, baseSide));
        const workplaceText = stripLegacyAsterisk(overrides.workplaceText ?? workplaceRef.current?.value);
        // Only the tasks travel; the server joins them with the admin's
        // format template using the current column language codes.
        const tasks = String(overrides.tasks ?? effectiveTasks ?? '').trim();

        if (!cellContent || !workplaceText) {
            return;
        }

        await streamAsk(buildAskPayload({
            cellContent,
            workplaceText,
            tasks,
            base: languages[baseSide]?.code ?? null,
            learning: languages[learningSide]?.code ?? null,
        }));
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
    React.useEffect(() => {
        if (!showWorkplace || !pendingWorkplaceFocusRef.current) {
            return;
        }
        pendingWorkplaceFocusRef.current = false;
        focusOnWorkplace();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [showWorkplace]);

    // Display order: column 0 (firstSide) is the learning target (hidden
    // until revealed), column 1 (secondSide) the base the Open/Ask actions
    // and the workplace pair with. Rows stay canonical — the flip is just
    // which side each column shows.

    const hasStressedData = React.useMemo(() => rowsHaveAnnotation(rows, 'stressed'), [rows]);
    const hasPhrasalData = React.useMemo(() => rowsHaveAnnotation(rows, 'phrasal'), [rows]);

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
                    <ReadingSideRadiogroup
                        variant="sim"
                        ariaLabel={t('bilinguals.learning_language')}
                        name="simulator-learning-language"
                        options={['a', 'b'].map((side) => ({
                            value: side,
                            label: languages[side]?.code ?? side,
                            title: t('bilinguals.learning_language'),
                        }))}
                        value={learningSide}
                        onChange={toggleTo}
                    />
                    <span className={HAIRLINE} aria-hidden="true"/>
                    <div className="flex items-center gap-1">
                        <FontButton aria-label={t('bilinguals.increase_font_size')} label={t('bilinguals.increase_font_size')} onClick={() => adjustFontSize(FONT_SIZE_STEP)}>+</FontButton>
                        <FontButton aria-label={t('bilinguals.decrease_font_size')} label={t('bilinguals.decrease_font_size')} onClick={() => adjustFontSize(-FONT_SIZE_STEP)}>−</FontButton>
                    </div>
                    <div className="ml-auto flex items-end gap-0.5 border-b border-transparent">
                        <PanelToggleTab active={showText} label={t('bilinguals.text')} icon="bookOpen" onClick={() => setShowText(!showText)}/>
                        <PanelToggleTab active={showWorkplace} label={t('bilinguals.workplace')} icon="pencil" onClick={() => setShowWorkplace(!showWorkplace)}/>
                        {canUseAi && (
                            <PanelToggleTab active={showQuestion} label={t('bilinguals.question')} icon="questionMarkCircle" onClick={() => setShowQuestion(!showQuestion)}/>
                        )}
                        <PanelToggleTab active={highlightWords} label={t('bilinguals.highlight_words')} icon="highlighter" onClick={() => setHighlightWords(!highlightWords)}/>
                        {hasStressedData && (
                            <AnnotationToggle variant="sim" active={showStress} label={t('bilinguals.stress_marks')} icon="stress" onClick={() => setShowStress(!showStress)}/>
                        )}
                        {hasPhrasalData && (
                            <AnnotationToggle variant="sim" active={showPhrasal} label={t('bilinguals.phrasal_verbs')} icon="phrasal" onClick={() => setShowPhrasal(!showPhrasal)}/>
                        )}
                        <PanelToggleTab active={showAI} label={t('bilinguals.ai')} icon="codeBracket" onClick={() => setShowAI(!showAI)}/>
                    </div>
                </div>
            </div>
            <div className="relative flex-1 min-h-0 flex gap-0 overflow-hidden">
                <Spinner errors={errors} pending={textPending || aiPending}/>
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
                                                disabled={textMeta.current_page <= 1 || textPending}
                                                onClick={() => fetchPage(textMeta.current_page - 1)}>{t('bilinguals.previous')}</Button>
                                        <PageInput
                                            variant="sim"
                                            page={textPage}
                                            lastPage={textMeta.last_page}
                                            onCommit={commitPage}
                                            disabled={textPending}
                                            ariaLabel={t('bilinguals.page_number')}
                                        />
                                        <Button color="dark" size="xs" outline type="button"
                                                disabled={textMeta.current_page >= textMeta.last_page || textPending}
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
                                onWordProgress={onWordProgress}
                                allTarget={allTarget}
                                onToggleAllTarget={toggleAllTarget}
                                popupFontSize={popupFontSize}
                                explain={explainConfig}
                            />
                        </>
                    }
                    {showWorkplace === true &&
                        <Workplace workplaceRef={workplaceRef} changeQuestion={changeQuestion} currentQuestion={effectiveTasks} questionInfo={questionInfo} onResetQuestion={resetQuestion} questionResetKey={questionResetKey} canResetQuestion={canResetQuestion} showQuestion={showQuestion} onToggleQuestion={() => setShowQuestion(!showQuestion)} canUseAi={canUseAi} height={workplaceHeight} startDrag={startWorkplaceDrag}/>
                    }
                </div>
                {showAI === true &&
                    <AI aiAnswer={aiAnswer} pending={aiPending} aiError={aiError} onRetry={retryAsk} canUseAi={canUseAi} answerModel={answerModel} explanationModel={props.explanationModel ?? null} width={aiPanelWidth} startDrag={startAiPanelDrag}/>
                }
            </div>
        </div>
    )
}

Bilinguals.layout = (page) => <Main children={page}/>
export default Bilinguals;
