function TabPanel({active, children}) {
    if (!active) {
        return null;
    }

    return <div className="w-full">{children}</div>;
}

function DefinitionList({items, className = 'text-gray-800 dark:text-gray-200'}) {
    return (
        <div className="space-y-2">
            {items.map((item, index) => (
                <div
                    key={index}
                    className={`flex gap-2 text-2xl ${className} leading-relaxed p-2 rounded hover:bg-orange-100 dark:hover:bg-gray-600 transition-colors duration-150`}
                >
                    <span className="font-semibold min-w-[3rem]">{index + 1}.</span>
                    <span>{item}</span>
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
                <DefinitionList items={translations} className="text-emerald-700 dark:text-emerald-400"/>
            </TabPanel>
            <TabPanel active={currentTab === 3}>
                <DefinitionList items={forms}/>
            </TabPanel>
        </div>
    );
}
