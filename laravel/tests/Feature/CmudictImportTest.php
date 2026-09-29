<?php

use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\Word;

function writeCmudictFixture(array $lines): string
{
    $path = tempnam(sys_get_temp_dir(), 'cmudict');
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

function ipaTranscriptionType(): TranscriptionType
{
    $languageId = Language::query()->where('code', 'en')->firstOrFail()->id;

    return TranscriptionType::query()->firstOrCreate(
        ['language_id' => $languageId, 'slug' => 'ipa'],
        ['title' => 'IPA', 'description' => 'test'],
    );
}

it('imports new words with converted ipa and skips words that already carry ipa', function () {
    $kind = createWord('en', 'kind', 'noun');
    Transcription::query()->create([
        'word_id' => $kind->id,
        'transcription_type_id' => ipaTranscriptionType()->id,
        'transcription' => '/ˈkaɪnd/',
    ]);

    // Kaikki-style unstressed-only row: CMUdict must still add its variant.
    $turned = createWord('en', 'turned', 'verb');
    Transcription::query()->create([
        'word_id' => $turned->id,
        'transcription_type_id' => ipaTranscriptionType()->id,
        'transcription' => '/tɜːnd/',
    ]);

    // Multi-class rows for one l_word: the entity link may point at any of
    // them, so both must gain the variant (die noun vs verb).
    $dieNoun = createWord('en', 'die', 'noun');
    $dieVerb = createWord('en', 'die', 'verb');

    $file = writeCmudictFixture([
        ';;; cmudict fixture',
        'GAMBLERS G AE1 M B L ER0 Z',
        'KIND K AY1 N D',
        'TURNED T ER1 N D',
        'DIE D AY1',
        'OF AH1 V',
        'TO T UW1',
        'TO(2) T AH0',
        'BECAUSE B IH0 K AO1 Z',
    ]);

    $this->artisan('dictionary:import-cmudict', ['file' => $file])->assertSuccessful();

    // New word row (class "unknown") with ARPAbet converted to IPA.
    $gamblers = Word::query()->where('l_word', 'gamblers')->first();
    expect($gamblers)->not->toBeNull()
        ->and(Transcription::query()->where('word_id', $gamblers->id)->pluck('transcription')->all())
        ->toBe(['/ɡˈæmblɚz/']);

    // KIND already carried a stress-marked Kaikki IPA — untouched.
    expect(Transcription::query()->where('word_id', $kind->id)->pluck('transcription')->all())
        ->toBe(['/ˈkaɪnd/']);

    // Unstressed-only row gains the CMUdict variant (ˈ first in id order).
    expect(Transcription::query()->where('word_id', $turned->id)->orderBy('id')->pluck('transcription')->all())
        ->toBe(['/tɜːnd/', '/tˈɜːnd/']);

    // Both class rows gain the variant.
    expect(Transcription::query()
        ->whereIn('word_id', [$dieNoun->id, $dieVerb->id])
        ->where('transcription', '/dˈaɪ/')
        ->count())->toBe(2);

    // Stressed function-word variant dropped; unstressed TO(2) imported.
    $to = Word::query()->where('l_word', 'to')->first();
    expect($to)->not->toBeNull()
        ->and(Transcription::query()->where('word_id', $to->id)->pluck('transcription')->all())
        ->toBe(['/tə/']);

    // Function word with only stressed variants gets no row at all.
    expect(Word::query()->where('l_word', 'of')->exists())->toBeFalse();

    // Idempotent: a second run changes nothing.
    $countBefore = Transcription::query()->count();
    $this->artisan('dictionary:import-cmudict', ['file' => $file])->assertSuccessful();
    expect(Transcription::query()->count())->toBe($countBefore);

    unlink($file);
});
