import Textarea from "../../../Components/Forms/Textarea.jsx";
import React from "react";

const DEFAULT_WORKPLACE_HEIGHT = 168;

export default function Workplace(props) {
    const [workplaceHeight, setWorkplaceHeight] = React.useState(DEFAULT_WORKPLACE_HEIGHT);

    const startDrag = (event) => {
        event.preventDefault();
        const startY = event.clientY;
        const startHeight = workplaceHeight;

        const onMove = (e) => {
            const delta = e.clientY - startY;
            setWorkplaceHeight(Math.max(80, Math.min(Math.round(window.innerHeight * 0.6), startHeight - delta)));
        };

        const onUp = () => {
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
            document.body.style.userSelect = '';
            document.body.style.cursor = '';
        };

        document.body.style.userSelect = 'none';
        document.body.style.cursor = 'row-resize';
        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
    };

    return (
        <div className="mt-auto shrink-0 flex flex-col">
            <div className="drag-handle-horizontal flex-none" onMouseDown={startDrag} aria-hidden="true"></div>
            <div id="workplace_area"
                 className="shrink-0 border-t border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] overflow-y-auto"
                 style={{height: `${workplaceHeight}px`}}>

                <div className="px-4 py-3">
                    <Textarea ref={props.workplaceRef} label="Translation" value="" placeholder="Write your translation here, then ask the reader to grade it." className="resizeable_element"/>
                </div>
                {props.showQuestion === true && (
                    <div className="px-4 pb-4 border-t border-[var(--wbench-rule)]/70 dark:border-[var(--wbench-rule-night)]/70 pt-3">
                        <Textarea onChange={props.changeQuestion} ref={props.questionRef} label="Question" value={props.currentQuestion} placeholder="Question" className="resizeable_element" rows={3}/>
                    </div>
                )}
                {props.showQuestion !== true && (
                    <button
                        type="button"
                        onClick={props.onToggleQuestion}
                        className="mx-4 mb-3 inline-flex items-center gap-1.5 font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                    >
                        <span aria-hidden="true">▾</span> Question
                    </button>
                )}
            </div>
        </div>
    )
}
