import {getCsrfToken} from './http';

// The missing-mode lookup POST (popup-ai-enrich ticket 07), extracted from
// WordPopup.jsx (ai-answer-words ticket 02) so the ordering/short-circuit/
// fallback behavior is unit-testable in Node without component tests and
// reusable by the popup's next consuming surface. The RAW clicked surface
// plus a language code — the server derives the lookup key (never trusting
// the title) — CSRF-signed like every mutating call; the endpoint answers
// `{data: {word_id: int|null}}`, the id ALONE (the full payload still comes
// from the separate `GET /words/{word}?surface=` fetch).

// The POST proper. THROWS on any transport failure (network error, non-2xx
// status, unreadable body) — the two exported callers below each decide the
// collapse policy, and a null return from here means a genuine miss only.
async function requestWordId(surface, languageCode) {
    const res = await fetch('/words/lookup', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(getCsrfToken() ? {'X-CSRF-TOKEN': getCsrfToken()} : {}),
        },
        body: JSON.stringify({surface, language_code: languageCode}),
    });
    if (!res.ok) {
        throw new Error(String(res.status));
    }
    const json = await res.json();
    return json?.data?.word_id ?? null;
}

// The single-code lookup — WordPopup's postLookup contract, byte for byte:
// a miss (null word_id) AND a transport failure both resolve null, so every
// caller degrades to the plain missing card instead of growing an error
// state of its own.
export function lookupWordId(surface, languageCode) {
    return requestWordId(surface, languageCode).catch(() => null);
}

// Sequential resolution across an ordered language list (ai-answer-words
// ticket 02): codes run in list order, the first non-null word id wins
// (short-circuit — later codes never fire), a miss falls through to the
// next code, and ANY transport failure collapses the whole resolution to
// null immediately — the single-code contract above — so a broken transport
// never hammers the remaining codes and a failed resolve leaves the plain
// missing card, never an error state.
export async function resolveWordId(surface, languageCodes) {
    for (const languageCode of languageCodes) {
        let wordId;
        try {
            wordId = await requestWordId(surface, languageCode);
        } catch {
            return null;
        }
        if (wordId != null) {
            return wordId;
        }
    }
    return null;
}
