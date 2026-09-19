<?php

use App\Models\Definition;
use App\Models\Etymology;
use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\User;
use App\Models\Word;
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
