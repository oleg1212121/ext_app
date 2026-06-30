<?php

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
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    SentenceType::create(['name' => 'sentence', 'description' => 'A standard sentence']);
});

function createImportEntities(): array
{
    $en = EnEntity::create(['name' => 'Test Book (en)']);
    $ru = RuEntity::create(['name' => 'Test Book (ru)']);

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
            'en_entity_id' => $en->id,
            'ru_entity_id' => $ru->id,
        ])->assertSuccessful();

        expect(EnEntitySentence::where('en_entity_id', $en->id)->count())->toBe(3)
            ->and(RuEntitySentence::where('ru_entity_id', $ru->id)->count())->toBe(3)
            ->and(EnRuMeaningMatch::count())->toBe(3)
            ->and(EnSentenceMeaningMatch::count())->toBe(3)
            ->and(RuSentenceMeaningMatch::count())->toBe(3);

        $enSentences = EnEntitySentence::where('en_entity_id', $en->id)->orderBy('order')->get();
        expect($enSentences->pluck('content')->all())->toBe([
            'Sentence 1 EN.',
            'Sentence 2 EN.',
            'Sentence 3 EN.',
        ])->and($enSentences->pluck('order')->all())->toBe([
            0,
            SparseOrderService::STRIDE,
            SparseOrderService::STRIDE * 2,
        ]);

        $ruSentences = RuEntitySentence::where('ru_entity_id', $ru->id)->orderBy('order')->get();
        expect($ruSentences->pluck('content')->all())->toBe([
            'Sentence 1 RU.',
            'Sentence 2 RU.',
            'Sentence 3 RU.',
        ]);

        $entityMatch = EnRuEntityMatch::query()
            ->where('en_entity_id', $en->id)
            ->where('ru_entity_id', $ru->id)
            ->first();

        expect($entityMatch)->not->toBeNull()
            ->and($entityMatch->status)->toBe('completed')
            ->and($entityMatch->linked_count)->toBe(3);

        $meaningMatches = EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatch->id)
            ->orderBy('order')
            ->get();

        expect($meaningMatches)->toHaveCount(3)
            ->and($meaningMatches->pluck('order')->all())->toBe([
                0,
                SparseOrderService::STRIDE,
                SparseOrderService::STRIDE * 2,
            ]);

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

it('creates entity match when none exists', function () {
    [$en, $ru] = createImportEntities();
    $path = writeTempTextFile("Only EN.\n\nOnly RU.\n");

    try {
        expect(EnRuEntityMatch::count())->toBe(0);

        $this->artisan('entities:import-sentences', [
            'file' => $path,
            'en_entity_id' => $en->id,
            'ru_entity_id' => $ru->id,
        ])->assertSuccessful();

        expect(EnRuEntityMatch::query()
            ->where('en_entity_id', $en->id)
            ->where('ru_entity_id', $ru->id)
            ->exists())->toBeTrue();
    } finally {
        @unlink($path);
    }
});

it('replaces existing sentences and meaning matches on re-import', function () {
    [$en, $ru] = createImportEntities();
    $sentenceTypeId = SentenceType::first()->id;

    $enSentence = EnEntitySentence::create([
        'en_entity_id' => $en->id,
        'sentence_type_id' => $sentenceTypeId,
        'content' => 'Old EN.',
        'order' => 1,
    ]);
    $ruSentence = RuEntitySentence::create([
        'ru_entity_id' => $ru->id,
        'sentence_type_id' => $sentenceTypeId,
        'content' => 'Old RU.',
        'order' => 1,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $en->id,
        'ru_entity_id' => $ru->id,
        'status' => 'completed',
    ]);

    $meaningMatch = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.5,
        'alignment_chunk' => 0,
    ]);

    EnSentenceMeaningMatch::create([
        'en_entity_sentence_id' => $enSentence->id,
        'en_ru_meaning_match_id' => $meaningMatch->id,
        'order' => 0,
    ]);
    RuSentenceMeaningMatch::create([
        'ru_entity_sentence_id' => $ruSentence->id,
        'en_ru_meaning_match_id' => $meaningMatch->id,
        'order' => 0,
    ]);

    $path = writeTempTextFile("New EN.\n\nNew RU.\n");

    try {
        $this->artisan('entities:import-sentences', [
            'file' => $path,
            'en_entity_id' => $en->id,
            'ru_entity_id' => $ru->id,
        ])->assertSuccessful();

        expect(EnEntitySentence::where('en_entity_id', $en->id)->count())->toBe(1)
            ->and(EnEntitySentence::where('en_entity_id', $en->id)->value('content'))->toBe('New EN.')
            ->and(RuEntitySentence::where('ru_entity_id', $ru->id)->value('content'))->toBe('New RU.')
            ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(1)
            ->and(EnSentenceMeaningMatch::count())->toBe(1)
            ->and(RuSentenceMeaningMatch::count())->toBe(1);
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
            'en_entity_id' => 99999,
            'ru_entity_id' => $ru->id,
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
            'en_entity_id' => $en->id,
            'ru_entity_id' => $ru->id,
        ])->assertFailed();

        expect(EnEntitySentence::count())->toBe(0)
            ->and(RuEntitySentence::count())->toBe(0);
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
            'en_entity_id' => $en->id,
            'ru_entity_id' => $ru->id,
        ])->assertSuccessful();

        expect(EnEntitySentence::where('en_entity_id', $en->id)->value('content'))->toBe('I am cheerful.')
            ->and(RuEntitySentence::where('ru_entity_id', $ru->id)->value('content'))
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
            'en_entity_id' => $en->id,
            'ru_entity_id' => $ru->id,
        ])->assertSuccessful();

        expect(EnEntitySentence::where('en_entity_id', $en->id)->count())->toBe(1)
            ->and(RuEntitySentence::where('ru_entity_id', $ru->id)->count())->toBe(1)
            ->and(EnEntitySentence::where('en_entity_id', $en->id)->value('content'))->toBe('EN.')
            ->and(RuEntitySentence::where('ru_entity_id', $ru->id)->value('content'))->toBe('RU.');
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
            'en_entity_id' => $en->id,
            'ru_entity_id' => $ru->id,
        ])->assertFailed();
    } finally {
        @unlink($path);
    }
});
