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

// The editor API is work-nested like its page (ADR 0072); match payloads
// carry work_id, so every caller has both ids at hand.
function base(workId, matchId) {
    return `/works/${workId}/alignments/${matchId}`;
}

export const alignmentsApi = {
    rows(workId, matchId, page, perPage) {
        return request(`${base(workId, matchId)}/rows?page=${page}&per_page=${perPage}`);
    },

    unmatched(workId, matchId, side, page) {
        return request(`${base(workId, matchId)}/unmatched?side=${side}&page=${page}`);
    },

    needsReview(workId, matchId, page) {
        return request(`${base(workId, matchId)}/needs-review?page=${page}`);
    },

    refine(workId, matchId) {
        return request(`${base(workId, matchId)}/refine`, {method: 'POST'});
    },

    createRow(workId, matchId, afterRowId) {
        return request(`${base(workId, matchId)}/rows`, {
            method: 'POST',
            body: JSON.stringify({after_row_id: afterRowId}),
        });
    },

    deleteRow(workId, matchId, rowId) {
        return request(`${base(workId, matchId)}/rows/${rowId}`, {method: 'DELETE'});
    },

    approveRow(workId, matchId, rowId) {
        return request(`${base(workId, matchId)}/rows/${rowId}/approve`, {method: 'POST'});
    },

    disapproveRow(workId, matchId, rowId) {
        return request(`${base(workId, matchId)}/rows/${rowId}/disapprove`, {method: 'POST'});
    },

    addSentence(workId, matchId, {side, meaning_match_id, content}) {
        return request(`${base(workId, matchId)}/sentences`, {
            method: 'POST',
            body: JSON.stringify({side, meaning_match_id, content}),
        });
    },

    updateSentence(workId, matchId, sentenceId, {side, content}) {
        return request(`${base(workId, matchId)}/sentences/${sentenceId}`, {
            method: 'PATCH',
            body: JSON.stringify({side, content}),
        });
    },

    unlinkSentence(workId, matchId, sentenceId, side) {
        return request(`${base(workId, matchId)}/sentences/${sentenceId}`, {
            method: 'DELETE',
            body: JSON.stringify({side}),
        });
    },

    destroyUnmatched(workId, matchId, sentenceId, side) {
        return request(`${base(workId, matchId)}/unmatched/${sentenceId}`, {
            method: 'DELETE',
            body: JSON.stringify({side}),
        });
    },

    moveSentence(workId, matchId, payload) {
        return request(`${base(workId, matchId)}/sentences/move`, {
            method: 'POST',
            body: JSON.stringify(payload),
        });
    },
};
