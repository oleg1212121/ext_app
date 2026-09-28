<?php

use App\Models\Definition;
use App\Models\Etymology;
use App\Models\Form;
use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\User;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createWordWithDetails(): Word
{
    createWordClasses();
    $word = createWord('ru', 'кот', 'noun');
    $ruLanguageId = Language::query()->where('code', 'ru')->value('id');

    Definition::query()->create(['word_id' => $word->id, 'definition' => 'Домашнее животное семейства кошачьих.']);
    Etymology::query()->create(['word_id' => $word->id, 'etymology' => 'От праслав. *kotь.']);
    $ipa = TranscriptionType::query()->create(['language_id' => $ruLanguageId, 'slug' => 'ipa', 'title' => 'МФА']);
    Transcription::query()->create([
        'word_id' => $word->id,
        'transcription_type_id' => $ipa->id,
        'transcription' => 'kot',
    ]);

    return $word;
}

it('returns dictionary details for a word', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id]))
        ->assertOk()
        ->assertJsonPath('data.word', 'кот')
        ->assertJsonPath('data.language_code', 'ru')
        ->assertJsonPath('data.word_class', 'Существительное')
        ->assertJsonPath('data.is_form', false)
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.word_class', 'Существительное')
        ->assertJsonPath('data.entries.0.definitions.0', 'Домашнее животное семейства кошачьих.')
        ->assertJsonPath('data.entries.0.transcriptions.0.value', 'kot')
        ->assertJsonPath('data.entries.0.transcriptions.0.type', 'МФА')
        ->assertJsonPath('data.entries.0.etymologies.0', 'От праслав. *kotь.');
});

it('returns one entry per part of speech of the headword, linked word first', function () {
    $user = User::factory()->create();
    createWordClasses();
    $noun = createWord('en', 'bank', 'noun');
    $verb = createWord('en', 'bank', 'verb');
    Definition::query()->create(['word_id' => $noun->id, 'definition' => 'A financial institution.']);
    Definition::query()->create(['word_id' => $verb->id, 'definition' => 'To rely on.']);

    // The verb is the linked row: it must lead even though the noun outranks it.
    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $verb->id]))
        ->assertOk()
        ->assertJsonPath('data.id', $verb->id)
        ->assertJsonPath('data.word', 'bank')
        ->assertJsonCount(2, 'data.entries')
        ->assertJsonPath('data.entries.0.id', $verb->id)
        ->assertJsonPath('data.entries.0.word_class', 'Verb')
        ->assertJsonPath('data.entries.0.definitions.0', 'To rely on.')
        ->assertJsonPath('data.entries.1.id', $noun->id)
        ->assertJsonPath('data.entries.1.word_class', 'Noun')
        ->assertJsonPath('data.entries.1.definitions.0', 'A financial institution.');
});

it('flags a surface form different from the dictionary lemma', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id, 'surface' => 'коту']))
        ->assertOk()
        ->assertJsonPath('data.is_form', true);
});

it('exposes the headword frequency rank', function () {
    $user = User::factory()->create();
    createWordClasses();
    $word = createWord('en', 'melt', 'verb', ['frequency' => 2143]);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id]))
        ->assertOk()
        ->assertJsonPath('data.frequency', 2143);
});

it('hides the frequency rank for unranked words', function () {
    $user = User::factory()->create();
    createWordClasses();
    $neverRanked = createWord('en', 'melt', 'verb');
    $sentinelRanked = createWord('en', 'zarf', 'noun', ['frequency' => Word::FREQUENCY_UNRANKED]);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $neverRanked->id]))
        ->assertOk()
        ->assertJsonPath('data.frequency', null);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $sentinelRanked->id]))
        ->assertOk()
        ->assertJsonPath('data.frequency', null);
});

it('merges base-word entries of the surface form and hides the relay entry', function () {
    $user = User::factory()->create();
    createWordClasses();

    $meltVerb = createWord('en', 'melt', 'verb', ['frequency' => 900]);
    Definition::query()->create(['word_id' => $meltVerb->id, 'definition' => 'To change from solid to liquid state.']);
    // A base-headword sibling that does not claim the surface form: it must
    // not ride along with the claiming class.
    $meltNoun = createWord('en', 'melt', 'noun', ['frequency' => 5000]);
    Definition::query()->create(['word_id' => $meltNoun->id, 'definition' => 'Molten material.']);
    Form::query()->create(['word_id' => $meltVerb->id, 'form' => 'melted', 'l_word' => 'melted']);

    $meltedVerb = createWord('en', 'melted', 'verb', ['frequency' => 8000]);
    Definition::query()->create(['word_id' => $meltedVerb->id, 'definition' => 'simple past and past participle of melt']);
    $meltedAdj = createWord('en', 'melted', 'unknown', ['frequency' => 8100]);
    Definition::query()->create(['word_id' => $meltedAdj->id, 'definition' => 'Being in a liquid state as a result of melting.']);

    $response = $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $meltedVerb->id, 'surface' => 'melted']))
        ->assertOk()
        ->assertJsonPath('data.form_of', ['melt'])
        ->assertJsonCount(2, 'data.entries')
        // Own group keeps the real melted entry; the relay entry is hidden.
        ->assertJsonPath('data.entries.0.id', $meltedAdj->id)
        ->assertJsonPath('data.entries.0.word', 'melted')
        // The base group is scoped to the claiming class (melt/verb).
        ->assertJsonPath('data.entries.1.id', $meltVerb->id)
        ->assertJsonPath('data.entries.1.word', 'melt')
        ->assertJsonPath('data.entries.1.definitions.0', 'To change from solid to liquid state.');

    expect(collect($response->json('data.entries'))->pluck('id'))->not->toContain($meltNoun->id);
});

it('scopes base groups to the claiming classes so unrelated same-spelling entries stay hidden', function () {
    $user = User::factory()->create();
    createWordClasses();
    $languageId = Language::query()->where('code', 'en')->value('id');
    foreach (['pronoun' => 'Pronoun', 'character' => 'Character', 'numeral' => 'Numeral'] as $slug => $title) {
        WordClass::query()->create(['language_id' => $languageId, 'slug' => $slug, 'title' => $title]);
    }

    $iClasses = ['pronoun' => 12, 'character' => 13, 'numeral' => 14];
    $iWords = [];
    foreach ($iClasses as $slug => $frequency) {
        $iWords[$slug] = Word::query()->create([
            'word' => 'I',
            'l_word' => 'i',
            'language_id' => $languageId,
            'word_class_id' => WordClass::query()->where('language_id', $languageId)->where('slug', $slug)->value('id'),
            'frequency' => $frequency,
        ]);
    }
    Definition::query()->create(['word_id' => $iWords['pronoun']->id, 'definition' => 'The speaker or writer, referred to as the subject.']);
    Definition::query()->create(['word_id' => $iWords['character']->id, 'definition' => 'The ninth letter of the Latin alphabet.']);
    Definition::query()->create(['word_id' => $iWords['numeral']->id, 'definition' => 'The Roman numeral for one.']);
    // Only the pronoun lists "me" among its forms.
    Form::query()->create(['word_id' => $iWords['pronoun']->id, 'form' => 'me', 'l_word' => 'me']);

    $me = Word::query()->create([
        'word' => 'me',
        'l_word' => 'me',
        'language_id' => $languageId,
        'word_class_id' => $iWords['pronoun']->word_class_id,
        'frequency' => 40,
    ]);
    Definition::query()->create(['word_id' => $me->id, 'definition' => 'The speaker or writer as the object of a verb.']);

    $response = $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $me->id, 'surface' => 'me']))
        ->assertOk()
        ->assertJsonPath('data.form_of', ['I'])
        ->assertJsonCount(2, 'data.entries')
        ->assertJsonPath('data.entries.0.id', $me->id)
        ->assertJsonPath('data.entries.1.id', $iWords['pronoun']->id)
        ->assertJsonPath('data.entries.1.word', 'I');

    $entryIds = collect($response->json('data.entries'))->pluck('id');
    expect($entryIds)->not->toContain($iWords['character']->id)
        ->and($entryIds)->not->toContain($iWords['numeral']->id);
});

it('filters relay glosses out of partially-relay entries', function () {
    $user = User::factory()->create();
    createWordClasses();

    // Kaikki merges form-of lines into the real entry: real saw senses
    // alongside "simple past of see".
    $sawVerb = createWord('en', 'saw', 'verb');
    Definition::query()->create(['word_id' => $sawVerb->id, 'definition' => 'To cut (something) with a saw.']);
    Definition::query()->create(['word_id' => $sawVerb->id, 'definition' => 'simple past of see']);
    Definition::query()->create(['word_id' => $sawVerb->id, 'definition' => '(colloquial, nonstandard) past participle of see']);
    Form::query()->create(['word_id' => $sawVerb->id, 'form' => 'sawn', 'l_word' => 'sawn']);

    $sawn = createWord('en', 'sawn', 'unknown');

    $response = $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $sawn->id, 'surface' => 'sawn']))
        ->assertOk()
        ->assertJsonPath('data.form_of', ['saw']);

    $sawEntry = collect($response->json('data.entries'))->firstWhere('id', $sawVerb->id);
    expect($sawEntry)->not->toBeNull()
        ->and($sawEntry['definitions'])->toBe(['To cut (something) with a saw.']);
});

it('keeps a relay-only entry when the forms table offers no base word', function () {
    $user = User::factory()->create();
    createWordClasses();

    $meltedVerb = createWord('en', 'melted', 'verb');
    Definition::query()->create(['word_id' => $meltedVerb->id, 'definition' => 'simple past and past participle of melt']);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $meltedVerb->id, 'surface' => 'melted']))
        ->assertOk()
        ->assertJsonPath('data.form_of', [])
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.definitions.0', 'simple past and past participle of melt');
});

it('does not duplicate the own headword when the forms table lists it as a form', function () {
    $user = User::factory()->create();
    createWordClasses();

    $meltVerb = createWord('en', 'melt', 'verb');
    Definition::query()->create(['word_id' => $meltVerb->id, 'definition' => 'To change from solid to liquid state.']);
    Form::query()->create(['word_id' => $meltVerb->id, 'form' => 'melts', 'l_word' => 'melts']);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $meltVerb->id, 'surface' => 'melts']))
        ->assertOk()
        ->assertJsonPath('data.form_of', [])
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.is_form', true);
});

it('orders base-word groups by frequency without a cap', function () {
    $user = User::factory()->create();
    createWordClasses();

    $leftAdj = createWord('en', 'left', 'unknown', ['frequency' => 400]);
    Definition::query()->create(['word_id' => $leftAdj->id, 'definition' => 'Positioned on the left side.']);

    $leaveVerb = createWord('en', 'leave', 'verb', ['frequency' => 100]);
    Definition::query()->create(['word_id' => $leaveVerb->id, 'definition' => 'To depart from.']);
    $leaveNoun = createWord('en', 'leave', 'noun', ['frequency' => 9000]);
    Definition::query()->create(['word_id' => $leaveNoun->id, 'definition' => 'Permission to be absent.']);
    $liftVerb = createWord('en', 'lift', 'verb', ['frequency' => 3000]);
    Definition::query()->create(['word_id' => $liftVerb->id, 'definition' => 'To raise.']);

    Form::query()->create(['word_id' => $leaveVerb->id, 'form' => 'left', 'l_word' => 'left']);
    Form::query()->create(['word_id' => $leaveNoun->id, 'form' => 'left', 'l_word' => 'left']);
    Form::query()->create(['word_id' => $liftVerb->id, 'form' => 'left', 'l_word' => 'left']);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $leftAdj->id, 'surface' => 'left']))
        ->assertOk()
        ->assertJsonPath('data.form_of', ['leave', 'lift'])
        ->assertJsonCount(4, 'data.entries')
        ->assertJsonPath('data.entries.0.id', $leftAdj->id)
        ->assertJsonPath('data.entries.1.id', $leaveNoun->id)
        ->assertJsonPath('data.entries.2.id', $leaveVerb->id)
        ->assertJsonPath('data.entries.3.id', $liftVerb->id);
});

it('finds base words through a surface carrying combining marks', function () {
    $user = User::factory()->create();
    createWordClasses();

    $kot = createWord('ru', 'кот', 'noun');
    Definition::query()->create(['word_id' => $kot->id, 'definition' => 'Домашнее животное семейства кошачьих.']);
    Form::query()->create(['word_id' => $kot->id, 'form' => 'кота', 'l_word' => 'кота']);

    $kota = createWord('ru', 'кота', 'unknown');
    Definition::query()->create(['word_id' => $kota->id, 'definition' => 'родительный падеж от кот']);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $kota->id, 'surface' => 'ко́та']))
        ->assertOk()
        ->assertJsonPath('data.form_of', ['кот']);
});

it('sorts translations native language first', function () {
    createWordClasses();
    $user = User::factory()->create();
    $user->settings()->updateOrCreate([], [
        'native_language_id' => Language::query()->where('code', 'ru')->value('id'),
    ]);

    $enWord = createWord('en', 'cat', 'noun');
    $ruWord = createWord('ru', 'кот', 'noun');
    $deWord = createWord('en', 'tomcat', 'noun');
    WordTranslation::link($enWord->id, $ruWord->id);
    WordTranslation::link($enWord->id, $deWord->id);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $enWord->id]))
        ->assertOk()
        ->assertJsonPath('data.entries.0.translations.0.word', 'кот')
        ->assertJsonPath('data.entries.0.translations.0.language_code', 'ru');
});

it('sets a word known (familiarity 100) for the current user', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();

    $this->actingAs($user)
        ->patchJson(route('words.progress.update', ['word' => $word->id]), ['familiarity' => 100])
        ->assertOk()
        ->assertJsonPath('data.familiarity', 100);

    $this->assertDatabaseHas('user_word', [
        'user_id' => $user->id,
        'word_id' => $word->id,
        'familiarity' => 100,
    ]);
});

it('overwrites an existing progress mark when setting familiarity', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();
    $user->userWords()->create(['word_id' => $word->id, 'familiarity' => 7]);
    expect($user->userWords()->count())->toBe(1);
    $this->actingAs($user)
        ->patchJson(route('words.progress.update', ['word' => $word->id]), ['familiarity' => 100])
        ->assertOk();

    $this->assertDatabaseHas('user_word', [
        'user_id' => $user->id,
        'word_id' => $word->id,
        'familiarity' => 100,
    ]);
});

it('rejects an out-of-range familiarity value', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();

    $this->actingAs($user)
        ->patchJson(route('words.progress.update', ['word' => $word->id]), ['familiarity' => 101])
        ->assertJsonValidationErrors(['familiarity']);

    $this->actingAs($user)
        ->patchJson(route('words.progress.update', ['word' => $word->id]), ['familiarity' => -1])
        ->assertJsonValidationErrors(['familiarity']);

    $this->actingAs($user)
        ->patchJson(route('words.progress.update', ['word' => $word->id]), ['familiarity' => 'banana'])
        ->assertJsonValidationErrors(['familiarity']);

    $this->assertDatabaseMissing('user_word', ['user_id' => $user->id]);
});

it('removes the progress mark', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();
    $user->userWords()->create(['word_id' => $word->id, 'familiarity' => 100]);

    $this->actingAs($user)
        ->deleteJson(route('words.progress.reset', ['word' => $word->id]))
        ->assertOk()
        ->assertJsonPath('data.familiarity', null);

    $this->assertDatabaseMissing('user_word', [
        'user_id' => $user->id,
        'word_id' => $word->id,
    ]);
});

it('requires authentication', function () {
    $word = createWordWithDetails();

    $this->getJson(route('words.show', ['word' => $word->id]))->assertUnauthorized();
    $this->patchJson(route('words.progress.update', ['word' => $word->id]), ['familiarity' => 100])->assertUnauthorized();
    $this->deleteJson(route('words.progress.reset', ['word' => $word->id]))->assertUnauthorized();
    $this->postJson(route('word.events.store'), ['events' => []])->assertUnauthorized();
});
