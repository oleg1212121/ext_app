import {describe, expect, it} from 'vitest';
import {parseSseEvent, splitSseBuffer} from './sseStream.mjs';

describe('splitSseBuffer', () => {
    it('splits complete events and keeps the partial tail in the buffer', () => {
        const {events, remainder} = splitSseBuffer('data:{"text":"a"}\n\ndata:{"text":"b"}\n\ndata:{"te');
        expect(events).toEqual(['data:{"text":"a"}', 'data:{"text":"b"}']);
        expect(remainder).toBe('data:{"te');
    });

    it('returns the whole buffer as remainder when no event boundary arrived', () => {
        const {events, remainder} = splitSseBuffer('data:{"text":"a"}');
        expect(events).toEqual([]);
        expect(remainder).toBe('data:{"text":"a"}');
    });

    it('keeps a trailing empty remainder for a buffer ending on a boundary', () => {
        const {events, remainder} = splitSseBuffer('data:{"text":"a"}\n\n');
        expect(events).toEqual(['data:{"text":"a"}']);
        expect(remainder).toBe('');
    });
});

describe('parseSseEvent', () => {
    it('parses a text delta', () => {
        expect(parseSseEvent('data:{"text":"hello"}')).toEqual({kind: 'text', text: 'hello'});
    });

    it('surfaces a server error payload', () => {
        expect(parseSseEvent('data:{"error":"model exploded"}')).toEqual({kind: 'error', error: 'model exploded'});
    });

    it('ignores the [DONE] sentinel', () => {
        expect(parseSseEvent('data:[DONE]')).toEqual({kind: 'ignore'});
    });

    it('ignores lines without the data: prefix', () => {
        expect(parseSseEvent('event: ping')).toEqual({kind: 'ignore'});
        expect(parseSseEvent('')).toEqual({kind: 'ignore'});
    });

    it('ignores unparsable JSON (partial payload tolerance)', () => {
        expect(parseSseEvent('data:{"text":')).toEqual({kind: 'ignore'});
    });

    it('ignores data payloads without text or error', () => {
        expect(parseSseEvent('data:{"done":true}')).toEqual({kind: 'ignore'});
        expect(parseSseEvent('data:{"text":""}')).toEqual({kind: 'ignore'});
    });

    it('reports the error kind before any text field', () => {
        expect(parseSseEvent('data:{"error":"x","text":"y"}')).toEqual({kind: 'error', error: 'x'});
    });
});
