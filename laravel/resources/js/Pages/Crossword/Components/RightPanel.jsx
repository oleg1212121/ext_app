import TabContent from './TabContent';

const TABS = [
    {id: 0, label: 'Definitions'},
    {id: 1, label: 'Obsolete'},
    {id: 2, label: 'Russian'},
    {id: 3, label: 'Forms'},
];

function tabButtonClass(isActive) {
    const base = 'px-3 py-2 bg-gray-100 dark:bg-gray-600 hover:bg-gray-200 dark:hover:bg-gray-500 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition font-medium';
    if (isActive) {
        return `${base} bg-gray-700 dark:bg-gray-500 text-white hover:bg-gray-800 dark:hover:bg-gray-400`;
    }
    return base;
}

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
                className="right overflow-auto p-4 flex flex-col bg-white dark:bg-gray-700 border-l-2 border-gray-400 dark:border-gray-600"
                style={{width: `${width}px`}}
            >
                <div className="flex flex-wrap gap-2 mb-4 pb-4 border-b-2 border-gray-200 dark:border-gray-600">
                    {TABS.map((tab) => (
                        <button
                            key={tab.id}
                            type="button"
                            className={tabButtonClass(currentTab === tab.id)}
                            onClick={() => setCurrentTab(tab.id)}
                        >
                            {tab.label}
                        </button>
                    ))}
                    <button
                        type="button"
                        className={tabButtonClass(false)}
                        onClick={onCheckImage}
                    >
                        Image
                    </button>

                    <div className="h-6 w-px bg-gray-300 dark:bg-gray-500"/>

                    <button
                        type="button"
                        className="px-3 py-2 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition flex items-center gap-1"
                        onClick={onAskAi}
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        Search
                    </button>
                    <button
                        type="button"
                        title="Approve"
                        className="px-3 py-2 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition flex items-center gap-1"
                        onClick={onAcknowledge}
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Approve
                    </button>
                    <button
                        type="button"
                        title="Delete"
                        className="px-3 py-2 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition flex items-center gap-1"
                        onClick={onDismiss}
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        Delete
                    </button>
                    <button
                        type="button"
                        title="Show unsolved words"
                        className="px-3 py-2 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition flex items-center gap-1"
                        onClick={onShowUnsolved}
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                        Unsolved
                    </button>
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
