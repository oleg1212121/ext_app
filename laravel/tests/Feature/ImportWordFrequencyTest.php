<?php

use App\Models\Entity;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    createLanguages();
    createWordClasses();
});

it('updates frequency for all word classes of a spelling', function () {
    $theNoun = createWord('en', 'the', 'noun');
    $theUnknown = createWord('en', 'the', 'unknown');
    $and = createWord('en', 'and', 'verb');
    $csv = database_path('frequency/en-sample.csv');

    $this->artisan('words:import-frequency', ['source' => $csv, '--lang' => 'en']);

    expect((int) $theNoun->refresh()->frequency)->toBe(1);
    expect((int) $theUnknown->refresh()->frequency)->toBe(1);
    expect((int) $and->refresh()->frequency)->toBe(5);
});

it('skips words missing from the dictionary and never creates words', function () {
    createWord('en', 'the', 'noun');
    $before = Word::query()->count();
    $csv = database_path('frequency/en-sample.csv');

    $this->artisan('words:import-frequency', ['source' => $csv, '--lang' => 'en']);

    expect(Word::query()->count())->toBe($before);
});

it('is idempotent on re-run', function () {
    $word = createWord('en', 'be', 'verb');
    $csv = database_path('frequency/en-sample.csv');

    $this->artisan('words:import-frequency', ['source' => $csv, '--lang' => 'en']);
    $this->artisan('words:import-frequency', ['source' => $csv, '--lang' => 'en']);

    expect((int) $word->refresh()->frequency)->toBe(2);
});

it('fails on unknown language', function () {
    $csv = database_path('frequency/en-sample.csv');

    $this->artisan('words:import-frequency', ['source' => $csv, '--lang' => 'xx'])
        ->assertFailed();
});

it('downloads a named source and ranks by line position', function () {
    $you = createWord('en', 'you', 'noun');
    $and = createWord('en', 'and', 'verb');
    $entity = createEntity('en');
    Entity::query()->whereKey($entity->id)->update(['frequency_counted_at' => now()]);

    Storage::fake('local');
    Http::fake(['*' => Http::response("you 28787591\nand 10572938\nthe 22761659\n")]);

    $this->artisan('words:import-frequency', ['source' => 'en-opensubtitles'])
        ->assertSuccessful();

    expect((int) $you->refresh()->frequency)->toBe(1);
    expect((int) $and->refresh()->frequency)->toBe(2);
    expect($entity->refresh()->frequency_counted_at)->toBeNull();
    Http::assertSentCount(1);
});

it('aggregates rnc lemmas by summed ipm before ranking', function () {
    $deloNoun = createWord('ru', 'дело', 'noun');
    $deloVerb = createWord('ru', 'дело', 'verb');
    $by = createWord('ru', 'бы', 'unknown');

    Storage::fake('local');
    Http::fake(['*' => Http::response(
        "Lemma,PoS,Freq(ipm),R,D,Doc\nДело,s,100.0,100,97,32332\nдело,v,50.0,100,97,160\nбы,part,90.0,100,97,3231\nнет,s,bad,1,1,1\n"
    )]);

    $this->artisan('words:import-frequency', ['source' => 'ru-rnc'])
        ->assertSuccessful();

    expect((int) $deloNoun->refresh()->frequency)->toBe(1);
    expect((int) $deloVerb->refresh()->frequency)->toBe(1);
    expect((int) $by->refresh()->frequency)->toBe(2);
});

it('strips stress marks from rnc lemmas before matching', function () {
    $dom = createWord('ru', 'дом', 'noun');

    Storage::fake('local');
    Http::fake(['*' => Http::response(
        "Lemma,PoS,Freq(ipm),R,D,Doc\nдо́м,s,120.0,100,97,32332\nбы,part,90.0,100,97,3231\n"
    )]);

    $this->artisan('words:import-frequency', ['source' => 'ru-rnc'])
        ->assertSuccessful();

    expect((int) $dom->refresh()->frequency)->toBe(1);
});

it('reuses an existing download unless forced', function () {
    createWord('en', 'the', 'noun');
    Storage::fake('local');
    Storage::disk('local')->put('frequency/en_full.txt', "the 10\nand 9\n");
    Http::fake(['*' => Http::response('garbage 1')]);

    $this->artisan('words:import-frequency', ['source' => 'en-opensubtitles'])
        ->assertSuccessful();
    Http::assertNothingSent();

    Http::fake(['*' => Http::response("the 5\nand 4\nthe 3\n")]);
    $this->artisan('words:import-frequency', ['source' => 'en-opensubtitles', '--force-redownload' => true])
        ->assertSuccessful();
    Http::assertSentCount(1);
});

it('resets correction markers only for the imported language', function () {
    $en = createEntity('en');
    $ru = createEntity('ru');
    Entity::query()->whereIn('id', [$en->id, $ru->id])->update(['frequency_counted_at' => now()]);
    $csv = database_path('frequency/en-sample.csv');

    $this->artisan('words:import-frequency', ['source' => $csv, '--lang' => 'en'])
        ->assertSuccessful();

    expect($en->refresh()->frequency_counted_at)->toBeNull();
    expect($ru->refresh()->frequency_counted_at)->not->toBeNull();
});
