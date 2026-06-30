import {router} from '@inertiajs/react';
import {useEffect, useState} from 'react';

const LANGUAGE_LABELS = {
    en: 'English',
    ru: 'Russian',
};

const selectClassName =
    'px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-sm rounded border border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 transition';

export default function ReaderIndexApp({lang = 'en', languages = [], entities = []}) {
    const [selectedEntityId, setSelectedEntityId] = useState(() => entities[0]?.id ?? '');

    useEffect(() => {
        setSelectedEntityId(entities[0]?.id ?? '');
    }, [lang, entities]);

    const handleLanguageChange = (event) => {
        const newLang = event.target.value;
        router.visit(`/reader-react/${newLang}`);
    };

    const openReader = () => {
        if (!selectedEntityId) {
            return;
        }

        router.visit(`/reader-react/${lang}/${selectedEntityId}`);
    };

    const canOpenReader = selectedEntityId !== '' && entities.length > 0;

    return (
        <div className="min-h-screen flex flex-col bg-orange-50 dark:bg-gray-900">
            <header className="flex-none bg-white dark:bg-gray-800 border-b-2 border-gray-400 dark:border-gray-600 shadow-md">
                <div className="flex flex-wrap items-center justify-center gap-3 px-4 py-3">
                    <div className="flex items-center gap-2">
                        <h1 className="text-lg font-semibold text-gray-800 dark:text-gray-100">Book Reader</h1>
                    </div>

                    <div className="h-6 w-px bg-gray-400 dark:bg-gray-600"/>

                    <select
                        className={selectClassName}
                        value={lang}
                        onChange={handleLanguageChange}
                        aria-label="Language"
                    >
                        {languages.map((languageCode) => (
                            <option key={languageCode} value={languageCode}>
                                {LANGUAGE_LABELS[languageCode] ?? languageCode}
                            </option>
                        ))}
                    </select>

                    <select
                        className={selectClassName}
                        value={selectedEntityId}
                        onChange={(event) => setSelectedEntityId(event.target.value)}
                        disabled={entities.length === 0}
                        aria-label="Entity"
                    >
                        {entities.length === 0 ? (
                            <option value="">No entities</option>
                        ) : (
                            entities.map((entity) => (
                                <option key={entity.id} value={entity.id}>
                                    {entity.name}
                                </option>
                            ))
                        )}
                    </select>

                    <button
                        type="button"
                        className="px-3 py-1.5 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition disabled:opacity-50 disabled:cursor-not-allowed"
                        disabled={!canOpenReader}
                        onClick={openReader}
                    >
                        Open Reader
                    </button>
                </div>
            </header>

            <main className="flex-1 overflow-y-auto bg-orange-100 dark:bg-gray-800 pb-5">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                    <div className="bg-white dark:bg-gray-700 rounded-md shadow-sm border-2 border-gray-400 dark:border-gray-600 p-6">
                        <p className="text-gray-600 dark:text-gray-400 text-sm">
                            Choose a language and entity above, then click Open Reader to start reading.
                        </p>
                    </div>
                </div>
            </main>
        </div>
    );
}
