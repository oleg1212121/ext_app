import {getCsrfToken, responseErrorMessage} from './http';

export const DEFAULT_PER_PAGE = 50;

/**
 * Rename the /text wire payload (snake_case, the reader's sibling shape,
 * ADR 0060) into the page's camelCase shape, with the same defaults the
 * endpoint's consumers have always assumed. Pure — vitest pins it.
 */
export function mapTextPayload(payload, {page, perPage} = {}) {
    return {
        rows: payload.rows ?? [],
        wordMaps: payload.word_maps ?? {},
        highlightable: payload.highlightable ?? {a: false, b: false},
        explainable: payload.explainable ?? {a: false, b: false},
        languages: payload.languages ?? null,
        defaultLearningSide: payload.default_learning_side ?? null,
        meta: payload.meta ?? {
            current_page: page,
            per_page: perPage,
            total: (payload.rows ?? []).length,
            last_page: 1,
        },
    };
}

/**
 * POST /text for one page of an entity match and map the payload. Throws
 * with the endpoint's error message (or the caller's per-status fallback,
 * so i18n stays out of this module).
 */
export async function fetchTextPage(entityMatchId, page, perPage = DEFAULT_PER_PAGE, fallbackForStatus) {
    const token = getCsrfToken();
    const res = await fetch('/text', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(token ? {'X-CSRF-TOKEN': token} : {}),
        },
        body: JSON.stringify({entity_match_id: parseInt(String(entityMatchId), 10), page, per_page: perPage}),
    });
    const json = await res.json();
    const code = json?.data?.code ?? res.status;
    if (!res.ok || code !== 200) {
        throw new Error(responseErrorMessage(json, res.status, fallbackForStatus?.(res.status)));
    }
    return mapTextPayload(json.data.data, {page, perPage});
}
