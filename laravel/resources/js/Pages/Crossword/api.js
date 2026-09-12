function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function postJson(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
        },
        body: JSON.stringify(body),
    });

    if (!res.ok) {
        const message = await res.json().catch(() => null);
        throw new Error(message?.message ?? `Request failed (${res.status})`);
    }

    return res;
}

export async function fetchCrossword(entityId, level) {
    const res = await postJson('/crossword/generate', {entity_id: entityId, level});
    const json = await res.json();
    return json.data.crossword;
}

export async function completeCrossword(wordIds) {
    await postJson('/crossword/complete', {word_ids: wordIds});
}

export async function knowWord(wordId) {
    await postJson('/crossword/word/know', {word_id: wordId});
}
