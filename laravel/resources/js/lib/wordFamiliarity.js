import {segmentText} from './wordTokenizer.mjs';
import {getCsrfToken} from './http';

export const FAMILIARITY_MAX = 100;
export const FAMILIARITY_STRONG_AT = 60;
export const FAMILIARITY_PROGRESS_AT = 20;

/**
 * The popup's effective familiarity (word-popup-overhaul ticket 01; one
 * definition since the word-card review pass): the popup-open penalty's
 * response replaces the caller's open-time value once it answers — nullish,
 * not falsy, so a resolved 0 sticks — and an unmarked word is null. The card
 * header (lib/popupModel.js) and the shell's Anki send both read it, so the
 * header and the note's Familiarity field can never disagree.
 */
export function effectiveFamiliarity(resolvedFamiliarity, familiarity) {
    return resolvedFamiliarity ?? familiarity ?? null;
}

/**
 * Best-effort sentence-reveal read credits: +1 per fresh (word, row) read,
 * deduplicated per (word, sentence row, kind) on the server. Never throws —
 * reading must keep working when event logging fails; on failure the caller
 * just doesn't recolor.
 */
export async function recordWordEvents(events) {
    try {
        const token = getCsrfToken();
        const res = await fetch('/word-events', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(token ? {'X-CSRF-TOKEN': token} : {}),
            },
            body: JSON.stringify({events}),
        });
        if (!res.ok) {
            return {};
        }
        const json = await res.json();
        return json?.data?.familiarity ?? {};
    } catch {
        return {};
    }
}

/**
 * Best-effort popup-open penalty (word-popup-overhaul ticket 01): every
 * popup open of a dictionary-mapped word costs −5 familiarity, every time —
 * no dedupe of any kind. The native-language filter is server-side, so a
 * native word's response just echoes its unchanged score. Never throws —
 * reading must keep working when the POST fails; on failure the caller
 * just doesn't recolor.
 */
export async function recordWordOpens(wordIds) {
    try {
        const token = getCsrfToken();
        const res = await fetch('/words/opens', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(token ? {'X-CSRF-TOKEN': token} : {}),
            },
            body: JSON.stringify({word_ids: wordIds}),
        });
        if (!res.ok) {
            return {};
        }
        const json = await res.json();
        return json?.data?.familiarity ?? {};
    } catch {
        return {};
    }
}

/**
 * Dictionary word ids referenced by a sentence's text: distinct word ids of
 * every map-linked token in the sentence.
 */
export function rowWordIds(text, wordMap = {}) {
    const ids = [];
    for (const segment of segmentText(text ?? '')) {
        const entry = wordMap[segment.key];
        if (entry?.w && !ids.includes(entry.w)) {
            ids.push(entry.w);
        }
    }
    return ids;
}

/**
 * Set one word's familiarity status on both canonical sides of a word-map
 * pair, returning a new pair (null maps stay null). The shared recolor step
 * behind a popup progress change on every reading surface.
 */
export function patchWordStatus(maps, key, status) {
    if (!maps) {
        return maps;
    }
    const apply = (map) => (map?.[key] ? {...map, [key]: {...map[key], s: status}} : map);
    return {a: apply(maps.a), b: apply(maps.b)};
}

/**
 * Apply {wordId: familiarity} updates to a word map, returning a new map
 * (or the same one when nothing matches).
 */
export function patchWordMap(wordMap = {}, familiarity = {}) {
    const byWordId = new Map();
    for (const [wordId, value] of Object.entries(familiarity)) {
        byWordId.set(Number(wordId), value);
    }

    let changed = false;
    const next = {};
    for (const [key, entry] of Object.entries(wordMap)) {
        if (entry?.w && byWordId.has(entry.w)) {
            next[key] = {...entry, s: byWordId.get(entry.w)};
            changed = true;
        } else {
            next[key] = entry;
        }
    }
    return changed ? next : wordMap;
}
