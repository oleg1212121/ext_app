<?php

use App\Filament\Resources\EntityResource\Pages\EditEntity;
use App\Filament\Resources\EntityResource\Pages\ListEntities;
use App\Filament\Resources\EntityTagResource\Pages\CreateEntityTag;
use App\Filament\Resources\EntityTagResource\Pages\ListEntityTags;
use App\Models\EntityTag;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('syncs entity tags through the entity relation without duplicating pivot rows', function () {
    $entity = createEntity('en');
    $entityTag = EntityTag::factory()->create();

    $entity->tags()->sync([$entityTag->id]);
    $entity->tags()->sync([$entityTag->id]);

    expect(DB::table('tagged_entities')->count())->toBeOne();
});

it('enforces the unique entity-tag pair in the pivot', function () {
    $entity = createEntity('en');
    $entityTag = EntityTag::factory()->create();

    $entity->tags()->attach($entityTag->id);
    $entity->tags()->attach($entityTag->id);
})->throws(QueryException::class);

it('timestamps pivot rows written through the entity relation', function () {
    $entity = createEntity('en');
    $entityTag = EntityTag::factory()->create();

    $entity->tags()->attach($entityTag->id);

    $pivot = DB::table('tagged_entities')->first();
    expect($pivot->entity_id)->toBe($entity->id)
        ->and($pivot->entity_tag_id)->toBe($entityTag->id)
        ->and($pivot->created_at)->not->toBeNull()
        ->and($pivot->updated_at)->not->toBeNull();
});

it('resolves the inverse entities relation on the entity tag', function () {
    $entity = createEntity('en');
    $entityTag = EntityTag::factory()->create();

    $entity->tags()->attach($entityTag);

    expect($entityTag->refresh()->entities->pluck('id'))->toContain($entity->id);
});

it('removes pivot rows when the entity is deleted', function () {
    $entity = createEntity('en');
    $entityTag = EntityTag::factory()->create();
    $entity->tags()->attach($entityTag);

    $entity->delete();

    expect(DB::table('tagged_entities')->count())->toBe(0);
});

it('removes pivot rows when the entity tag is deleted', function () {
    $entity = createEntity('en');
    $entityTag = EntityTag::factory()->create();
    $entity->tags()->attach($entityTag);

    $entityTag->delete();

    expect(DB::table('tagged_entities')->count())->toBe(0);
});

it('lists entity tags for an authenticated admin', function () {
    actingAsAdmin();

    $entityTag = EntityTag::factory()->create(['name' => 'Dialogue-heavy', 'slug' => 'dialogue-heavy']);

    Livewire::test(ListEntityTags::class)
        ->assertCanSeeTableRecords([$entityTag]);
});

it('creates an entity tag from the create page', function () {
    actingAsAdmin();

    Livewire::test(CreateEntityTag::class)
        ->fillForm(['name' => 'Dialogue-heavy', 'slug' => 'dialogue-heavy'])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect(EntityTag::query()->where('slug', 'dialogue-heavy')->where('name', 'Dialogue-heavy')->exists())->toBeTrue();
});

it('persists entity tags from the Entities edit form', function () {
    actingAsAdmin();

    $entity = createEntity('en');
    $entityTag = EntityTag::factory()->create();

    Livewire::test(EditEntity::class, ['record' => $entity->id])
        ->fillForm(['tags' => [$entityTag->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($entity->refresh()->tags->pluck('id'))->toContain($entityTag->id);
});

it('creates an entity tag inline from the Entities edit form and attaches it', function () {
    actingAsAdmin();

    $entity = createEntity('en');

    $component = Livewire::test(EditEntity::class, ['record' => $entity->id])
        ->callAction(
            TestAction::make('createOption')->schemaComponent('tags'),
            data: ['name' => 'Fresh tag', 'slug' => 'fresh-tag'],
        )
        ->assertHasNoActionErrors();

    $entityTag = EntityTag::query()->where('slug', 'fresh-tag')->first();
    expect($entityTag)->not->toBeNull();

    $component->assertSchemaStateSet(['tags' => [$entityTag->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($entity->refresh()->tags->pluck('id'))->toContain($entityTag->id);
});

it('narrows the Entities table to entities carrying the selected tag', function () {
    actingAsAdmin();

    $tagged = createEntity('en', attributes: ['name' => 'Tagged Entity']);
    $untagged = createEntity('en', attributes: ['name' => 'Plain Entity']);
    $entityTag = EntityTag::factory()->create();
    $tagged->tags()->attach($entityTag);

    Livewire::test(ListEntities::class)
        ->filterTable('tags', [$entityTag->id])
        ->assertCanSeeTableRecords([$tagged])
        ->assertCanNotSeeTableRecords([$untagged]);
});
