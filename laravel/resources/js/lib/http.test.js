import {describe, expect, it} from 'vitest';
import {responseErrorMessage} from './http';

describe('responseErrorMessage', () => {
    it('prefers the flat JSON error envelope', () => {
        expect(responseErrorMessage({error: 'No such match'}, 404, 'fb')).toBe('No such match');
    });

    it('falls back to the plain message field', () => {
        expect(responseErrorMessage({message: 'Server error'}, 500, 'fb')).toBe('Server error');
    });

    it('uses the caller\'s fallback last', () => {
        expect(responseErrorMessage(null, 500, 'Request failed')).toBe('Request failed');
        expect(responseErrorMessage({}, 500, 'Request failed')).toBe('Request failed');
    });
});
