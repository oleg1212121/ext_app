import {useState} from 'react';

// The assessment question is split (server-assembled question, ADR 0040):
// an admin-owned format template shown read-only, plus the learner's
// editable task list — the customization. A saved task list ships verbatim;
// null means "show the default". The read-only template substitutes the
// current column language names on every render, so it tracks the side
// toggle.
export function useAssessmentQuestion({format, defaultTasks, initialTasks, languages, learningSide, baseSide}) {
    const [customTasks, setCustomTasks] = useState(initialTasks ?? null);
    // Bumped on reset to remount the uncontrolled tasks textarea with the
    // restored default.
    const [questionResetKey, setQuestionResetKey] = useState(0);

    const effectiveTasks = customTasks ?? String(defaultTasks ?? '');
    const questionInfo = String(format ?? '')
        .replaceAll(':base', languages[baseSide]?.name ?? languages[baseSide]?.code ?? '')
        .replaceAll(':learning', languages[learningSide]?.name ?? languages[learningSide]?.code ?? '');

    const changeQuestion = (event) => setCustomTasks(event.target.value);
    const resetQuestion = () => {
        setCustomTasks(null);
        setQuestionResetKey((key) => key + 1);
    };

    return {
        customTasks,
        effectiveTasks,
        questionInfo,
        questionResetKey,
        changeQuestion,
        resetQuestion,
        canResetQuestion: customTasks !== null,
    };
}
