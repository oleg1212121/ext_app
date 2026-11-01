import {useI18n} from '../../../i18n';

function TabPanel({active, children}) {
    if (!active) {
        return null;
    }

    return <div className="w-full">{children}</div>;
}

function EmptyState() {
    const {t} = useI18n();

    return (
        <div className="px-2 py-6 text-center">
            <p className="font-[var(--font-wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] mb-2">
                {t('crossword.nothing_here')}
            </p>
            <p className="font-[var(--font-wbench-serif)] text-base text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] leading-snug">
                {t('crossword.click_word')}
            </p>
        </div>
    );
}

const SECTION_HEAD_CLASS = 'font-[var(--font-wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] mb-2';

// One part-of-speech block: a "(Noun)" header over rows numbered from 01.
// Sections separate by a hairline rule — the panel's divider idiom.
function Section({section, children}) {
    return (
        <section>
            {section.word_class && (
                <p className={SECTION_HEAD_CLASS}>({section.word_class})</p>
            )}
            {children}
        </section>
    );
}

const ROW_CLASS = 'group relative flex gap-3 text-lg leading-relaxed p-2 rounded-sm hover:bg-[var(--wbench-paper-deep)] dark:hover:bg-[var(--wbench-paper-deep-night)] transition-colors duration-150';

const ROW_NUM_CLASS = 'font-[var(--font-wbench-mono)] text-xs pt-1 min-w-[2.5rem] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]';

function NumberedRow({index, accent, children}) {
    return (
        <div className={ROW_CLASS}>
            <span aria-hidden="true" className="xword-edge absolute left-0 top-1/2 -translate-y-1/2 self-stretch h-8"/>
            <span className={ROW_NUM_CLASS}>{String(index + 1).padStart(2, '0')}</span>
            <span className={`font-[var(--font-wbench-serif)] ${accent}`}>{children}</span>
        </div>
    );
}

// Sections with at least one definition, POS-headered, numbering restarted
// per section.
function DefinitionSections({sections}) {
    const visible = sections.filter((section) => section.definitions?.length > 0);

    if (!visible.length) {
        return <EmptyState/>;
    }

    return (
        <div className="space-y-5 divide-y divide-[var(--wbench-rule)] dark:divide-[var(--wbench-rule-night)]">
            {visible.map((section, sIndex) => (
                <Section key={sIndex} section={section}>
                    <div className="space-y-1 pt-1">
                        {section.definitions.map((definition, index) => (
                            <NumberedRow key={index} index={index} accent="text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                {definition}
                            </NumberedRow>
                        ))}
                    </div>
                </Section>
            ))}
        </div>
    );
}

// Sections with at least one translation: the linked words numbered
// (native language first, as shipped), then the staged raw candidates as
// one muted line under the section.
function TranslationSections({sections}) {
    const {t} = useI18n();
    const visible = sections.filter(
        (section) => section.translations?.length > 0 || section.staged_translations?.length > 0,
    );

    if (!visible.length) {
        return <EmptyState/>;
    }

    return (
        <div className="space-y-5 divide-y divide-[var(--wbench-rule)] dark:divide-[var(--wbench-rule-night)]">
            {visible.map((section, sIndex) => (
                <Section key={sIndex} section={section}>
                    <div className="space-y-1 pt-1">
                        {(section.translations ?? []).map((translation, index) => (
                            <NumberedRow
                                key={index}
                                index={index}
                                accent="text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]"
                            >
                                {translation.word}
                            </NumberedRow>
                        ))}
                        {(section.staged_translations?.length ?? 0) > 0 && (
                            <p className="px-2 pt-1 font-[var(--font-wbench-serif)] text-base italic text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                <span className="opacity-60">{t('word.raw_translations')}</span>{' '}
                                {section.staged_translations.join(', ')}
                            </p>
                        )}
                    </div>
                </Section>
            ))}
        </div>
    );
}

export default function TabContent({currentTab, sections = []}) {
    return (
        <div className="flex-1 overflow-auto">
            <TabPanel active={currentTab === 0}>
                <DefinitionSections sections={sections}/>
            </TabPanel>
            <TabPanel active={currentTab === 1}>
                <TranslationSections sections={sections}/>
            </TabPanel>
        </div>
    );
}
