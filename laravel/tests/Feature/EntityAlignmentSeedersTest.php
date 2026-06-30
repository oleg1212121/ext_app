<?php

use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\RuSentenceMeaningMatch;
use App\Models\SentenceType;
use Database\Seeders\EnEntitySeeder;
use Database\Seeders\EnEntitySentenceSeeder;
use Database\Seeders\EnRuEntityMatchSeeder;
use Database\Seeders\EnRuMeaningMatchSeeder;
use Database\Seeders\EnSentenceMeaningMatchSeeder;
use Database\Seeders\RuEntitySeeder;
use Database\Seeders\RuEntitySentenceSeeder;
use Database\Seeders\RuSentenceMeaningMatchSeeder;
use Database\Seeders\SentenceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function entityAlignmentSeeders(): array
{
    return [
        SentenceTypeSeeder::class,
        EnEntitySeeder::class,
        RuEntitySeeder::class,
        EnEntitySentenceSeeder::class,
        RuEntitySentenceSeeder::class,
        EnRuEntityMatchSeeder::class,
        EnRuMeaningMatchSeeder::class,
        EnSentenceMeaningMatchSeeder::class,
        RuSentenceMeaningMatchSeeder::class,
    ];
}

it('seeds sample entity alignment data', function () {
    $this->seed(entityAlignmentSeeders());

    $enEntity = EnEntity::query()->where('name', EnEntitySeeder::ENTITY_NAME)->first();
    $ruEntity = RuEntity::query()->where('name', RuEntitySeeder::ENTITY_NAME)->first();
    $entityMatch = EnRuEntityMatch::query()
        ->where('en_entity_id', $enEntity->id)
        ->where('ru_entity_id', $ruEntity->id)
        ->first();

    expect($enEntity)->not->toBeNull()
        ->and($ruEntity)->not->toBeNull()
        ->and(EnEntity::query()->count())->toBe(3)
        ->and(RuEntity::query()->count())->toBe(3)
        ->and(EnEntitySentence::query()->where('en_entity_id', $enEntity->id)->count())->toBe(7)
        ->and(RuEntitySentence::query()->where('ru_entity_id', $ruEntity->id)->count())->toBe(7)
        ->and($entityMatch)->not->toBeNull()
        ->and($entityMatch->status)->toBe('completed')
        ->and(EnRuEntityMatch::query()->where('status', 'pending')->count())->toBe(1)
        ->and(EnRuMeaningMatch::query()->where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(6)
        ->and(EnSentenceMeaningMatch::query()->count())->toBe(10)
        ->and(RuSentenceMeaningMatch::query()->count())->toBe(10)
        ->and(SentenceType::query()->count())->toBe(6);
});

it('can rerun entity alignment seeders without duplicating records', function () {
    $seeders = entityAlignmentSeeders();

    $this->seed($seeders);
    $this->seed($seeders);

    expect(EnEntity::query()->count())->toBe(3)
        ->and(RuEntity::query()->count())->toBe(3)
        ->and(EnEntitySentence::query()->count())->toBe(15)
        ->and(RuEntitySentence::query()->count())->toBe(15)
        ->and(EnRuEntityMatch::query()->count())->toBe(3)
        ->and(EnRuMeaningMatch::query()->count())->toBe(10)
        ->and(EnSentenceMeaningMatch::query()->count())->toBe(10)
        ->and(RuSentenceMeaningMatch::query()->count())->toBe(10);
});
