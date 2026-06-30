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
        throw new Error(`Request failed (${res.status})`);
    }

    return res;
}

export async function fetchTexts() {
    const res = await fetch('/get-texts', {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        },
    });

    if (!res.ok) {
        throw new Error(`Request failed (${res.status})`);
    }

    const json = await res.json();
    return json.data.texts;
}

export async function fetchCrossword(id, level) {
    const res = await postJson('/get-crossword', {id, level});
    const json = await res.json();
    return json.data.crossword;
}

export async function upvoteWord(word, book) {
    await postJson('/word/upvote', {word, book});
}

export async function acknowledgeWord(word) {
    await postJson('/word/acknowledge', {word});
}

export async function dismissWord(word) {
    await postJson('/word/dismiss', {word});
}

export async function askAi(word) {
    const res = await postJson('/word/ask-ai/', {word});
    const json = await res.json();
    return json.data.definitions ?? [];
}
