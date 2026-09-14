import { usePage } from '@inertiajs/react';

let strings = {};

export function useI18n() {
    const { props } = usePage();
    strings = props.uiStrings ?? {};

    return { t, locale: props.locale ?? 'en' };
}

export function t(key, replace = {}) {
    let text = strings[key] ?? key;

    for (const [name, value] of Object.entries(replace)) {
        text = text.replaceAll(`:${name}`, String(value));
    }

    if (import.meta.env.DEV && strings[key] === undefined) {
        console.warn(`[i18n] missing ui string: ${key}`);
    }

    return text;
}
