function TabPanel({active, children}) {
    if (!active) {
        return null;
    }

    return <div className="w-full">{children}</div>;
}

function DefinitionList({items, accent = 'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]'}) {
    if (!items.length) {
        return (
            <div className="px-2 py-6 text-center">
                <p className="font-[var(--font-wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] mb-2">
                    Nothing here yet
                </p>
                <p className="font-[var(--font-wbench-serif)] text-base text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] leading-snug">
                    Click a word on the grid to see its entries.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-1">
            {items.map((item, index) => (
                <div
                    key={index}
                    className="group relative flex gap-3 text-lg leading-relaxed p-2 rounded-sm hover:bg-[var(--wbench-paper-deep)] dark:hover:bg-[var(--wbench-paper-deep-night)] transition-colors duration-150"
                >
                    <span aria-hidden="true" className="xword-edge absolute left-0 top-1/2 -translate-y-1/2 self-stretch h-8"/>
                    <span className="font-[var(--font-wbench-mono)] text-xs pt-1 min-w-[2.5rem] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">{String(index + 1).padStart(2, '0')}</span>
                    <span className={`font-[var(--font-wbench-serif)] ${accent}`}>{item}</span>
                </div>
            ))}
        </div>
    );
}

export default function TabContent({currentTab, definitions, obsolete, translations, forms}) {
    return (
        <div className="flex-1 overflow-auto">
            <TabPanel active={currentTab === 0}>
                <DefinitionList items={definitions}/>
            </TabPanel>
            <TabPanel active={currentTab === 1}>
                <DefinitionList items={obsolete}/>
            </TabPanel>
            <TabPanel active={currentTab === 2}>
                <DefinitionList items={translations} accent="text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]"/>
            </TabPanel>
            <TabPanel active={currentTab === 3}>
                <DefinitionList items={forms}/>
            </TabPanel>
        </div>
    );
}