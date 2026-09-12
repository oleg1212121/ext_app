import {useI18n} from '../../../i18n';
import TabContent from './TabContent';
import React from 'react';

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
    onKnow,
    onShowUnsolved,
    onStartDrag,
}) {
    const {t} = useI18n();

    const tabs = [
        {id: 0, label: t('crossword.tab_definitions')},
        {id: 1, label: t('crossword.tab_obsolete')},
        {id: 2, label: t('crossword.tab_translations')},
        {id: 3, label: t('crossword.tab_forms')},
    ];

    return (
        <>
            <div
                className="drag-handle-vertical"
                role="separator"
                aria-orientation="vertical"
                title={t('crossword.tab_definitions')}
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
                    {tabs.map((tab) => (
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

                    <GhostButton onClick={onCheckImage} title={t('crossword.image')}>
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        {t('crossword.image')}
                    </GhostButton>
                    <GhostButton onClick={onKnow} title={t('crossword.know')}>
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        {t('crossword.know')}
                    </GhostButton>
                    <GhostButton onClick={onShowUnsolved} title={t('crossword.show_unsolved')}>
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
