import {segmentText} from './wordTokenizer.mjs';
import {getCsrfToken} from './http';

export const FAMILIARITY_MAX = 100;
export const FAMILIARITY_STRONG_AT = 60;
export const FAMILIARITY_PROGRESS_AT = 20;

/**
 * Best-effort familiarity event logging: read (+1) and lookup (-2) events,
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
