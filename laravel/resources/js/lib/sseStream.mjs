// Pure SSE parsing for the simulator's AI question stream — the wire the
// /ai/question/stream endpoint speaks: '\n\n'-separated events, each a single
// 'data:' line carrying JSON ({text} deltas, {error}, or the [DONE] sentinel).
// Framework-free (.mjs) so vitest pins it directly.

/**
 * Split a accumulated stream buffer into complete events plus the trailing
 * partial event (no '\n\n' yet) that stays in the buffer.
 */
export function splitSseBuffer(buffer) {
    const parts = buffer.split('\n\n');
    return {events: parts.slice(0, -1), remainder: parts[parts.length - 1]};
}

/**
 * Parse one SSE event into an instruction. Kinds:
 *   'text'   — a delta to append ({text: '...'})
 *   'error'  — the server signalled a failure ({error: '...'})
 *   'ignore' — not a data line, the [DONE] sentinel, or unparsable JSON
 *              (tolerated: a split JSON payload can parse midway)
 */
export function parseSseEvent(event) {
    const line = event.trim();
    if (!line.startsWith('data:')) {
        return {kind: 'ignore'};
    }
    const data = line.slice(5).trim();
    if (data === '[DONE]') {
        return {kind: 'ignore'};
    }
    let parsed;
    try {
        parsed = JSON.parse(data);
    } catch {
        return {kind: 'ignore'};
    }
    if (parsed?.error) {
        return {kind: 'error', error: parsed.error};
    }
    return parsed?.text ? {kind: 'text', text: parsed.text} : {kind: 'ignore'};
}
