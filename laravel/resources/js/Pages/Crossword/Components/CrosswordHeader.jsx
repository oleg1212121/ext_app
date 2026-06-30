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
        <header className="flex-none bg-white dark:bg-gray-800 border-b-2 border-gray-400 dark:border-gray-600 shadow-md">
            <div className="flex flex-wrap items-center justify-center gap-3 px-4 py-3">
                <div className="flex items-center gap-2">
                    <h1 className="text-lg font-semibold text-gray-800 dark:text-gray-100">Crossword Puzzle</h1>
                </div>

                <div className="h-6 w-px bg-gray-400 dark:bg-gray-600"/>

                <select
                    className="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-sm rounded border border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 transition"
                    value={currentText}
                    onChange={(e) => setCurrentText(e.target.value)}
                >
                    {texts.map((text) => (
                        <option key={text.id} value={text.id}>{text.name}</option>
                    ))}
                </select>

                <select
                    className="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-sm rounded border border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 transition"
                    value={currentLevel}
                    onChange={(e) => setCurrentLevel(Number(e.target.value))}
                >
                    {wordLevels.map((level) => (
                        <option key={level.id} value={level.id}>{level.name}</option>
                    ))}
                </select>

                <button
                    type="button"
                    className="px-3 py-1.5 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition"
                    onClick={onBuild}
                >
                    Build Crossword
                </button>
            </div>
        </header>
    );
}
