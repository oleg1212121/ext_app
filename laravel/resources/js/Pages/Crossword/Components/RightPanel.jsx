import TabContent from './TabContent';
import React from 'react';

const TABS = [
    {id: 0, label: 'Definitions'},
    {id: 1, label: 'Obsolete'},
    {id: 2, label: 'Russian'},
    {id: 3, label: 'Forms'},
];

const HAIRLINE = 'h-6 w-px bg-[var(--wbench-rule)] dark:bg-[var(--wbench-rule-night)]';

const tabClass = (isActive) => [
    'relative inline-flex items-center gap-1.5 px-2.5 py-1.5 text-sm font-medium tracking-wide transition-colors duration-200 rounded-sm',
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

const GhostButton = ({onClick, title, children}) => (
    <button
        type="button"
        title={title}
        aria-label={title}
        onClick={onClick}
        className="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-sm font-medium rounded-sm cursor-pointer transition-colors duration-200 border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-transparent text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)] hover:border-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
    >
        {children}
    </button>
);

export default function RightPanel({
    width,
    currentTab,
    setCurrentTab,
    definitions,
    obsolete,
    translations,
    forms,
    onCheckImage,
    onAskAi,
    onAcknowledge,
    onDismiss,
    onShowUnsolved,
    onStartDrag,
}) {
    return (
        <>
            <div
                className="drag-handle-vertical"
                role="separator"
                aria-orientation="vertical"
                title="Resize definitions panel"
                onMouseDown={(e) => {
                    e.preventDefault();
                    onStartDrag(e);
                }}
            />

            <div
                className="right overflow-auto p-4 flex flex-col bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] border-l border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]"
                style={{width: `${width}px`}}
            >
                <div className="flex flex-wrap items-end gap-1 mb-4 pb-4 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                    {TABS.map((tab) => (
                        <button
                            key={tab.id}
                            type="button"
                            className={tabClass(currentTab === tab.id)}
                            onClick={() => setCurrentTab(tab.id)}
                            aria-pressed={currentTab === tab.id}
                        >
                            {tab.label}
                            <Underline isActive={currentTab === tab.id}/>
                        </button>
                    ))}

                    <span className={HAIRLINE} aria-hidden="true"/>

                    <GhostButton onClick={onCheckImage} title="Image">Image</GhostButton>
                    <GhostButton onClick={onAskAi} title="Search">
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        Search
                    </GhostButton>
                    <GhostButton onClick={onAcknowledge} title="Approve">
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Approve
                    </GhostButton>
                    <GhostButton onClick={onDismiss} title="Delete">
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </GhostButton>
                    <GhostButton onClick={onShowUnsolved} title="Show unsolved words">
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </GhostButton>
                </div>

                <TabContent
                    currentTab={currentTab}
                    definitions={definitions}
                    obsolete={obsolete}
                    translations={translations}
                    forms={forms}
                />
            </div>
        </>
    );
}