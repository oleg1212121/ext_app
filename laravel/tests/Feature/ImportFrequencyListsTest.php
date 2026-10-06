<?php

use App\Models\Entity;
use App\Models\Form;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    createLanguages();
    createWordClasses();
});

/**
 * Write band word lists into a fresh temp directory, cleaned up on shutdown.
 *
 * @param  array<string, array<int, string>>  $files  file name => lines
 * @return string directory path to pass as --path
 */
function makeFrequencyLists(array $files): string
{
    $dir = sys_get_temp_dir().'/freq-lists-'.uniqid('', true);
    mkdir($dir);

    foreach ($files as $name => $lines) {
        file_put_contents($dir.'/'.$name, implode("\n", $lines)."\n");
    }

    register_shutdown_function(fn () => File::deleteDirectory($dir));

    return $dir;
}

function createForm(Word $word, string $form): Form
{
    return Form::query()->create([
        'word_id' => $word->id,
        'form' => $form,
        'l_word' => mb_strtolower($form),
    ]);
}

it('clamps an unranked word down to the file band', function () {
    $word = createWord('en', 'house', 'noun');
    expect((int) $word->refresh()->frequency)->toBe(1100000);

    $dir = makeFrequencyLists([
        '100.txt' => ['en', '100', 'house'],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertSuccessful();

    expect((int) $word->refresh()->frequency)->toBe(100);
});

it('never raises a rank that is already finer than the band', function () {
    $fine = createWord('en', 'the', 'noun', ['frequency' => 3]);
    $wide = createWord('en', 'zoological', 'noun', ['frequency' => 9000]);

    $dir = makeFrequencyLists([
        '8000.txt' => ['en', '8000', 'the', 'zoological'],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertSuccessful();

    expect((int) $fine->refresh()->frequency)->toBe(3);
    expect((int) $wide->refresh()->frequency)->toBe(8000);
});

it('applies the smallest band when a word appears in several files', function () {
    $word = createWord('en', 'house', 'noun');

    $dir = makeFrequencyLists([
        '2000.txt' => ['en', '2000', 'house'],
        '500.txt' => ['en', '500', 'house'],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertSuccessful();

    expect((int) $word->refresh()->frequency)->toBe(500);
});

it('collapses a word repeated within one file', function () {
    $word = createWord('en', 'house', 'noun');

    $dir = makeFrequencyLists([
        '500.txt' => ['en', '500', 'house', 'house', 'house'],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertSuccessful();

    expect((int) $word->refresh()->frequency)->toBe(500);
});

it('reaches base words through their inflected forms', function () {
    $abandon = createWord('en', 'abandon', 'verb');
    createForm($abandon, 'abandoned');

    $dir = makeFrequencyLists([
        '3000.txt' => ['en', '3000', 'abandoned'],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertSuccessful();

    expect((int) $abandon->refresh()->frequency)->toBe(3000);
});

it('lowers every word a file word matches, direct and through forms', function () {
    $left = createWord('en', 'left', 'noun');
    $leave = createWord('en', 'leave', 'verb');
    createForm($leave, 'left');

    $dir = makeFrequencyLists([
        '1000.txt' => ['en', '1000', 'left'],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertSuccessful();

    expect((int) $left->refresh()->frequency)->toBe(1000);
    expect((int) $leave->refresh()->frequency)->toBe(1000);
});

it('moves every word-class row of a headword together', function () {
    $noun = createWord('en', 'the', 'noun');
    $unknown = createWord('en', 'the', 'unknown');

    $dir = makeFrequencyLists([
        '100.txt' => ['en', '100', 'the'],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertSuccessful();

    expect((int) $noun->refresh()->frequency)->toBe(100);
    expect((int) $unknown->refresh()->frequency)->toBe(100);
});

it('skips unknown words and never creates words', function () {
    createWord('en', 'the', 'noun');
    $before = Word::query()->count();

    $dir = makeFrequencyLists([
        '100.txt' => ['en', '100', 'the', 'hatred n', 'to infinitive marker'],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertSuccessful();

    expect(Word::query()->count())->toBe($before);
});

it('is idempotent on re-run', function () {
    $word = createWord('en', 'house', 'noun', ['frequency' => 700]);

    $dir = makeFrequencyLists([
        '500.txt' => ['en', '500', 'house'],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertSuccessful();
    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertSuccessful();

    expect((int) $word->refresh()->frequency)->toBe(500);
});

it('reports without writing under --dry-run', function () {
    $word = createWord('en', 'house', 'noun');

    $dir = makeFrequencyLists([
        '500.txt' => ['en', '500', 'house'],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir, '--dry-run' => true])
        ->assertSuccessful();

    expect((int) $word->refresh()->frequency)->toBe(1100000);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertSuccessful();

    expect((int) $word->refresh()->frequency)->toBe(500);
});

it('leaves entity frequency-correction markers alone', function () {
    $entity = createEntity('en');
    Entity::query()->whereKey($entity->id)->update(['frequency_counted_at' => now()]);

    createWord('en', 'house', 'noun');

    $dir = makeFrequencyLists([
        '500.txt' => ['en', '500', 'house'],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertSuccessful();

    expect($entity->refresh()->frequency_counted_at)->not->toBeNull();
});

it('fails without writing when a header is malformed', function () {
    $word = createWord('en', 'house', 'noun');

    $dir = makeFrequencyLists([
        'a.txt' => ['en', '500', 'house'],
        'b.txt' => ['xx', '500', 'house'],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertFailed();

    expect((int) $word->refresh()->frequency)->toBe(1100000);
});

it('rejects a non-numeric band', function () {
    $dir = makeFrequencyLists([
        'bad.txt' => ['en', 'lots', 'house'],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertFailed();
});

it('applies lists per language, ignoring other languages words', function () {
    $en = createWord('en', 'house', 'noun');
    $ru = createWord('ru', 'дом', 'noun');

    $dir = makeFrequencyLists([
        'en500.txt' => ['en', '500', 'house'],
        'ru700.txt' => ['ru', '700', 'дом'],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertSuccessful();

    expect((int) $en->refresh()->frequency)->toBe(500);
    expect((int) $ru->refresh()->frequency)->toBe(700);
});

it('tolerates blank lines and matches stress-marked words', function () {
    $dom = createWord('ru', 'дом', 'noun');
    $garden = createWord('ru', 'сад', 'noun');

    $dir = makeFrequencyLists([
        'ru500.txt' => ['ru', '500', 'до́м', '', 'сад '],
    ]);

    $this->artisan('words:import-frequency-lists', ['--path' => $dir])->assertSuccessful();

    expect((int) $dom->refresh()->frequency)->toBe(500);
    expect((int) $garden->refresh()->frequency)->toBe(500);
});
