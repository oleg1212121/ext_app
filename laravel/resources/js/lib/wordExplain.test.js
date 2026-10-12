import {describe, expect, it} from 'vitest';
import {canAskExplanation} from './wordExplain.js';

// The payload WordText ships per column; only enabled/modelKey matter here.
const askable = {enabled: true, modelKey: 7, sentenceId: 42};

describe('canAskExplanation', () => {
    it('asks for an enabled payload with a chosen model', () => {
        expect(canAskExplanation(askable)).toBe(true);
    });

    it('treats an absent enabled flag as enabled', () => {
        expect(canAskExplanation({modelKey: 7})).toBe(true);
    });

    it('never asks without an API key (enabled false)', () => {
        expect(canAskExplanation({...askable, enabled: false})).toBe(false);
    });

    it('never asks without a chosen explanation model', () => {
        expect(canAskExplanation({...askable, modelKey: null})).toBe(false);
        expect(canAskExplanation({enabled: true, modelKey: undefined})).toBe(false);
    });

    it('treats a falsy-but-set model key as chosen', () => {
        expect(canAskExplanation({...askable, modelKey: 0})).toBe(true);
    });
});
