function TabPanel({active, children}) {
    if (!active) {
        return null;
    }

    return <div className="w-full">{children}</div>;
}

function DefinitionList({items, accent = 'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]'}) {
    return (
        <div className="space-y-1">
            {items.map((item, index) => (
                <div
                    key={index}
                    className={`group relative flex gap-3 text-2xl leading-relaxed p-2 rounded-sm hover:bg-[var(--color-vellum-deep)] dark:hover:bg-[var(--color-hairline-night)]/30 transition-colors duration-150`}
                >
                    <span aria-hidden="true" className="ribbon-mark absolute left-0 top-1/2 -translate-y-1/2 self-stretch h-8"/>
                    <span className="font-serif italic min-w-[3rem] text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">{index + 1}.</span>
                    <span className={`font-serif ${accent}`}>{item}</span>
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
                <DefinitionList items={translations} accent="text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]"/>
            </TabPanel>
            <TabPanel active={currentTab === 3}>
                <DefinitionList items={forms}/>
            </TabPanel>
        </div>
    );
}