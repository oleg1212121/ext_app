export default function UnsolvedModal({show, onClose, items, hasCrossword}) {
    if (!show) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="absolute inset-0 bg-black bg-opacity-50" onClick={onClose}/>
            <div className="relative bg-white dark:bg-gray-800 rounded-md shadow-sm border-2 border-gray-400 dark:border-gray-600 w-11/12 md:w-5/6 lg:w-3/4 max-h-[90vh] overflow-y-auto p-6">
                <div className="flex justify-between items-center mb-4">
                    <h2 className="text-2xl font-semibold text-gray-800 dark:text-gray-100">Unsolved Words</h2>
                    <button
                        type="button"
                        className="text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:cursor-pointer text-2xl"
                        onClick={onClose}
                    >
                        ✕
                    </button>
                </div>
                <ul className="list-decimal pl-5 space-y-3">
                    {!hasCrossword && (
                        <li className="text-gray-500 dark:text-gray-400">No crossword loaded</li>
                    )}
                    {items.map((item) => (
                        <li key={item.word}>
                            <span className="text-xl font-semibold text-gray-800 dark:text-gray-100">{item.word}</span>
                            {item.definitions.map((definition, index) => (
                                <span
                                    key={index}
                                    className="text-base text-gray-700 dark:text-gray-300 block mt-1"
                                >
                                    {definition}
                                </span>
                            ))}
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}
