import React from "react";

const DEFAULT_AI_PANEL_WIDTH = 620;

export default function AI(props) {
    const [panelWidth, setPanelWidth] = React.useState(DEFAULT_AI_PANEL_WIDTH);

    const startDrag = (event) => {
        event.preventDefault();
        const startX = event.clientX;
        const startWidth = panelWidth;

        const onMove = (e) => {
            const delta = e.clientX - startX;
            setPanelWidth(Math.max(200, startWidth - delta));
        };

        const onUp = () => {
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
            document.body.style.userSelect = '';
            document.body.style.cursor = '';
        };

        document.body.style.userSelect = 'none';
        document.body.style.cursor = 'col-resize';
        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
    };

    return (
        <div
            className="flex shrink-0 overflow-hidden bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)]"
            style={{width: `${panelWidth}px`}}
        >
            <div className="drag-handle-vertical" onMouseDown={startDrag}></div>
            <div className="min-w-0 flex-1 flex flex-col overflow-hidden border-l border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]">
                <div className="flex-none flex flex-col gap-0.5 px-4 py-3 border-b border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum-deep)] dark:bg-[var(--color-ink-night)]">
                    <span className="font-serif italic text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)] text-[10px] tracking-[0.22em] uppercase">Reader's gloss</span>
                    <span className="font-serif text-sm tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">AI Response</span>
                </div>
                <div className="flex-1 min-w-0 overflow-y-auto overflow-x-hidden p-4 bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] pb-5 mb-5">
                    <div id="ai_answer_div"
                         className="resizeable_element prose prose-stone prose-sm dark:prose-invert max-w-none break-words"
                         dangerouslySetInnerHTML={{__html: props.aiAnswer ?? ''}}></div>
                </div>
            </div>
        </div>
    )
}