<?php

use App\Classes\EntitySentenceImporter;
use App\Classes\SparseOrderService;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use Database\Seeders\SimulatorEntitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    createLanguages();
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

    Entity::query()->where('file_path', 'like', SimulatorEntitySeeder::FILE_PATH_PREFIX.'test_%')->delete();
});

it('seeds simulator entities for bilingual files and skips excluded files', function () {
    writeSimulatorFile('test_batch_import', bilingualSimulatorContent(2));

    $this->seed(SimulatorEntitySeeder::class);

    expect(Entity::query()->where('name', SimulatorEntitySeeder::entityName('test_batch_import', 'en'))->exists())->toBeTrue()
        ->and(Entity::query()->where('name', SimulatorEntitySeeder::entityName('test_batch_import', 'ru'))->exists())->toBeTrue()
        ->and(Entity::query()->where('name', SimulatorEntitySeeder::entityName('001_articles', 'en'))->exists())->toBeFalse()
        ->and(Entity::query()->where('name', SimulatorEntitySeeder::entityName('001_articles', 'ru'))->exists())->toBeFalse();
});

function createSimulatorEntities(string $basename): array
{
    $filePath = SimulatorEntitySeeder::FILE_PATH_PREFIX.$basename.'.txt';
    $work = createWork(['title' => SimulatorEntitySeeder::workTitle($basename)]);

    $en = createEntity('en', $work, [
        'name' => SimulatorEntitySeeder::entityName($basename, 'en'),
        'file_path' => $filePath,
    ]);
    $ru = createEntity('ru', $work, [
        'name' => SimulatorEntitySeeder::entityName($basename, 'ru'),
        'file_path' => $filePath,
    ]);

    return [$en, $ru];
}

it('imports all simulator entities with entities:import-simulator --all', function () {
    writeSimulatorFile('test_batch_import', bilingualSimulatorContent(3));
    createSimulatorEntities('test_batch_import');

    $this->artisan('entities:import-simulator', ['--all' => true])
        ->assertSuccessful();

    $enEntity = Entity::query()->where('name', SimulatorEntitySeeder::entityName('test_batch_import', 'en'))->firstOrFail();
    $ruEntity = Entity::query()->where('name', SimulatorEntitySeeder::entityName('test_batch_import', 'ru'))->firstOrFail();

    expect(EntitySentence::where('entity_id', $enEntity->id)->count())->toBe(3)
        ->and(EntitySentence::where('entity_id', $ruEntity->id)->count())->toBe(3)
        ->and(EntityMatch::query()
            ->where('a_entity_id', $enEntity->id)
            ->where('b_entity_id', $ruEntity->id)
            ->where('status', 'completed')
            ->exists())->toBeTrue()
        ->and(MeaningMatch::count())->toBe(3)
        ->and(SentenceMeaningMatch::where('side', 'a')->count())->toBe(3)
        ->and(SentenceMeaningMatch::where('side', 'b')->count())->toBe(3);
});

it('imports one simulator entity with --file', function () {
    writeSimulatorFile('test_single_import', bilingualSimulatorContent(2));
    createSimulatorEntities('test_single_import');

    $this->artisan('entities:import-simulator', ['--file' => 'test_single_import'])
        ->assertSuccessful();

    $enEntity = Entity::query()->where('name', SimulatorEntitySeeder::entityName('test_single_import', 'en'))->firstOrFail();

    expect(EntitySentence::where('entity_id', $enEntity->id)->count())->toBe(2);
});

it('skips completed simulator imports with --skip-existing', function () {
    writeSimulatorFile('test_batch_import', bilingualSimulatorContent(2));
    createSimulatorEntities('test_batch_import');

    $this->artisan('entities:import-simulator', ['--all' => true])->assertSuccessful();

    EntitySentence::query()->delete();

    $this->artisan('entities:import-simulator', ['--all' => true, '--skip-existing' => true])
        ->assertSuccessful();

    expect(EntitySentence::count())->toBe(0);
});

it('requires --all or --file for simulator import', function () {
    $this->artisan('entities:import-simulator')
        ->assertFailed();
});

it('bulk importer produces the same database shape as per-row expectations', function () {
    $work = createWork();
    [$en, $ru] = [
        createEntity('en', $work, ['name' => 'Bulk EN', 'file_path' => 'texts/simulator/bulk.txt']),
        createEntity('ru', $work, ['name' => 'Bulk RU', 'file_path' => 'texts/simulator/bulk.txt']),
    ];

    $path = simulatorDirectory().'/bulk-test.txt';
    file_put_contents($path, bilingualSimulatorContent(4));

    try {
        $result = app(EntitySentenceImporter::class)->import($en, $ru, $path);

        $enSentences = EntitySentence::where('entity_id', $en->id)->orderBy('order')->get();
        $ruSentences = EntitySentence::where('entity_id', $ru->id)->orderBy('order')->get();
        $meaningMatches = MeaningMatch::query()
            ->where('entity_match_id', $result->entityMatch->id)
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
            $enJunction = SentenceMeaningMatch::query()
                ->where('meaning_match_id', $meaningMatch->id)
                ->where('side', 'a')
                ->first();
            $ruJunction = SentenceMeaningMatch::query()
                ->where('meaning_match_id', $meaningMatch->id)
                ->where('side', 'b')
                ->first();

            expect($enJunction?->entity_sentence_id)->toBe($enSentences[$index]->id)
                ->and($ruJunction?->entity_sentence_id)->toBe($ruSentences[$index]->id);
        }
    } finally {
        @unlink($path);
    }
});
