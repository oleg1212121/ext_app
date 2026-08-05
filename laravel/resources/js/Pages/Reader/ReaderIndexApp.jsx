import {router} from '@inertiajs/react';
import {useEffect, useState} from 'react';

const LANGUAGE_LABELS = {
    en: 'English',
    ru: 'Russian',
};

const LANGUAGE_GLOSS = {
    en: 'A parallel-text reader. Pick a text in English; the Russian translation sits across the gutter, sentence by sentence.',
    ru: 'Параллельный читатель. Выберите текст на русском; английский перевод — через спиральный желоб, предложение за предложением.',
};

const tabClass = (isActive) => [
    'relative px-3 py-1.5 text-sm font-medium tracking-wide transition-colors duration-200',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] rounded-sm',
    isActive
        ? 'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]'
        : 'text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60 hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)]',
].join(' ');

const Underline = ({isActive}) => (
    <span
        aria-hidden="true"
        className={[
            'absolute left-2 right-2 -bottom-[5px] h-[2px] bg-[var(--color-vermilion)]',
            'dark:bg-[var(--color-vermilion-night)] transition-transform duration-300 origin-left',
            isActive ? 'scale-x-100' : 'scale-x-0',
        ].join(' ')}
        style={{transformOrigin: 'left center'}}
    />
);

export default function ReaderIndexApp({lang = 'en', languages = [], entities = []}) {
    const [selectedEntityId, setSelectedEntityId] = useState(() => entities[0]?.id ?? null);

    useEffect(() => {
        setSelectedEntityId(entities[0]?.id ?? null);
    }, [lang, entities]);

    const handleLanguageChange = (nextLang) => {
        if (nextLang === lang) return;
        router.visit(`/reader-react/${nextLang}`);
    };

    const openReader = (entityId) => {
        const id = entityId ?? selectedEntityId;
        if (!id) return;
        router.visit(`/reader-react/${lang}/${id}`);
    };

    const activeLabel = LANGUAGE_LABELS[lang] ?? lang;
    const gloss = LANGUAGE_GLOSS[lang] ?? LANGUAGE_GLOSS.en;

    return (
        <div className="flex-1 min-h-0 flex flex-col bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
            <header className="flex-none border-b border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]">
                <div className="px-6 py-6 sm:px-10 lg:px-14 flex flex-wrap items-end justify-between gap-6">
                    <div className="shrink-0">
                        <span className="block font-serif italic text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)] text-xs tracking-[0.22em] uppercase">
                            Antiphonal
                        </span>
                        <h1 className="mt-1 font-serif text-2xl sm:text-3xl leading-none tracking-tight">
                            Parallel Reader
                        </h1>
                    </div>

                    <nav
                        aria-label="Library language"
                        className="inline-flex items-end gap-1 border-b border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]"
                    >
                        {languages.map((code, i) => {
                            const isActive = code === lang;
                            return (
                                <button
                                    key={code}
                                    type="button"
                                    className={tabClass(isActive)}
                                    onClick={() => handleLanguageChange(code)}
                                    aria-pressed={isActive}
                                >
                                    <span className="font-serif text-base">{LANGUAGE_LABELS[code] ?? code}</span>
                                    <span className="ml-1.5 font-sans text-[10px] tracking-[0.2em] uppercase opacity-60">
                                        {code}
                                    </span>
                                    <Underline isActive={isActive}/>
                                </button>
                            );
                        })}
                    </nav>
                </div>
            </header>

            <main className="flex-1 min-h-0 overflow-y-auto">
                <div className="grid grid-cols-1 lg:grid-cols-[260px_1px_1fr] xl:grid-cols-[320px_1px_1fr]">
                    <aside className="relative px-6 sm:px-10 lg:px-8 py-10 lg:py-14 border-b lg:border-b-0 border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]">
                        <span className="block font-sans text-[10px] tracking-[0.24em] uppercase text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/50">
                            Library · {entities.length} {entities.length === 1 ? 'text' : 'texts'}
                        </span>
                        <h2 className="mt-3 font-serif font-light leading-[0.95] tracking-tight text-5xl sm:text-6xl xl:text-7xl">
                            {activeLabel}
                        </h2>
                        <span className="mt-3 block h-px w-16 bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)]"/>
                        <p className="mt-6 max-w-[240px] font-serif italic text-sm leading-relaxed text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
                            {gloss}
                        </p>
                    </aside>

                    <span aria-hidden="true" className="hidden lg:block w-px bg-[var(--color-hairline)] dark:bg-[var(--color-hairline-night)]"/>

                    <section
                        aria-label={`${activeLabel} texts`}
                        className="px-6 sm:px-10 lg:px-12 py-10 lg:py-14"
                    >
                        {entities.length === 0 ? (
                            <div className="py-20 text-center">
                                <p className="font-serif italic text-lg text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
                                    No texts in this library yet.
                                </p>
                                <p className="mt-2 font-sans text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                    Switch the language above, or ask an editor to add texts.
                                </p>
                            </div>
                        ) : (
                            <ul role="list" className="divide-y divide-[var(--color-hairline)] dark:divide-[var(--color-hairline-night)]">
                                {entities.map((entity) => {
                                    const isActive = entity.id === selectedEntityId;
                                    return (
                                        <li key={entity.id} className="group">
                                            <button
                                                type="button"
                                                onClick={() => openReader(entity.id)}
                                                onMouseEnter={() => setSelectedEntityId(entity.id)}
                                                onFocus={() => setSelectedEntityId(entity.id)}
                                                aria-current={isActive ? 'true' : undefined}
                                                className={[
                                                    'relative w-full text-left py-5 pl-5 pr-4 flex items-baseline justify-between gap-6',
                                                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--color-vermilion)]',
                                                    'transition-colors duration-200',
                                                ].join(' ')}
                                            >
                                                <span
                                                    aria-hidden="true"
                                                    className="ribbon-mark absolute left-0 top-1/2 -translate-y-1/2 self-stretch h-8"
                                                />
                                                <span className="font-serif text-xl sm:text-2xl leading-snug tracking-tight transition-colors duration-200 group-hover:text-[var(--color-vermilion)] dark:group-hover:text-[var(--color-vermilion-night)]">
                                                    {entity.name}
                                                </span>
                                                <span
                                                    aria-hidden="true"
                                                    className="shrink-0 font-serif italic text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)] text-lg leading-none opacity-0 -translate-x-2 transition-all duration-200 group-hover:opacity-100 group-hover:translate-x-0"
                                                >
                                                    →
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}

                        <p className="mt-10 font-sans text-xs tracking-wide text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/50">
                            Select a text to open the reader · the translation appears across the gutter
                        </p>
                    </section>
                </div>
            </main>
        </div>
    );
}
