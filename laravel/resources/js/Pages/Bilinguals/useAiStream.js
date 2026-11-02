import {useCallback, useRef, useState} from 'react';
import {useI18n} from '../../i18n';
import {getCsrfToken, responseErrorMessage} from '../../lib/http';
import {parseSseEvent, splitSseBuffer} from '../../lib/sseStream.mjs';
import {answerLanguagesFromPayload} from '../../lib/aiAnswerTokens.mjs';
import {renderMarkdown} from '../../lib/markdown';

// The AI answer engine: POST /ai/question/stream, accumulate the SSE deltas
// into markdown, re-render throttled (~20 renders/second at most), and keep
// the last payload for Ask again. Owns aiPending alone — page loads no
// longer block asking and streaming no longer blocks page turns.

// The AI answer renders word tokens (ai-answer-words 01): every word becomes
// a Ctrl+clickable span like the text columns'. Only this engine opts in —
// every other renderMarkdown consumer (the word popup's explanation pane)
// keeps the plain rendering.
const RENDER_OPTIONS = {wordTokens: true};
export function useAiStream() {
    const {t} = useI18n();
    const [aiAnswer, setAiAnswer] = useState('');
    const [aiError, setAiError] = useState(null);
    const [aiPending, setAiPending] = useState(false);
    // The displayed answer's language codes (ai-answer-words ticket 03):
    // snapshotted from the ask payload the moment a stream starts, so the
    // answer's tokens resolve against the ask that produced it — a side
    // flip afterwards changes the columns, never the resolution. A retry
    // re-captures: Ask again streams the stored payload through the same
    // call below. The derivation itself is pure lib code
    // (aiAnswerTokens.mjs, behaviorally tested there); what the hook owns
    // is only the when (at stream start) and the storing. Null until the
    // first ask — with no answer, no popup can open anyway.
    const [answerLanguages, setAnswerLanguages] = useState(null);
    const lastAskPayloadRef = useRef(null);

    const streamAsk = useCallback(async (payload) => {
        lastAskPayloadRef.current = payload;
        setAnswerLanguages(answerLanguagesFromPayload(payload));
        setAiPending(true);
        setAiError(null);
        setAiAnswer('');
        let markdown = '';
        let lastRender = 0;
        try {
            const token = getCsrfToken();
            const res = await fetch('/ai/question/stream', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                    ...(token ? {'X-CSRF-TOKEN': token} : {}),
                },
                body: JSON.stringify(payload),
            });

            if (!res.ok) {
                const json = await res.json().catch(() => null);
                throw new Error(responseErrorMessage(json, res.status, t('bilinguals.request_failed', {status: res.status})));
            }

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const {done, value} = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, {stream: true});
                const {events, remainder} = splitSseBuffer(buffer);
                buffer = remainder;

                for (const event of events) {
                    const parsed = parseSseEvent(event);
                    if (parsed.kind === 'error') {
                        throw new Error(parsed.error);
                    }
                    if (parsed.kind !== 'text') {
                        continue;
                    }
                    markdown += parsed.text;
                    const now = Date.now();
                    if (now - lastRender > 50) {
                        lastRender = now;
                        setAiAnswer(renderMarkdown(markdown, RENDER_OPTIONS));
                    }
                }
            }
            setAiAnswer(renderMarkdown(markdown, RENDER_OPTIONS));
        } catch (e) {
            // Keep whatever streamed before the failure on screen.
            if (markdown) setAiAnswer(renderMarkdown(markdown, RENDER_OPTIONS));
            setAiError(e instanceof Error ? e.message : t('bilinguals.couldnt_reach_model'));
        } finally {
            setAiPending(false);
        }
    }, [t]);

    const retryAsk = useCallback(async (overrides = {}) => {
        const last = lastAskPayloadRef.current;
        if (!last || aiPending) {
            return;
        }
        await streamAsk({...last, ...(overrides.tasks ? {tasks: overrides.tasks} : {})});
    }, [aiPending, streamAsk]);

    return {aiAnswer, aiError, aiPending, answerLanguages, streamAsk, retryAsk};
}
