const HAIRLINE = 'h-6 w-px bg-[var(--wbench-rule)] dark:bg-[var(--wbench-rule-night)]';

const selectClass = [
    'px-2.5 py-1.5 text-sm font-[var(--font-wbench-serif)] tracking-tight rounded-sm transition cursor-pointer',
    'bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-deep-night)]',
    'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]',
    'border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
    'hover:border-[var(--wbench-accent)] dark:hover:border-[var(--wbench-accent-night)]',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]',
].join(' ');

export default function CrosswordHeader({
    lang,
    texts,
    currentText,
    setCurrentText,
    wordLevels,
    currentLevel,
    setCurrentLevel,
    onBuild,
}) {
    return (
        <header className="flex-none border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)]">
            <div className="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-5 py-2">
                <div className="flex items-center gap-3 shrink-0">
                    <div className="hidden sm:flex flex-col leading-none gap-1">
                        <span className="font-[var(--font-wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            Crossword <span className="text-[var(--wbench-rule)] dark:text-[var(--wbench-rule-night)]">·</span> {lang}
                        </span>
                        <span className="font-[var(--font-wbench-serif)] text-lg tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            Workbench
                        </span>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <select
                        className={selectClass}
                        value={currentText}
                        onChange={(e) => setCurrentText(e.target.value)}
                        aria-label="Text"
                    >
                        {texts.map((text) => (
                            <option key={text.id} value={text.id}>{text.name}</option>
                        ))}
                    </select>

                    <select
                        className={selectClass}
                        value={currentLevel}
                        onChange={(e) => setCurrentLevel(Number(e.target.value))}
                        aria-label="Word level"
                    >
                        {wordLevels.map((level) => (
                            <option key={level.id} value={level.id}>{level.name}</option>
                        ))}
                    </select>

                    <span className={HAIRLINE} aria-hidden="true"/>

                    <button
                        type="button"
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-sm cursor-pointer transition-colors duration-200 border border-[var(--wbench-accent)] dark:border-[var(--wbench-accent-night)] bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)] text-[var(--wbench-paper)] dark:text-[var(--wbench-paper-night)] hover:bg-transparent hover:text-[var(--wbench-accent)] dark:hover:text-[var(--wbench-accent-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]"
                        onClick={onBuild}
                    >
                        Build
                    </button>
                </div>
            </div>
        </header>
    );
}