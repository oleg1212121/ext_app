<?php

use App\Filament\Resources\AlignmentTagResource;
use App\Filament\Resources\AlignmentTagResource\Pages\CreateAlignmentTag;
use App\Filament\Resources\AlignmentTagResource\Pages\ListAlignmentTags;
use App\Filament\Resources\EntityMatchResource;
use App\Filament\Resources\EntityMatchResource\Pages\EditEntityMatch;
use App\Filament\Resources\EntityMatchResource\Pages\ListEntityMatches;
use App\Models\AlignmentTag;
use App\Models\EntityMatch;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function createMatchFixture(): EntityMatch
{
    $work = createWork();

    return createEntityMatch(
        createEntity('en', $work),
        createEntity('ru', $work),
    );
}

it('syncs alignment tags through the entity match relation without duplicating pivot rows', function () {
    $match = createMatchFixture();
    $alignmentTag = AlignmentTag::factory()->create();

    $match->tags()->sync([$alignmentTag->id]);
    $match->tags()->sync([$alignmentTag->id]);

    expect(DB::table('tagged_alignments')->count())->toBeOne();
});

it('enforces the unique alignment-tag pair in the pivot', function () {
    $match = createMatchFixture();
    $alignmentTag = AlignmentTag::factory()->create();

    $match->tags()->attach($alignmentTag->id);
    $match->tags()->attach($alignmentTag->id);
})->throws(QueryException::class);

it('timestamps pivot rows written through the entity match relation', function () {
    $match = createMatchFixture();
    $alignmentTag = AlignmentTag::factory()->create();

    $match->tags()->attach($alignmentTag->id);

    $pivot = DB::table('tagged_alignments')->first();
    expect($pivot->entity_match_id)->toBe($match->id)
        ->and($pivot->alignment_tag_id)->toBe($alignmentTag->id)
        ->and($pivot->created_at)->not->toBeNull()
        ->and($pivot->updated_at)->not->toBeNull();
});

it('resolves the inverse alignments relation on the alignment tag', function () {
    $match = createMatchFixture();
    $alignmentTag = AlignmentTag::factory()->create();

    $match->tags()->attach($alignmentTag);

    expect($alignmentTag->refresh()->alignments->pluck('id'))->toContain($match->id);
});

it('removes pivot rows when the entity match is deleted', function () {
    $match = createMatchFixture();
    $alignmentTag = AlignmentTag::factory()->create();
    $match->tags()->attach($alignmentTag);

    $match->delete();

    expect(DB::table('tagged_alignments')->count())->toBe(0);
});

it('removes pivot rows when the alignment tag is deleted', function () {
    $match = createMatchFixture();
    $alignmentTag = AlignmentTag::factory()->create();
    $match->tags()->attach($alignmentTag);

    $alignmentTag->delete();

    expect(DB::table('tagged_alignments')->count())->toBe(0);
});

it('lists alignment tags for an authenticated admin', function () {
    actingAsAdmin();

    $alignmentTag = AlignmentTag::factory()->create(['name' => 'Needs review', 'slug' => 'needs-review']);

    Livewire::test(ListAlignmentTags::class)
        ->assertCanSeeTableRecords([$alignmentTag]);
});

it('creates an alignment tag from the create page', function () {
    actingAsAdmin();

    Livewire::test(CreateAlignmentTag::class)
        ->fillForm(['name' => 'Needs review', 'slug' => 'needs-review'])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect(AlignmentTag::query()->where('slug', 'needs-review')->where('name', 'Needs review')->exists())->toBeTrue();
});

it('persists alignment tags from the Sentence Alignments edit form', function () {
    actingAsAdmin();

    $match = createMatchFixture();
    $alignmentTag = AlignmentTag::factory()->create();

    Livewire::test(EditEntityMatch::class, ['record' => $match->id])
        ->fillForm(['tags' => [$alignmentTag->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($match->refresh()->tags->pluck('id'))->toContain($alignmentTag->id);
});

it('leaves the entity pair untouched when the Sentence Alignments edit form saves', function () {
    actingAsAdmin();

    $match = createMatchFixture();
    $match->refresh(); // hydrate the DB defaults (chunk_size 75 / max_n 6)
    $otherEntity = createEntity('en', $match->aEntity->work);
    $alignmentTag = AlignmentTag::factory()->create();

    $aEntityIdBefore = $match->a_entity_id;
    $bEntityIdBefore = $match->b_entity_id;
    $chunkSizeBefore = $match->chunk_size;
    $maxNBefore = $match->max_n;

    Livewire::test(EditEntityMatch::class, ['record' => $match->id])
        ->fillForm([
            'first_entity_id' => $otherEntity->id,
            'second_entity_id' => $otherEntity->id,
            'chunk_size' => 42,
            'max_n' => 3,
            'tags' => [$alignmentTag->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($match->refresh()->a_entity_id)->toBe($aEntityIdBefore)
        ->and($match->b_entity_id)->toBe($bEntityIdBefore)
        ->and($match->chunk_size)->toBe($chunkSizeBefore)
        ->and($match->max_n)->toBe($maxNBefore)
        ->and($match->tags->pluck('id'))->toContain($alignmentTag->id);
});

it('creates an alignment tag inline from the Sentence Alignments edit form and attaches it', function () {
    actingAsAdmin();

    $match = createMatchFixture();

    $component = Livewire::test(EditEntityMatch::class, ['record' => $match->id])
        ->callAction(
            TestAction::make('createOption')->schemaComponent('tags'),
            data: ['name' => 'Fresh tag', 'slug' => 'fresh-tag'],
        )
        ->assertHasNoActionErrors();

    $alignmentTag = AlignmentTag::query()->where('slug', 'fresh-tag')->first();
    expect($alignmentTag)->not->toBeNull();

    $component->assertSchemaStateSet(['tags' => [$alignmentTag->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($match->refresh()->tags->pluck('id'))->toContain($alignmentTag->id);
});

it('narrows the Sentence Alignments table to matches carrying the selected tag', function () {
    actingAsAdmin();

    $tagged = createMatchFixture();
    $untagged = createMatchFixture();
    $alignmentTag = AlignmentTag::factory()->create();
    $tagged->tags()->attach($alignmentTag);

    Livewire::test(ListEntityMatches::class)
        ->filterTable('tags', [$alignmentTag->id])
        ->assertCanSeeTableRecords([$tagged])
        ->assertCanNotSeeTableRecords([$untagged]);
});

it('renders the tag badges on the Sentence Alignments table', function () {
    actingAsAdmin();

    $match = createMatchFixture();
    $alignmentTag = AlignmentTag::factory()->create(['name' => 'Hand-verified']);
    $match->tags()->attach($alignmentTag);

    Livewire::test(ListEntityMatches::class)
        ->assertCanSeeTableRecords([$match])
        ->assertSee('Hand-verified');
});

it('offers an edit action on the Sentence Alignments table that opens the edit page', function () {
    actingAsAdmin();

    $match = createMatchFixture();

    Livewire::test(ListEntityMatches::class)
        ->assertActionExists(TestAction::make('edit')->table($match))
        ->assertActionHasUrl(TestAction::make('edit')->table($match), EntityMatchResource::getUrl('edit', ['record' => $match]));
});

it('groups the Alignment Tags resource under the Entities navigation group', function () {
    expect(AlignmentTagResource::getNavigationGroup())->toBe('Entities')
        ->and(AlignmentTagResource::getNavigationLabel())->toBe('Alignment Tags');
});
