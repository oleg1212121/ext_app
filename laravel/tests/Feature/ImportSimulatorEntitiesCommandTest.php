<?php

use App\Classes\EntitySentenceImporter;
use App\Classes\SparseOrderService;
use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\RuSentenceMeaningMatch;
use App\Models\SentenceType;
use Database\Seeders\SimulatorEntitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    SentenceType::create(['name' => 'sentence', 'description' => 'A standard sentence']);
});

function simulatorDirectory(): string
{
    return public_path('texts/simulator');
}

function writeSimulatorFile(string $basename, string $content): string
{
    $directory = simulatorDirectory();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = $directory.'/'.$basename.'.txt';
    file_put_contents($path, $content);

    return $path;
}

function removeSimulatorFile(string $basename): void
{
    $path = simulatorDirectory().'/'.$basename.'.txt';

    if (is_file($path)) {
        unlink($path);
    }
}

function bilingualSimulatorContent(int $pairs = 2): string
{
    $blocks = [];

    for ($i = 1; $i <= $pairs; $i++) {
        $blocks[] = "Sentence {$i} EN.\n\nSentence {$i} RU.\n\n";
    }

    return rtrim(implode('', $blocks));
}

afterEach(function () {
    foreach (['test_batch_import', 'test_single_import'] as $basename) {
        removeSimulatorFile($basename);
    }

    EnEntity::query()->where('file_path', 'like', SimulatorEntitySeeder::FILE_PATH_PREFIX.'test_%')->delete();
    RuEntity::query()->where('file_path', 'like', SimulatorEntitySeeder::FILE_PATH_PREFIX.'test_%')->delete();
});

it('seeds simulator entities for bilingual files and skips excluded files', function () {
    writeSimulatorFile('test_batch_import', bilingualSimulatorContent(2));

    $this->seed(SimulatorEntitySeeder::class);

    expect(EnEntity::query()->where('name', SimulatorEntitySeeder::enEntityName('test_batch_import'))->exists())->toBeTrue()
        ->and(RuEntity::query()->where('name', SimulatorEntitySeeder::ruEntityName('test_batch_import'))->exists())->toBeTrue()
        ->and(EnEntity::query()->where('name', SimulatorEntitySeeder::enEntityName('001_articles'))->exists())->toBeFalse()
        ->and(RuEntity::query()->where('name', SimulatorEntitySeeder::ruEntityName('001_articles'))->exists())->toBeFalse();
});

function createSimulatorEntities(string $basename): array
{
    $filePath = SimulatorEntitySeeder::FILE_PATH_PREFIX.$basename.'.txt';

    $en = EnEntity::create([
        'name' => SimulatorEntitySeeder::enEntityName($basename),
        'file_path' => $filePath,
    ]);
    $ru = RuEntity::create([
        'name' => SimulatorEntitySeeder::ruEntityName($basename),
        'file_path' => $filePath,
    ]);

    return [$en, $ru];
}

it('imports all simulator entities with entities:import-simulator --all', function () {
    writeSimulatorFile('test_batch_import', bilingualSimulatorContent(3));
    createSimulatorEntities('test_batch_import');

    $this->artisan('entities:import-simulator', ['--all' => true])
        ->assertSuccessful();

    $enEntity = EnEntity::query()->where('name', SimulatorEntitySeeder::enEntityName('test_batch_import'))->firstOrFail();
    $ruEntity = RuEntity::query()->where('name', SimulatorEntitySeeder::ruEntityName('test_batch_import'))->firstOrFail();

    expect(EnEntitySentence::where('en_entity_id', $enEntity->id)->count())->toBe(3)
        ->and(RuEntitySentence::where('ru_entity_id', $ruEntity->id)->count())->toBe(3)
        ->and(EnRuEntityMatch::query()
            ->where('en_entity_id', $enEntity->id)
            ->where('ru_entity_id', $ruEntity->id)
            ->where('status', 'completed')
            ->exists())->toBeTrue()
        ->and(EnRuMeaningMatch::count())->toBe(3)
        ->and(EnSentenceMeaningMatch::count())->toBe(3)
        ->and(RuSentenceMeaningMatch::count())->toBe(3);
});

it('imports one simulator entity with --file', function () {
    writeSimulatorFile('test_single_import', bilingualSimulatorContent(2));
    createSimulatorEntities('test_single_import');

    $this->artisan('entities:import-simulator', ['--file' => 'test_single_import'])
        ->assertSuccessful();

    $enEntity = EnEntity::query()->where('name', SimulatorEntitySeeder::enEntityName('test_single_import'))->firstOrFail();

    expect(EnEntitySentence::where('en_entity_id', $enEntity->id)->count())->toBe(2);
});

it('skips completed simulator imports with --skip-existing', function () {
    writeSimulatorFile('test_batch_import', bilingualSimulatorContent(2));
    createSimulatorEntities('test_batch_import');

    $this->artisan('entities:import-simulator', ['--all' => true])->assertSuccessful();

    EnEntitySentence::query()->delete();

    $this->artisan('entities:import-simulator', ['--all' => true, '--skip-existing' => true])
        ->assertSuccessful();

    expect(EnEntitySentence::count())->toBe(0);
});

it('requires --all or --file for simulator import', function () {
    $this->artisan('entities:import-simulator')
        ->assertFailed();
});

it('bulk importer produces the same database shape as per-row expectations', function () {
    [$en, $ru] = [
        EnEntity::create(['name' => 'Bulk EN', 'file_path' => 'texts/simulator/bulk.txt']),
        RuEntity::create(['name' => 'Bulk RU', 'file_path' => 'texts/simulator/bulk.txt']),
    ];

    $path = simulatorDirectory().'/bulk-test.txt';
    file_put_contents($path, bilingualSimulatorContent(4));

    try {
        $result = app(EntitySentenceImporter::class)->import($en, $ru, $path);

        $enSentences = EnEntitySentence::where('en_entity_id', $en->id)->orderBy('order')->get();
        $ruSentences = RuEntitySentence::where('ru_entity_id', $ru->id)->orderBy('order')->get();
        $meaningMatches = EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $result->entityMatch->id)
            ->orderBy('order')
            ->get();

        expect($result->pairCount)->toBe(4)
            ->and($enSentences->pluck('content')->all())->toBe([
                'Sentence 1 EN.',
                'Sentence 2 EN.',
                'Sentence 3 EN.',
                'Sentence 4 EN.',
            ])
            ->and($ruSentences->pluck('order')->all())->toBe([
                0,
                SparseOrderService::STRIDE,
                SparseOrderService::STRIDE * 2,
                SparseOrderService::STRIDE * 3,
            ])
            ->and($meaningMatches)->toHaveCount(4);

        foreach ($meaningMatches as $index => $meaningMatch) {
            $enJunction = EnSentenceMeaningMatch::query()
                ->where('en_ru_meaning_match_id', $meaningMatch->id)
                ->first();
            $ruJunction = RuSentenceMeaningMatch::query()
                ->where('en_ru_meaning_match_id', $meaningMatch->id)
                ->first();

            expect($enJunction?->en_entity_sentence_id)->toBe($enSentences[$index]->id)
                ->and($ruJunction?->ru_entity_sentence_id)->toBe($ruSentences[$index]->id);
        }
    } finally {
        @unlink($path);
    }
});
