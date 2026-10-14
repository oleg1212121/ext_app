// Browser-side AnkiConnect client (the profile's Anki tab). Only the browser
// ever talks to AnkiConnect — dev runs in Docker/WSL2 and prod is remote, so
// a PHP-side call could never reach a user's desktop Anki. Scope for now:
// invoke / version / requestPermission; the card-push actions arrive with
// the later Anki tickets and must live here too.
//
// Wire format: POST {action, version: 6, params?} to {url}:{port}; the
// answer is {result, error}. AnkiConnect reports failures INSIDE HTTP 200
// (a non-null `error` string), while CORS blocks and Anki being down reject
// the fetch itself — both are normalized into AnkiConnectError so the tab
// can render one failure panel with exact fix instructions.

export const ANKI_DEFAULT_URL = 'http://127.0.0.1'
export const ANKI_DEFAULT_PORT = 8765
export const ANKI_DEFAULT_DECK = 'ExtApp'

export class AnkiConnectError extends Error {
    constructor(kind, message) {
        super(message)
        this.name = 'AnkiConnectError'
        // 'network' = fetch rejection (CORS/refused), HTTP-level or non-JSON
        // answers; 'anki' = AnkiConnect's own error string inside HTTP 200.
        this.kind = kind
    }
}

export function ankiConnectEndpoint({url = ANKI_DEFAULT_URL, port = ANKI_DEFAULT_PORT} = {}) {
    const base = String(url ?? ANKI_DEFAULT_URL).trim().replace(/\/+$/, '')
    const portNumber = Number(port) || ANKI_DEFAULT_PORT
    return `${base}:${portNumber}`
}

export async function invoke(action, params, config) {
    let response
    try {
        response = await fetch(ankiConnectEndpoint(config), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action, version: 6, ...(params ? {params} : {})}),
        })
    } catch {
        throw new AnkiConnectError(
            'network',
            `Could not reach AnkiConnect at ${ankiConnectEndpoint(config)}`,
        )
    }

    if (!response.ok) {
        throw new AnkiConnectError('network', `AnkiConnect answered HTTP ${response.status}`)
    }

    const body = await response.json().catch(() => null)
    if (body === null || typeof body !== 'object') {
        throw new AnkiConnectError('network', 'AnkiConnect answer was not JSON')
    }
    if (body.error) {
        throw new AnkiConnectError('anki', String(body.error))
    }
    return body.result
}

export function ankiConnectVersion(config) {
    return invoke('version', null, config)
}

export function requestPermission(config) {
    return invoke('requestPermission', null, config)
}
