const selectClass = [
    'px-3 py-1.5 text-sm font-serif tracking-tight rounded-sm transition cursor-pointer',
    'bg-[var(--color-vellum-deep)] dark:bg-[var(--color-hairline-night)]/40 text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]',
    'border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]',
].join(' ');

export default function CrosswordHeader({
    texts,
    currentText,
    setCurrentText,
    wordLevels,
    currentLevel,
    setCurrentLevel,
    onBuild,
}) {
    return (
        <header className="flex-none border-b border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum-deep)] dark:bg-[var(--color-ink-night)]">
            <div className="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-6 py-3">
                <div className="flex items-center gap-3 shrink-0">
                    <div className="hidden sm:flex flex-col leading-none">
                        <span className="font-serif italic text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)] text-[10px] tracking-[0.22em] uppercase">Antiphonal</span>
                        <span className="font-serif text-lg tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">Crossword</span>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <select
                        className={selectClass}
                        value={currentText}
                        onChange={(e) => setCurrentText(e.target.value)}
                    >
                        {texts.map((text) => (
                            <option key={text.id} value={text.id}>{text.name}</option>
                        ))}
                    </select>

                    <select
                        className={selectClass}
                        value={currentLevel}
                        onChange={(e) => setCurrentLevel(Number(e.target.value))}
                    >
                        {wordLevels.map((level) => (
                            <option key={level.id} value={level.id}>{level.name}</option>
                        ))}
                    </select>

                    <button
                        type="button"
                        className="px-3 py-1.5 text-sm font-medium rounded-sm cursor-pointer transition-colors duration-200 border border-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)] bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)] text-[var(--color-vellum)] dark:text-[var(--color-ink-night)] hover:bg-transparent hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]"
                        onClick={onBuild}
                    >
                        Build
                    </button>
                </div>
            </div>
        </header>
    );
}