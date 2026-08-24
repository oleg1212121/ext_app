import {Link} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';

export default function Index({languages = []}) {
    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-wrap items-end justify-between gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            Entities
                        </p>
                        <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            Entity libraries
                        </h1>
                    </div>
                </header>

                {languages.length === 0 ? (
                    <div className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-4 py-12 text-center">
                        <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            No languages enabled.
                        </p>
                        <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            Enable a language in the admin panel to create entity libraries.
                        </p>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {languages.map((language) => (
                            <Link
                                key={language.code}
                                href={`/entities/${language.code}`}
                                className="group flex flex-col gap-2 border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-5 py-5 transition-colors hover:border-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                            >
                                <div className="flex items-baseline justify-between gap-3">
                                    <span className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                        {language.code}
                                    </span>
                                    <span className="font-mono text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                        {language.entity_count} {language.entity_count === 1 ? 'entity' : 'entities'}
                                    </span>
                                </div>
                                <h2 className="font-serif text-xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                    {language.name}
                                </h2>
                                {language.native_name && (
                                    <p className="text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                        {language.native_name}
                                    </p>
                                )}
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

Index.layout = (page) => <Main children={page}/>;
