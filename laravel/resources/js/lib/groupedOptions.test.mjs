import {describe, expect, it} from 'vitest';
import {filterGroups, flattenGroups} from './groupedOptions.mjs';

const groups = [
    {
        id: 1,
        label: 'Alice in Wonderland — Lewis Carroll',
        options: [
            {id: 11, text: 'Alice EN / Alice RU'},
            {id: 12, text: 'Alice 2nd ed. EN / Alice RU'},
        ],
    },
    {
        id: 2,
        label: 'War and Peace — Leo Tolstoy',
        options: [{id: 21, text: 'War EN / War RU'}],
    },
];

describe('filterGroups', () => {
    it('returns the groups untouched for a blank needle', () => {
        expect(filterGroups(groups, '')).toBe(groups);
        expect(filterGroups(groups, '   ')).toBe(groups);
    });

    it('keeps only the options whose label matches', () => {
        const filtered = filterGroups(groups, '2nd');
        expect(filtered).toHaveLength(1);
        expect(filtered[0].label).toBe('Alice in Wonderland — Lewis Carroll');
        expect(filtered[0].options).toEqual([{id: 12, text: 'Alice 2nd ed. EN / Alice RU'}]);
    });

    it('keeps a whole group when its header matches', () => {
        const filtered = filterGroups(groups, 'carroll');
        expect(filtered[0].options).toEqual(groups[0].options);
    });

    it('matches case-insensitively and trims the needle', () => {
        expect(filterGroups(groups, '  TOLSTOY ')).toEqual([groups[1]]);
    });

    it('drops groups with no surviving options', () => {
        expect(filterGroups(groups, 'Alice 2nd')).toHaveLength(1);
        expect(filterGroups(groups, 'nonexistent')).toEqual([]);
    });
});

describe('flattenGroups', () => {
    it('concatenates the groups\' options in display order', () => {
        expect(flattenGroups(groups)).toEqual(groups[0].options.concat(groups[1].options));
    });

    it('returns an empty list for empty groups', () => {
        expect(flattenGroups([])).toEqual([]);
    });
});
