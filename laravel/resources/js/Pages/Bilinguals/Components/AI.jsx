import React from "react";

const DEFAULT_AI_PANEL_WIDTH = 560;

export default function AI(props) {
    const [panelWidth, setPanelWidth] = React.useState(DEFAULT_AI_PANEL_WIDTH);

    const startDrag = (event) => {
        event.preventDefault();
        const startX = event.clientX;
        const startWidth = panelWidth;

        const onMove = (e) => {
            const delta = e.clientX - startX;
            setPanelWidth(Math.max(280, startWidth - delta));
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

    const hasAnswer = Boolean(props.aiAnswer);
    const isLoading = props.pending === true;
    const hasError = Boolean(props.aiError);

    return (
        <div
            className="flex shrink-0 overflow-hidden bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] font-[var(--wbench-sans)]"
            style={{width: `${panelWidth}px`}}
        >
            <div className="drag-handle-vertical" onMouseDown={startDrag} aria-hidden="true"></div>
            <div className="min-w-0 flex-1 flex flex-col overflow-hidden border-l border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                <div className="relative flex-none flex items-center justify-between gap-3 px-4 py-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)]">
                    <div className="flex flex-col gap-0.5 min-w-0">
                        <span className="font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">Reader's gloss</span>
                        <span className="font-[var(--wbench-serif)] text-sm tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] truncate">AI Response</span>
                    </div>
                    {hasAnswer && !isLoading && !hasError && (
                        <button
                            type="button"
                            onClick={props.onRetry}
                            disabled={props.pending}
                            className="shrink-0 inline-flex items-center gap-1.5 font-[var(--wbench-mono)] text-[10px] tracking-[0.18em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)] disabled:opacity-40 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                        >
                            <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/>
                            </svg>
                            Ask again
                        </button>
                    )}
                    {isLoading && (
                        <span className="shrink-0 inline-flex items-center gap-1.5 font-[var(--wbench-mono)] text-[10px] tracking-[0.18em] uppercase text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]">
                            <span className="relative flex h-1.5 w-1.5">
                                <span className="absolute inline-flex h-full w-full rounded-full bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)] opacity-60 motion-safe:animate-ping"></span>
                                <span className="relative inline-flex rounded-full h-1.5 w-1.5 bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)]"></span>
                            </span>
                            Working
                        </span>
                    )}
                    <span
                        aria-hidden="true"
                        className={`ai-loader-rule absolute left-0 bottom-0 h-[2px] bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)] ${isLoading ? 'is-loading' : ''}`}
                    />
                </div>

                <div className="flex-1 min-w-0 overflow-y-auto overflow-x-hidden bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
                    {hasError ? (
                        <div className="px-4 py-6 max-w-none">
                            <p className="font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)] mb-2">Couldn't reach the model</p>
                            <p className="font-[var(--wbench-serif)] text-base text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] leading-snug">{props.aiError}</p>
                            <button
                                type="button"
                                onClick={props.onRetry}
                                disabled={props.pending}
                                className="mt-4 inline-flex items-center gap-1.5 px-3 py-1.5 border border-[var(--wbench-accent)] dark:border-[var(--wbench-accent-night)] text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] font-[var(--wbench-mono)] text-[10px] tracking-[0.18em] uppercase rounded-sm hover:bg-[var(--wbench-accent)] hover:text-[var(--wbench-paper)] dark:hover:bg-[var(--wbench-accent-night)] dark:hover:text-[var(--wbench-paper-night)] transition-colors disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
                            >
                                Retry
                            </button>
                        </div>
                    ) : hasAnswer ? (
                        <div className="px-4 py-5 pb-8">
                            <div id="ai_answer_div"
                                 className="resizeable_element ai-prose max-w-none break-words"
                                 dangerouslySetInnerHTML={{__html: props.aiAnswer ?? ''}}></div>
                        </div>
                    ) : (
                        <div className="px-4 py-6 max-w-none">
                            <div className="flex items-start gap-3">
                                <span className="mt-1 shrink-0 inline-block h-5 w-5 border border-[var(--wbench-accent)] dark:border-[var(--wbench-accent-night)] text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] font-[var(--wbench-mono)] text-[10px] leading-[18px] text-center" aria-hidden="true">§</span>
                                <p className="font-[var(--wbench-serif)] text-base text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] leading-relaxed">
                                    Open a row, write your translation to the left, then send. The reader will answer here.
                                </p>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    )
}
