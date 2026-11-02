import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// ai-answer-words ticket 03 — the popup on an AI-answer token must resolve
// against the language codes of the ask that produced the DISPLAYED answer:
// a side flip after the ask changes the columns, never the resolution, and
// a retry (Ask again) re-captures from the stored payload. Hooks sit
// outside vitest's behavioral reach (ADR 0066, no React rendering in
// Node), so the snapshot decision is pinned as source assertions; the
// ordered-list derivation it feeds is behaviorally tested in
// lib/aiAnswerTokens.test.mjs.

const hookSource = readFileSync(new URL('./useAiStream.js', import.meta.url), 'utf8');

// streamAsk: from its opener to the fetch — where the snapshot must sit.
const streamAskAt = hookSource.indexOf('const streamAsk = useCallback');
const streamAskHead = hookSource.slice(streamAskAt, hookSource.indexOf("fetch('/ai/question/stream'", streamAskAt));

// retryAsk: the stored-payload re-run.
const retryAskSource = hookSource.slice(
    hookSource.indexOf('const retryAsk = useCallback'),
    hookSource.indexOf('return {'),
);

describe('useAiStream: the displayed answer’s language snapshot (ticket 03)', () => {
    it('starts null — before the first ask there is no answer and no popup can open', () => {
        expect(hookSource).toContain('const [answerLanguages, setAnswerLanguages] = useState(null);');
    });

    it('snapshots the ask payload’s codes the moment a stream starts', () => {
        // Inside streamAsk, BEFORE the fetch: the payload is the ask's own
        // record (buildAskPayload carried base/learning); the copy — not a
        // reference to the payload object — is what later asks can never
        // mutate retroactively.
        expect(streamAskHead).toContain('setAnswerLanguages({learning: payload.learning ?? null, base: payload.base ?? null});');
        const snapshotAt = streamAskHead.indexOf('setAnswerLanguages(');
        const storedAt = streamAskHead.indexOf('lastAskPayloadRef.current = payload;');
        expect(snapshotAt).toBeGreaterThan(storedAt);
        // The engine opts in to word tokens (ticket 01); the snapshot rides
        // the same engine — no other renderMarkdown consumer is involved.
        expect(hookSource).toContain('const RENDER_OPTIONS = {wordTokens: true};');
    });

    it('exposes the snapshot next to the answer it describes', () => {
        expect(hookSource).toMatch(/return \{aiAnswer, aiError, aiPending, answerLanguages, streamAsk, retryAsk\};/);
    });

    it('a retry re-captures — Ask again streams the stored payload through the same snapshot line', () => {
        // retryAsk spreads the stored payload back into streamAsk, so the
        // snapshot line runs again with the ask that produced THIS answer.
        expect(retryAskSource).toContain('await streamAsk({...last,');
    });
});
