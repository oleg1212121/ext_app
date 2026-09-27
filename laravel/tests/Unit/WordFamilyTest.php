<?php

use App\Classes\WordFamily;

it('recognises Wiktionary relay glosses', function (string $definition) {
    expect(WordFamily::isRelayGloss($definition))->toBeTrue();
})->with([
    'simple past and past participle of melt',
    'past participle of the verb melt',
    'simple past of see',
    '(colloquial, nonstandard) past participle of see',
    'present participle and gerund of melt',
    'third-person singular simple present indicative form of see',
    'plural of ghetto',
    'comparative of good',
    'Alternative form of Belarusian.',
    'Alternative spelling of Mjollnir.',
    'Alternative letter-case form of see.',
    'inflected form of run',
    'форма родительного падежа от «кот»',
    'наст. от таять',
]);

it('does not treat real definitions as relay glosses', function (string $definition) {
    expect(WordFamily::isRelayGloss($definition))->toBeFalse();
})->with([
    'A form of address, now used chiefly for an unmarried woman.',
    'Form of address for a married woman.',
    'Being in a liquid state as a result of melting.',
    'A tool with a toothed blade used for cutting hard substances, in particular wood.',
    'The transition of matter from a solid state to a liquid state.',
    'To change from solid to liquid state by heating.',
    '(UK, slang, derogatory) An idiot.',
    'Форма правления, при которой власть принадлежит народу.',
]);

it('normalises the surface lookup key like the importer normalises l_word', function () {
    expect(WordFamily::lookupKey('Ко́та'))->toBe('кота')
        ->and(WordFamily::lookupKey('  MELTED '))->toBe('melted')
        ->and(WordFamily::lookupKey(''))->toBe('');
});
