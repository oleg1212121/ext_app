import {describe, expect, it} from 'vitest';
import {DIMMING_LABELS, REGISTER_LABELS, dimsDefinition, isKnownLabel, usageLabelChips} from './usageLabelDisplay';

const t = (key) => `t:${key}`;

describe('dimsDefinition — the popup\'s display dimming tier', () => {
    it('dims a sense carrying any of the four dimming labels', () => {
        for (const slug of DIMMING_LABELS) {
            expect(dimsDefinition([slug])).toBe(true);
        }
    });

    it('never dims the register tier — dated and colloquial stay full opacity', () => {
        expect(dimsDefinition(['dated'])).toBe(false);
        expect(dimsDefinition(['colloquial'])).toBe(false);
    });

    it('dims a mixed set — any dimming label present wins over muted ones', () => {
        expect(dimsDefinition(['dated', 'archaic'])).toBe(true);
        expect(dimsDefinition(['rare', 'colloquial'])).toBe(true);
    });

    it('leaves unlabelled senses alone — a null column and an empty list are both undimmed', () => {
        expect(dimsDefinition(null)).toBe(false);
        expect(dimsDefinition(undefined)).toBe(false);
        expect(dimsDefinition([])).toBe(false);
    });
});

describe('usageLabelChips — one localized chip per label', () => {
    it('resolves each label onto its word.label_* ui-string key', () => {
        expect(usageLabelChips(['obsolete'], t)).toEqual([{text: 't:word.label_obsolete', dimming: true}]);
        expect(usageLabelChips(['colloquial'], t)).toEqual([{text: 't:word.label_colloquial', dimming: false}]);
    });

    it('marks each chip with its display tier', () => {
        const dimming = usageLabelChips(DIMMING_LABELS, t);
        expect(dimming).toHaveLength(4);
        expect(dimming.every((chip) => chip.dimming)).toBe(true);

        const register = usageLabelChips(REGISTER_LABELS, t);
        expect(register).toHaveLength(2);
        expect(register.every((chip) => !chip.dimming)).toBe(true);
    });

    it('keeps the stored label order', () => {
        expect(usageLabelChips(['dated', 'archaic'], t).map((chip) => chip.text)).toEqual([
            't:word.label_dated',
            't:word.label_archaic',
        ]);
    });

    it('drops unknown slugs and tolerates null — the set is closed', () => {
        expect(usageLabelChips(['no-such-slug'], t)).toEqual([]);
        expect(usageLabelChips(['dated', 'no-such-slug'], t)).toEqual([{text: 't:word.label_dated', dimming: false}]);
        expect(usageLabelChips(null, t)).toEqual([]);
    });
});

describe('isKnownLabel — the closed-set statement both renderers share', () => {
    // The chips (popup) and the plain-text prefix (Anki notes,
    // popup-usage-labels ticket 03) must drop the same strays: one
    // predicate, two renderers.
    it('accepts exactly the six slugs across the two tiers and drops everything else', () => {
        for (const slug of [...DIMMING_LABELS, ...REGISTER_LABELS]) {
            expect(isKnownLabel(slug)).toBe(true);
        }
        expect(isKnownLabel('no-such-slug')).toBe(false);
        expect(isKnownLabel(undefined)).toBe(false);
    });
});

describe('the display tier sets', () => {
    it('deliberately exceed the completeness DEMOTING subset — rare and dialectal dim on display', () => {
        // App\Support\UsageLabels::DEMOTING is [obsolete, archaic] and stays
        // untouched: the display split is its own JS-side decision, so a
        // rare or dialectal sense dims in the popup while completeness
        // still scores it live.
        expect(DIMMING_LABELS).toEqual(['obsolete', 'archaic', 'rare', 'dialectal']);
        expect(REGISTER_LABELS).toEqual(['dated', 'colloquial']);
    });
});
