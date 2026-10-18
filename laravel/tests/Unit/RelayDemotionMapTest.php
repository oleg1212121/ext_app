<?php

use App\Classes\Wiktionary\RelayDemotionMap;

/**
 * Entry shape the demote command feeds the builder: verbatim dump strings,
 * normalized inside the builder.
 */
function relayed(string $word, string $base): array
{
    return ['word' => $word, 'relay' => true, 'base' => $base];
}

function worded(string $word): array
{
    return ['word' => $word, 'relay' => false, 'base' => null];
}

it('builds a normalized relay map — case folds, stress strips from keys and bases', function () {
    $map = (new RelayDemotionMap)
        ->add(relayed('Четвереньках', 'четвере'."\u{0301}".'ньки'))
        ->finalize();

    expect($map->map())->toBe(['четвереньках' => 'четвереньки'])
        ->and($map->alsoReal())->toBe(0)
        ->and($map->conflicts())->toBe(0)
        ->and($map->cycles())->toBe(0)
        ->and($map->chained())->toBe(0);
});

it('keeps a surface with any real entry a word, whatever the order', function () {
    $relayFirst = (new RelayDemotionMap)
        ->add(relayed('печь', 'пе'."\u{0301}".'чка'))
        ->add(worded('печь'))
        ->finalize();

    $realFirst = (new RelayDemotionMap)
        ->add(worded('печь'))
        ->add(relayed('печь', 'пе'."\u{0301}".'чка'))
        ->finalize();

    expect($relayFirst->map())->toBe([])
        ->and($realFirst->map())->toBe([])
        ->and($relayFirst->alsoReal())->toBe(1)
        ->and($realFirst->alsoReal())->toBe(1);
});

it('keeps a surface whose relay lines disagree on the base a word', function () {
    $map = (new RelayDemotionMap)
        ->add(relayed('saw', 'see'))
        ->add(relayed('saw', 'saw (tool)'))
        ->finalize();

    expect($map->map())->toBe([])
        ->and($map->conflicts())->toBe(1);
});

it('closes relay chains onto the terminal base', function () {
    $map = (new RelayDemotionMap)
        ->add(relayed('лучшему', 'лу'."\u{0301}".'чше'))
        ->add(relayed('лучше', 'хоро'."\u{0301}".'ший'))
        ->finalize();

    expect($map->map())->toBe([
        'лучшему' => 'хороший',
        'лучше' => 'хороший',
    ])
        ->and($map->chained())->toBe(1)
        ->and($map->cycles())->toBe(0);
});

it('keeps a two-entry relay cycle a word and lets an outsider point at it', function () {
    $map = (new RelayDemotionMap)
        ->add(relayed('a', 'b'))
        ->add(relayed('b', 'a'))
        ->add(relayed('x', 'a'))
        ->finalize();

    expect($map->map())->toBe(['x' => 'a'])
        ->and($map->cycles())->toBe(1);
});

it('keeps a three-entry relay cycle words', function () {
    $map = (new RelayDemotionMap)
        ->add(relayed('a', 'b'))
        ->add(relayed('b', 'c'))
        ->add(relayed('c', 'a'))
        ->finalize();

    expect($map->map())->toBe([])
        ->and($map->cycles())->toBe(1);
});

it('never demotes a relay onto its own normalized key', function () {
    $map = (new RelayDemotionMap)
        ->add(relayed('X', 'x'))
        ->finalize();

    expect($map->map())->toBe([])
        ->and($map->conflicts())->toBe(0)
        ->and($map->alsoReal())->toBe(0);
});

it('keeps a relay with no usable base in the map for the DB pass to decide', function () {
    // The base word existing in the dump is not the builder's business: the
    // command resolves it against the database and keeps the relay word when
    // its base never made it there.
    $map = (new RelayDemotionMap)
        ->add(relayed('баглонье', 'баглоно'."\u{0301}"))
        ->finalize();

    expect($map->map())->toBe(['баглонье' => 'баглоно']);
});
