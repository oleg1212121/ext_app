<?php

use App\Models\Definition;
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
        ->assertJsonPath('data.definitions.0', 'Домашнее животное семейства кошачьих.')
        ->assertJsonPath('data.transcriptions.0.value', 'kot')
        ->assertJsonPath('data.transcriptions.0.type', 'МФА');
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
        ->assertJsonPath('data.translations.0.word', 'кот')
        ->assertJsonPath('data.translations.0.language_code', 'ru');
});

it('marks a word known for the current user', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();

    $this->actingAs($user)
        ->patchJson(route('words.progress.update', ['word' => $word->id]), ['status' => 'known'])
        ->assertOk()
        ->assertJsonPath('data.status', 'known');

    $this->assertDatabaseHas('user_word', [
        'user_id' => $user->id,
        'word_id' => $word->id,
        'status' => 'known',
    ]);
});

it('overwrites an existing progress mark when marking known', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();
    $user->userWords()->create(['word_id' => $word->id, 'status' => 'learning']);
    expect($user->userWords()->count())->toBe(1);
    $this->actingAs($user)
        ->patchJson(route('words.progress.update', ['word' => $word->id]), ['status' => 'known'])
        ->assertOk();

    $this->assertDatabaseHas('user_word', [
        'user_id' => $user->id,
        'word_id' => $word->id,
        'status' => 'known',
    ]);
});

it('rejects an invalid progress status', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();

    $this->actingAs($user)
        ->patchJson(route('words.progress.update', ['word' => $word->id]), ['status' => 'banana'])
        ->assertJsonValidationErrors(['status']);

    $this->assertDatabaseMissing('user_word', ['user_id' => $user->id]);
});

it('removes the progress mark', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();
    $user->userWords()->create(['word_id' => $word->id, 'status' => 'known']);

    $this->actingAs($user)
        ->deleteJson(route('words.progress.reset', ['word' => $word->id]))
        ->assertOk()
        ->assertJsonPath('data.status', null);

    $this->assertDatabaseMissing('user_word', [
        'user_id' => $user->id,
        'word_id' => $word->id,
    ]);
});

it('requires authentication', function () {
    $word = createWordWithDetails();

    $this->getJson(route('words.show', ['word' => $word->id]))->assertUnauthorized();
    $this->patchJson(route('words.progress.update', ['word' => $word->id]), ['status' => 'known'])->assertUnauthorized();
    $this->deleteJson(route('words.progress.reset', ['word' => $word->id]))->assertUnauthorized();
});
