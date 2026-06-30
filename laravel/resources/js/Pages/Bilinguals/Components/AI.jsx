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
        <>
            <div
                className="flex shrink-0 bg-orange-100 dark:bg-gray-800 overflow-hidden shadow-sm"
                style={{width: `${panelWidth}px`}}
            >
                <div className="drag-handle-vertical" onMouseDown={startDrag}></div>
                <div className="min-w-0 flex-1 flex flex-col overflow-hidden border-r-2 border-gray-400 dark:border-gray-600">
                    <div
                        className="flex-none flex items-center px-4 py-3 border-b-2 border-gray-400 dark:border-gray-600 bg-orange-100 dark:bg-gray-800">
                        <span className="text-sm font-semibold text-gray-800 dark:text-gray-200">AI Response</span>
                    </div>
                    <div className="flex-1 min-w-0 overflow-y-auto overflow-x-hidden p-4 bg-white dark:bg-gray-700 pb-5 mb-5">
                        <div id="ai_answer_div"
                             className="resizeable_element prose prose-sm dark:prose-invert max-w-none break-words"
                             dangerouslySetInnerHTML={{__html: props.aiAnswer ?? ''}}></div>
                    </div>
                </div>
            </div>
        </>
    )
}
