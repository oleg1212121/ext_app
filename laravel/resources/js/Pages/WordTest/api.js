function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function postJson(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
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

export async function submitWordTest(token, known) {
    const res = await postJson('/word-test/submit', {token, known});
    const json = await res.json();
    return json.data;
}
