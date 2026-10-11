<?php

use App\Filament\Resources\TagResource\Pages\CreateTag;
use App\Filament\Resources\TagResource\Pages\ListTags;
use App\Filament\Resources\WordResource\Pages\EditWord;
use App\Filament\Resources\WordResource\Pages\ListWords;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function actingAsWordTagsAdmin(): void
{
    test()->actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

it('syncs tags through the word relation without duplicating pivot rows', function () {
    $word = createWord('en', 'test');
    $tag = Tag::factory()->create();

    $word->tags()->sync([$tag->id]);
    $word->tags()->sync([$tag->id]);

    expect(DB::table('tagged_words')->count())->toBeOne();
});

it('enforces the unique word-tag pair in the renamed pivot', function () {
    $word = createWord('en', 'test');
    $tag = Tag::factory()->create();

    $word->tags()->attach($tag->id);
    $word->tags()->attach($tag->id);
})->throws(QueryException::class);

it('timestamps pivot rows written through the word relation', function () {
    $word = createWord('en', 'test');
    $tag = Tag::factory()->create();

    $word->tags()->attach($tag->id);

    $pivot = DB::table('tagged_words')->first();
    expect($pivot->word_id)->toBe($word->id)
        ->and($pivot->tag_id)->toBe($tag->id)
        ->and($pivot->created_at)->not->toBeNull()
        ->and($pivot->updated_at)->not->toBeNull();
});

it('resolves the inverse words relation on the tag', function () {
    $word = createWord('en', 'test');
    $tag = Tag::factory()->create();

    $word->tags()->attach($tag);

    expect($tag->refresh()->words->pluck('id'))->toContain($word->id);
});

it('removes pivot rows when the word is deleted', function () {
    $word = createWord('en', 'test');
    $tag = Tag::factory()->create();
    $word->tags()->attach($tag);

    $word->delete();

    expect(DB::table('tagged_words')->count())->toBe(0);
});

it('removes pivot rows when the tag is deleted', function () {
    $word = createWord('en', 'test');
    $tag = Tag::factory()->create();
    $word->tags()->attach($tag);

    $tag->delete();

    expect(DB::table('tagged_words')->count())->toBe(0);
});

it('lists word tags for an authenticated admin', function () {
    actingAsWordTagsAdmin();

    $tag = Tag::factory()->create(['name' => 'Science', 'slug' => 'science']);

    Livewire::test(ListTags::class)
        ->assertCanSeeTableRecords([$tag]);
});

it('creates a word tag from the create page', function () {
    actingAsWordTagsAdmin();

    Livewire::test(CreateTag::class)
        ->fillForm(['name' => 'Science', 'slug' => 'science'])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect(Tag::query()->where('slug', 'science')->where('name', 'Science')->exists())->toBeTrue();
});

it('persists tags from the Words edit form', function () {
    actingAsWordTagsAdmin();

    $word = createWord('en', 'test');
    $tag = Tag::factory()->create();

    Livewire::test(EditWord::class, ['record' => $word->id])
        ->fillForm(['tags' => [$tag->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($word->refresh()->tags->pluck('id'))->toContain($tag->id);
});

it('creates a tag inline from the Words edit form and attaches it', function () {
    actingAsWordTagsAdmin();

    $word = createWord('en', 'test');

    $component = Livewire::test(EditWord::class, ['record' => $word->id])
        ->callAction(
            TestAction::make('createOption')->schemaComponent('tags'),
            data: ['name' => 'Fresh tag', 'slug' => 'fresh-tag'],
        )
        ->assertHasNoActionErrors();

    $tag = Tag::query()->where('slug', 'fresh-tag')->first();
    expect($tag)->not->toBeNull();

    $component->assertSchemaStateSet(['tags' => [$tag->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($word->refresh()->tags->pluck('id'))->toContain($tag->id);
});

it('narrows the Words table to words carrying the selected tag', function () {
    actingAsWordTagsAdmin();

    $tagged = createWord('en', 'tagged');
    $untagged = createWord('en', 'plain');
    $tag = Tag::factory()->create();
    $tagged->tags()->attach($tag);

    Livewire::test(ListWords::class)
        ->filterTable('tags', [$tag->id])
        ->assertCanSeeTableRecords([$tagged])
        ->assertCanNotSeeTableRecords([$untagged]);
});
