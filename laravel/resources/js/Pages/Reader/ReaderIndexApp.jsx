import {router} from '@inertiajs/react';
import {useEffect, useState} from 'react';

const LANGUAGE_LABELS = {
    en: 'English',
    ru: 'Russian',
};

const LANGUAGE_GLYPH = {
    en: 'EN',
    ru: 'RU',
};

const HAIRLINE = 'h-5 w-px bg-[var(--wbench-rule)] dark:bg-[var(--wbench-rule-night)]';
const DOT = 'text-[var(--wbench-rule)] dark:text-[var(--wbench-rule-night)]';

const tabClass = (isActive) => [
    'relative inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium tracking-wide transition-colors duration-200 rounded-sm',
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

export default function ReaderIndexApp({lang = 'en', languages = [], entities = []}) {
    const [selectedEntityId, setSelectedEntityId] = useState(() => entities[0]?.id ?? null);
    const [navigating, setNavigating] = useState(false);
    const [pendingLang, setPendingLang] = useState(null);

    useEffect(() => {
        setSelectedEntityId(entities[0]?.id ?? null);
    }, [lang, entities]);

    useEffect(() => {
        const unbind = router.on('finish', () => {
            setNavigating(false);
            setPendingLang(null);
        });
        return unbind;
    }, []);

    const handleLanguageChange = (nextLang) => {
        if (nextLang === lang || navigating) return;
        setPendingLang(nextLang);
        setNavigating(true);
        router.visit(`/reader-react/${nextLang}`);
    };

    const openReader = (entityId) => {
        const id = entityId ?? selectedEntityId;
        if (!id) return;
        router.visit(`/reader-react/${lang}/${id}`);
    };

    const pendingLabel = LANGUAGE_LABELS[pendingLang] ?? '';

    return (
        <div className="flex-1 min-h-0 flex flex-col bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] font-[var(--wbench-sans)]">
            <header className="relative flex-none border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)]">
                <div className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-2">
                    <span className="font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] whitespace-nowrap">
                        Reader <span className={DOT}>·</span> En&nbsp;↔&nbsp;Ru
                    </span>
                    <span className={HAIRLINE} aria-hidden="true"/>
                    <span className="font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] whitespace-nowrap">
                        Parallel Library
                    </span>

                    <nav
                        aria-label="Library language"
                        className="ml-auto inline-flex items-end gap-0.5"
                    >
                        {languages.map((code) => {
                            const isActive = code === lang;
                            return (
                                <button
                                    key={code}
                                    type="button"
                                    className={tabClass(isActive)}
                                    onClick={() => handleLanguageChange(code)}
                                    aria-pressed={isActive}
                                >
                                    <span className="font-[var(--wbench-mono)]">{LANGUAGE_GLYPH[code] ?? code}</span>
                                    <Underline isActive={isActive}/>
                                </button>
                            );
                        })}
                    </nav>
                </div>

                {navigating && (
                    <span
                        aria-hidden="true"
                        className="ai-loader-rule absolute left-0 right-0 -bottom-px h-[2px] bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)] is-loading"
                    />
                )}
            </header>

            <main className="flex-1 min-h-0 overflow-y-auto">
                <div className="mx-auto max-w-[62rem] px-5 sm:px-8 py-10">
                    {navigating ? (
                        <div role="status" aria-live="polite" className="py-24 flex flex-col items-center gap-4">
                            <span className="font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                Loading {pendingLabel ? `· ${pendingLabel}` : ''}
                            </span>
                            <div className="relative w-40 h-[2px] overflow-hidden bg-[var(--wbench-rule)] dark:bg-[var(--wbench-rule-night)]">
                                <span
                                    aria-hidden="true"
                                    className="ai-loader-rule absolute left-0 top-0 h-[2px] bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)] is-loading"
                                />
                            </div>
                        </div>
                    ) : entities.length === 0 ? (
                        <div className="py-20 text-center" role="status" aria-live="polite">
                            <p className="font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                No texts in this library
                            </p>
                            <p className="mt-3 max-w-md mx-auto font-[var(--wbench-serif)] text-lg leading-snug text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                Switch the language above, or ask an editor to add texts.
                            </p>
                        </div>
                    ) : (
                        <>
                            <p className="font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                Library <span className={DOT}>·</span> {entities.length} {entities.length === 1 ? 'text' : 'texts'}
                            </p>

                            <ul
                                role="list"
                                className="mt-6 divide-y divide-[var(--wbench-rule)] dark:divide-[var(--wbench-rule-night)]"
                            >
                                {entities.map((entity, index) => {
                                    const isActive = entity.id === selectedEntityId;
                                    const nStr = String(index + 1).padStart(2, '0');
                                    return (
                                        <li key={entity.id} className="group">
                                            <button
                                                type="button"
                                                onClick={() => openReader(entity.id)}
                                                onMouseEnter={() => setSelectedEntityId(entity.id)}
                                                onFocus={() => setSelectedEntityId(entity.id)}
                                                aria-current={isActive ? 'true' : undefined}
                                                className={[
                                                    'relative w-full text-left py-3 grid items-baseline gap-x-4',
                                                    'grid-cols-[2rem_1fr_auto]',
                                                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--wbench-accent)]',
                                                    'transition-colors duration-150',
                                                ].join(' ')}
                                            >
                                                <span
                                                    aria-hidden="true"
                                                    className="ribbon-mark absolute left-0 top-0 bottom-0"
                                                />
                                                <span className="font-[var(--wbench-mono)] text-[10px] tracking-[0.2em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] tabular-nums text-right">
                                                    {nStr}
                                                </span>
                                                <span className="font-[var(--wbench-serif)] text-xl leading-snug tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] group-hover:text-[var(--wbench-accent)] dark:group-hover:text-[var(--wbench-accent-night)] group-focus:text-[var(--wbench-accent)] dark:group-focus:text-[var(--wbench-accent-night)] transition-colors duration-150">
                                                    {entity.name}
                                                </span>
                                                <span
                                                    aria-hidden="true"
                                                    className="font-[var(--wbench-mono)] text-base leading-none text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] opacity-0 -translate-x-1 transition-all duration-150 group-hover:opacity-100 group-hover:translate-x-0 group-focus:opacity-100 group-focus:translate-x-0"
                                                >
                                                    →
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>

                            <p className="mt-10 font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                Open a text to read it <span className={DOT}>·</span> the translation appears across the gutter
                            </p>
                        </>
                    )}
                </div>
            </main>
        </div>
    );
}