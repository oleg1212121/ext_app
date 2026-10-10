/**
 * The reader index's pending-alignment label. English and Russian keep their
 * translated ui-string names (reader.english / reader.russian); any other
 * language resolves from the page's languages list — native name first,
 * registry name second, raw code last — so a third language is never
 * mislabeled as English or Russian.
 */
export const pendingLanguageLabel = (code, languages, t) => {
    if (code === 'en' || code === 'ru') {
        return t(code === 'en' ? 'reader.english' : 'reader.russian');
    }

    const language = languages.find((candidate) => candidate.code === code);

    return language?.native_name ?? language?.name ?? code;
};
