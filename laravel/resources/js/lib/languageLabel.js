/**
 * The reader index's pending-alignment label. The page's languages list is
 * the primary path — native name first, registry name second, raw code last —
 * so any registry language resolves from its own data. The documented
 * exception: English and Russian keep their translated ui-string names
 * (reader.english / reader.russian) instead of their native names.
 */

// Languages whose label is a translated ui-string (localized into the interface
// language) rather than the language's own native name.
const TRANSLATED_LABELS = { en: 'reader.english', ru: 'reader.russian' };

export const pendingLanguageLabel = (code, languages, t) => {
    const translated = TRANSLATED_LABELS[code];
    if (translated) {
        return t(translated);
    }

    const language = languages.find((candidate) => candidate.code === code);

    return language?.native_name ?? language?.name ?? code;
};
