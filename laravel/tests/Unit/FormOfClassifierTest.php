<?php

use App\Classes\Wiktionary\FormOfClassifier;

/**
 * The real dump line for четвереньках (kaikki.org-dictionary-Russian.jsonl):
 * a prepositional-case relay whose single sense carries form_of + the
 * form-of tag, pointing at the stressed base четвере́ньки.
 */
function relayEntry(): object
{
    $base = 'четвере'."\u{0301}".'ньки';

    return json_decode('{"word":"четвереньках","pos":"noun","head_templates":[{"args":{"head":"четвере'."\u{0301}".'ньках"}}],'
        .'"senses":[{"glosses":["prepositional of '.$base.' (četverénʹki)"],"tags":["form-of","prepositional"],'
        .'"form_of":[{"word":"'.$base.'","extra":"četverénʹki"}]}]}');
}

it('classifies a kaikki relay entry whose every sense carries form_of', function () {
    expect(FormOfClassifier::classify(relayEntry()))->toBe([
        'relay' => true,
        'base' => 'четвере'."\u{0301}".'ньки',
    ]);
});

it('classifies an english inflected-form entry as a relay', function () {
    $entry = json_decode('{"word":"cats","pos":"noun","senses":[{"glosses":["plural of cat"],'
        .'"tags":["form-of","plural"],"form_of":[{"word":"cat"}]}]}');

    expect(FormOfClassifier::classify($entry))->toBe(['relay' => true, 'base' => 'cat']);
});

it('keeps a real entry with plain glosses a word', function () {
    $entry = json_decode('{"word":"cat","pos":"noun","senses":[{"glosses":["A small domesticated feline"]}]}');

    expect(FormOfClassifier::classify($entry))->toBe(['relay' => false, 'base' => null]);
});

it('keeps a mixed entry (one relay sense + one real gloss) a word', function () {
    $entry = json_decode('{"word":"run","pos":"noun","senses":['
        .'{"tags":["form-of"],"form_of":[{"word":"run"}]},'
        .'{"glosses":["An act of running"]}]}');

    expect(FormOfClassifier::classify($entry))->toBe(['relay' => false, 'base' => null]);
});

it('keeps an entry without senses a word', function () {
    // No senses means no sense-level signal: nothing to classify on.
    $entry = json_decode('{"word":"кот","pos":"noun","forms":[{"form":"кота"}]}');

    expect(FormOfClassifier::classify($entry))->toBe(['relay' => false, 'base' => null]);
});

it('refuses to demote when the form-of senses carry no base word', function () {
    // Every sense is tagged form-of but none carries a form_of word: there
    // is no base to attach to, so the entry stays a word.
    $entry = json_decode('{"word":"reran","pos":"verb","senses":[{"glosses":["past of rerun"],"tags":["form-of"]}]}');

    expect(FormOfClassifier::classify($entry))->toBe(['relay' => false, 'base' => null]);
});

it('honors a trivially present entry-level form_of', function () {
    // Essentially absent in both dumps; handled when a dump emits it.
    $entry = json_decode('{"word":"cats","pos":"noun","form_of":[{"word":"cat"}],"senses":[{"glosses":["plural of cat"]}]}');

    expect(FormOfClassifier::classify($entry))->toBe(['relay' => true, 'base' => 'cat']);
});

it('treats a form_of list without a usable word as not carrying form_of', function () {
    $entry = json_decode('{"word":"run","pos":"noun","senses":['
        .'{"tags":["form-of"],"form_of":[]},'
        .'{"tags":["form-of"],"form_of":[{"extra":"no word key"}]},'
        .'{"glosses":["An act of running"]}]}');

    expect(FormOfClassifier::classify($entry))->toBe(['relay' => false, 'base' => null]);
});

it('refuses a self-referential relay whose base is the entry itself', function () {
    // The stressed dump base collapses into the same lookup key as the
    // plain entry word: demoting would recreate the very word row.
    $entry = json_decode('{"word":"дом","pos":"noun","senses":[{"tags":["form-of"],'
        .'"form_of":[{"word":"До'."\u{0301}".'м"}]}]}');

    expect(FormOfClassifier::classify($entry))->toBe(['relay' => false, 'base' => null]);
});

it('takes the base from the first sense that carries one', function () {
    $entry = json_decode('{"word":"cats","pos":"noun","senses":['
        .'{"tags":["form-of"],"form_of":[{"word":"cat"}]},'
        .'{"tags":["form-of"],"form_of":[{"word":"cats"}]}]}');

    expect(FormOfClassifier::classify($entry))->toBe(['relay' => true, 'base' => 'cat']);
});
