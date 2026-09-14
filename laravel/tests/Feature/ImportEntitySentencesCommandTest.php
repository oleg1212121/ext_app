<?php

use App\Classes\SparseOrderService;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    SentenceType::create(['name' => 'sentence', 'description' => 'A standard sentence']);
});

function createImportEntities(): array
{
    $work = createWork();
    $en = createEntity('en', $work, ['name' => 'Test Book (en)']);
    $ru = createEntity('ru', $work, ['name' => 'Test Book (ru)']);

    return [$en, $ru];
}

function writeTempTextFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'entity_import_');
    file_put_contents($path, $content);

    return $path;
}

function bilingualFileContent(int $pairs = 3): string
{
    $blocks = [];

    for ($i = 1; $i <= $pairs; $i++) {
        $blocks[] = "Sentence {$i} EN.\n\nSentence {$i} RU.\n\n";
    }

    return rtrim(implode('', $blocks));
}

it('imports bilingual pairs with meaning matches', function () {
    [$en, $ru] = createImportEntities();
    $path = writeTempTextFile(bilingualFileContent(3));

    try {
        $this->artisan('entities:import-sentences', [
            'file' => $path,
            'first_entity_id' => $en->id,
            'second_entity_id' => $ru->id,
        ])->assertSuccessful();

        expect(EntitySentence::where('entity_id', $en->id)->count())->toBe(3)
            ->and(EntitySentence::where('entity_id', $ru->id)->count())->toBe(3)
            ->and(MeaningMatch::count())->toBe(3)
            ->and(SentenceMeaningMatch::where('side', 'a')->count())->toBe(3)
            ->and(SentenceMeaningMatch::where('side', 'b')->count())->toBe(3);

        $enSentences = EntitySentence::where('entity_id', $en->id)->orderBy('order')->get();
        expect($enSentences->pluck('content')->all())->toBe([
            'Sentence 1 EN.',
            'Sentence 2 EN.',
            'Sentence 3 EN.',
        ])->and($enSentences->pluck('order')->all())->toBe([
            0,
            SparseOrderService::STRIDE,
            SparseOrderService::STRIDE * 2,
        ]);

        $ruSentences = EntitySentence::where('entity_id', $ru->id)->orderBy('order')->get();
        expect($ruSentences->pluck('content')->all())->toBe([
            'Sentence 1 RU.',
            'Sentence 2 RU.',
            'Sentence 3 RU.',
        ]);

        $entityMatch = EntityMatch::query()
            ->where('a_entity_id', $en->id)
            ->where('b_entity_id', $ru->id)
            ->first();

        expect($entityMatch)->not->toBeNull()
            ->and($entityMatch->status)->toBe('completed')
            ->and($entityMatch->linked_count)->toBe(3);

        $meaningMatches = MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->orderBy('order')
            ->get();

        expect($meaningMatches)->toHaveCount(3)
            ->and($meaningMatches->pluck('order')->all())->toBe([
                0,
                SparseOrderService::STRIDE,
                SparseOrderService::STRIDE * 2,
            ]);

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

it('creates entity match when none exists', function () {
    [$en, $ru] = createImportEntities();
    $path = writeTempTextFile("Only EN.\n\nOnly RU.\n");

    try {
        expect(EntityMatch::count())->toBe(0);

        $this->artisan('entities:import-sentences', [
            'file' => $path,
            'first_entity_id' => $en->id,
            'second_entity_id' => $ru->id,
        ])->assertSuccessful();

        expect(EntityMatch::query()
            ->where('a_entity_id', $en->id)
            ->where('b_entity_id', $ru->id)
            ->exists())->toBeTrue();
    } finally {
        @unlink($path);
    }
});

it('replaces existing sentences and meaning matches on re-import', function () {
    [$en, $ru] = createImportEntities();
    $sentenceTypeId = SentenceType::first()->id;

    $enSentence = EntitySentence::create([
        'entity_id' => $en->id,
        'sentence_type_id' => $sentenceTypeId,
        'content' => 'Old EN.',
        'order' => 1,
    ]);
    $ruSentence = EntitySentence::create([
        'entity_id' => $ru->id,
        'sentence_type_id' => $sentenceTypeId,
        'content' => 'Old RU.',
        'order' => 1,
    ]);

    $entityMatch = createEntityMatch($en, $ru, [
        'status' => 'completed',
    ]);

    $meaningMatch = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.5,
        'alignment_chunk' => 0,
    ]);

    SentenceMeaningMatch::create([
        'entity_sentence_id' => $enSentence->id,
        'meaning_match_id' => $meaningMatch->id,
        'side' => 'a',
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $ruSentence->id,
        'meaning_match_id' => $meaningMatch->id,
        'side' => 'b',
    ]);

    $path = writeTempTextFile("New EN.\n\nNew RU.\n");

    try {
        $this->artisan('entities:import-sentences', [
            'file' => $path,
            'first_entity_id' => $en->id,
            'second_entity_id' => $ru->id,
        ])->assertSuccessful();

        expect(EntitySentence::where('entity_id', $en->id)->count())->toBe(1)
            ->and(EntitySentence::where('entity_id', $en->id)->value('content'))->toBe('New EN.')
            ->and(EntitySentence::where('entity_id', $ru->id)->value('content'))->toBe('New RU.')
            ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(1)
            ->and(SentenceMeaningMatch::count())->toBe(2);
    } finally {
        @unlink($path);
    }
});

it('fails when entity id is missing', function () {
    [$en, $ru] = createImportEntities();
    $path = writeTempTextFile("EN.\n\nRU.\n");

    try {
        $this->artisan('entities:import-sentences', [
            'file' => $path,
            'first_entity_id' => 99999,
            'second_entity_id' => $ru->id,
        ])->assertFailed();
    } finally {
        @unlink($path);
    }
});

it('fails on malformed file with extra non-empty line between pair', function () {
    [$en, $ru] = createImportEntities();
    $path = writeTempTextFile("EN.\nnot empty\nRU.\n\n");

    try {
        $this->artisan('entities:import-sentences', [
            'file' => $path,
            'first_entity_id' => $en->id,
            'second_entity_id' => $ru->id,
        ])->assertFailed();

        expect(EntitySentence::count())->toBe(0);
    } finally {
        @unlink($path);
    }
});

it('imports cyrillic text with utf-8 characters that previously broke preg_split', function () {
    [$en, $ru] = createImportEntities();
    $content = "I am cheerful.\r\n\r\n"
        ."Ни капли не кривлю душой: я стараюсь подходить к этой теме легко.\r\n\r\n";
    $path = writeTempTextFile($content);

    try {
        $this->artisan('entities:import-sentences', [
            'file' => $path,
            'first_entity_id' => $en->id,
            'second_entity_id' => $ru->id,
        ])->assertSuccessful();

        expect(EntitySentence::where('entity_id', $en->id)->value('content'))->toBe('I am cheerful.')
            ->and(EntitySentence::where('entity_id', $ru->id)->value('content'))
            ->toBe('Ни капли не кривлю душой: я стараюсь подходить к этой теме легко.');
    } finally {
        @unlink($path);
    }
});

it('skips whitespace-only separator lines', function () {
    [$en, $ru] = createImportEntities();
    $path = writeTempTextFile("EN.\n \r\nRU.\n\t\n");

    try {
        $this->artisan('entities:import-sentences', [
            'file' => $path,
            'first_entity_id' => $en->id,
            'second_entity_id' => $ru->id,
        ])->assertSuccessful();

        expect(EntitySentence::where('entity_id', $en->id)->count())->toBe(1)
            ->and(EntitySentence::where('entity_id', $ru->id)->count())->toBe(1)
            ->and(EntitySentence::where('entity_id', $en->id)->value('content'))->toBe('EN.')
            ->and(EntitySentence::where('entity_id', $ru->id)->value('content'))->toBe('RU.');
    } finally {
        @unlink($path);
    }
});

it('fails on malformed file with missing russian sentence', function () {
    [$en, $ru] = createImportEntities();
    $path = writeTempTextFile("EN.\n\n\n\n");

    try {
        $this->artisan('entities:import-sentences', [
            'file' => $path,
            'first_entity_id' => $en->id,
            'second_entity_id' => $ru->id,
        ])->assertFailed();
    } finally {
        @unlink($path);
    }
});
