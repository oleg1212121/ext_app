function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function request(url, options = {}) {
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
        ...(options.headers ?? {}),
    };

    const res = await fetch(url, {...options, headers});

    if (!res.ok) {
        let message = `Request failed (${res.status})`;

        try {
            const body = await res.json();

            if (body?.message) {
                message = body.message;
            } else if (body?.errors) {
                const first = Object.values(body.errors)[0];
                if (Array.isArray(first) && first.length > 0) {
                    message = first[0];
                }
            }
        } catch {
            // keep the generic message
        }

        const error = new Error(message);
        error.status = res.status;
        throw error;
    }

    if (res.status === 204) {
        return null;
    }

    return res.json();
}

export const alignmentsApi = {
    rows(matchId, page, perPage) {
        return request(`/alignments/${matchId}/rows?page=${page}&per_page=${perPage}`);
    },

    unmatched(matchId, lang, page) {
        return request(`/alignments/${matchId}/unmatched?lang=${lang}&page=${page}`);
    },

    createRow(matchId, afterRowId) {
        return request(`/alignments/${matchId}/rows`, {
            method: 'POST',
            body: JSON.stringify({after_row_id: afterRowId}),
        });
    },

    deleteRow(matchId, rowId) {
        return request(`/alignments/${matchId}/rows/${rowId}`, {method: 'DELETE'});
    },

    addSentence(matchId, {lang, meaning_match_id, content}) {
        return request(`/alignments/${matchId}/sentences`, {
            method: 'POST',
            body: JSON.stringify({lang, meaning_match_id, content}),
        });
    },

    updateSentence(matchId, sentenceId, {lang, content}) {
        return request(`/alignments/${matchId}/sentences/${sentenceId}`, {
            method: 'PATCH',
            body: JSON.stringify({lang, content}),
        });
    },

    unlinkSentence(matchId, sentenceId, lang) {
        return request(`/alignments/${matchId}/sentences/${sentenceId}`, {
            method: 'DELETE',
            body: JSON.stringify({lang}),
        });
    },

    destroyUnmatched(matchId, sentenceId, lang) {
        return request(`/alignments/${matchId}/unmatched/${sentenceId}`, {
            method: 'DELETE',
            body: JSON.stringify({lang}),
        });
    },

    moveSentence(matchId, payload) {
        return request(`/alignments/${matchId}/sentences/move`, {
            method: 'POST',
            body: JSON.stringify(payload),
        });
    },
};
