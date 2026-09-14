<?php

use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Safety guard: tests must always run against the dedicated `testing`
// connection. If a test run is started with the wrong connection (e.g.
// `--database=pgsql` from the shell), the first test fails here BEFORE any
// destructive migration can touch the main dev database.
beforeEach(function () {
    expect(DB::connection()->getName())->toBe('testing');
});

pest()->tia()
    ->filtered()
    ->baselined();

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Entity-domain fixtures (unified works/entities schema)
|--------------------------------------------------------------------------
*/

/**
 * Idempotently ensure the seeded languages exist. Returns code => model.
 *
 * @return array<string, Language>
 */
function createLanguages(): array
{
    $en = Language::query()->updateOrCreate(
        ['code' => 'en'],
        ['name' => 'English', 'is_enabled' => true, 'is_interface_enabled' => true, 'sort_order' => 0],
    );

    $ru = Language::query()->updateOrCreate(
        ['code' => 'ru'],
        ['name' => 'Russian', 'is_enabled' => true, 'is_interface_enabled' => true, 'sort_order' => 1],
    );

    return ['en' => $en, 'ru' => $ru];
}

function createWork(array $attributes = []): Work
{
    createLanguages();

    return Work::query()->create([
        'title' => 'Test Work',
        'original_language_id' => Language::query()->where('code', 'en')->value('id'),
        ...$attributes,
    ]);
}

/**
 * Create an entity of the given language code ('en'/'ru') for a work.
 */
function createEntity(string $languageCode, ?Work $work = null, array $attributes = []): Entity
{
    $languages = createLanguages();
    $language = $languages[$languageCode] ?? Language::query()->where('code', $languageCode)->firstOrFail();

    return Entity::query()->create([
        'work_id' => ($work ?? createWork())->id,
        'language_id' => $language->id,
        'name' => 'Entity ('.$languageCode.')',
        ...$attributes,
    ]);
}

/**
 * Create an entity match between two entities, enforcing the canonical
 * a_entity_id < b_entity_id ordering.
 */
function createEntityMatch(Entity $first, Entity $second, array $attributes = []): EntityMatch
{
    [$aId, $bId] = $first->id < $second->id
        ? [$first->id, $second->id]
        : [$second->id, $first->id];

    return EntityMatch::query()->create([
        'a_entity_id' => $aId,
        'b_entity_id' => $bId,
        'status' => 'pending',
        ...$attributes,
    ]);
}

/*
|--------------------------------------------------------------------------
| Dictionary-domain fixtures (unified language-keyed schema)
|--------------------------------------------------------------------------
*/

/**
 * Seed the standard word classes for both languages and return slug => id
 * lookups per language code.
 *
 * @return array<string, array<string, int>>
 */
function createWordClasses(): array
{
    createLanguages();

    $classes = [
        'en' => ['noun' => 'Noun', 'verb' => 'Verb', 'unknown' => 'Unknown'],
        'ru' => ['noun' => 'Существительное', 'verb' => 'Глагол', 'unknown' => 'Неизвестно'],
    ];

    $ids = [];
    foreach ($classes as $code => $slugs) {
        $languageId = Language::query()->where('code', $code)->value('id');
        foreach ($slugs as $slug => $title) {
            $ids[$code][$slug] = WordClass::query()
                ->updateOrCreate(
                    ['language_id' => $languageId, 'slug' => $slug],
                    ['title' => $title],
                )->id;
        }
    }

    return $ids;
}

/**
 * Create a word in the given language code with the given word-class slug.
 */
function createWord(string $languageCode, string $word, string $classSlug = 'noun', array $attributes = []): Word
{
    $classes = createWordClasses();
    $languageId = Language::query()->where('code', $languageCode)->value('id');

    return Word::query()->create([
        'word' => $word,
        'l_word' => mb_strtolower($word),
        'language_id' => $languageId,
        'word_class_id' => $classes[$languageCode][$classSlug],
        ...$attributes,
    ]);
}
