// Keys must carry the same normalization as App\Support\WordLookupKey
// (trim → lowercase → mark strip → \p{Cf} strip → ё→е fold): the dictionary's
// l_word values are folded, so a ёлка token must look the елка entry up. The
// PHP↔JS cross-check lives in tests/Unit/TokenizerParityTest.php; this pins
// the JS side alone so vitest catches drift without a PHP run.
import {describe, expect, it} from 'vitest';

import {segmentText} from './wordTokenizer.mjs';

describe('segmentText keys', () => {
    it('folds ё/Ё into the key while keeping the display text', () => {
        const segments = segmentText('Ёлка ёлка.');

        expect(segments.map(({text, key}) => ({text, key}))).toEqual([
            {text: 'Ёлка', key: 'елка'},
            {text: ' ', key: null},
            {text: 'ёлка', key: 'елка'},
            {text: '.', key: null},
        ]);
    });

    it('strips combining marks and zero-width format characters from keys', () => {
        const segments = segmentText('поёт ещё́, ел\u{00AD}ка');

        expect(segments.filter(({key}) => key !== null).map(({key}) => key)).toEqual([
            'поет',
            'еще',
            'ел',
            'ка',
        ]);
    });
});
