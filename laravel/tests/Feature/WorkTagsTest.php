<?php

use App\Filament\Resources\WorkResource\Pages\EditWork;
use App\Filament\Resources\WorkResource\Pages\ListWorks;
use App\Filament\Resources\WorkTagResource\Pages\CreateWorkTag;
use App\Filament\Resources\WorkTagResource\Pages\ListWorkTags;
use App\Models\WorkTag;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('syncs work tags through the work relation without duplicating pivot rows', function () {
    $work = createWork();
    $workTag = WorkTag::factory()->create();

    $work->tags()->sync([$workTag->id]);
    $work->tags()->sync([$workTag->id]);

    expect(DB::table('tagged_works')->count())->toBeOne();
});

it('enforces the unique work-tag pair in the pivot', function () {
    $work = createWork();
    $workTag = WorkTag::factory()->create();

    $work->tags()->attach($workTag->id);
    $work->tags()->attach($workTag->id);
})->throws(QueryException::class);

it('timestamps pivot rows written through the work relation', function () {
    $work = createWork();
    $workTag = WorkTag::factory()->create();

    $work->tags()->attach($workTag->id);

    $pivot = DB::table('tagged_works')->first();
    expect($pivot->work_id)->toBe($work->id)
        ->and($pivot->work_tag_id)->toBe($workTag->id)
        ->and($pivot->created_at)->not->toBeNull()
        ->and($pivot->updated_at)->not->toBeNull();
});

it('resolves the inverse works relation on the work tag', function () {
    $work = createWork();
    $workTag = WorkTag::factory()->create();

    $work->tags()->attach($workTag);

    expect($workTag->refresh()->works->pluck('id'))->toContain($work->id);
});

it('removes pivot rows when the work is deleted', function () {
    $work = createWork();
    $workTag = WorkTag::factory()->create();
    $work->tags()->attach($workTag);

    $work->delete();

    expect(DB::table('tagged_works')->count())->toBe(0);
});

it('removes pivot rows when the work tag is deleted', function () {
    $work = createWork();
    $workTag = WorkTag::factory()->create();
    $work->tags()->attach($workTag);

    $workTag->delete();

    expect(DB::table('tagged_works')->count())->toBe(0);
});

it('lists work tags for an authenticated admin', function () {
    actingAsAdmin();

    $workTag = WorkTag::factory()->create(['name' => 'Science', 'slug' => 'science']);

    Livewire::test(ListWorkTags::class)
        ->assertCanSeeTableRecords([$workTag]);
});

it('creates a work tag from the create page', function () {
    actingAsAdmin();

    Livewire::test(CreateWorkTag::class)
        ->fillForm(['name' => 'Science', 'slug' => 'science'])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect(WorkTag::query()->where('slug', 'science')->where('name', 'Science')->exists())->toBeTrue();
});

it('persists work tags from the Works edit form', function () {
    actingAsAdmin();

    $work = createWork();
    $workTag = WorkTag::factory()->create();

    Livewire::test(EditWork::class, ['record' => $work->id])
        ->fillForm(['tags' => [$workTag->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($work->refresh()->tags->pluck('id'))->toContain($workTag->id);
});

it('creates a work tag inline from the Works edit form and attaches it', function () {
    actingAsAdmin();

    $work = createWork();

    $component = Livewire::test(EditWork::class, ['record' => $work->id])
        ->callAction(
            TestAction::make('createOption')->schemaComponent('tags'),
            data: ['name' => 'Fresh tag', 'slug' => 'fresh-tag'],
        )
        ->assertHasNoActionErrors();

    $workTag = WorkTag::query()->where('slug', 'fresh-tag')->first();
    expect($workTag)->not->toBeNull();

    $component->assertSchemaStateSet(['tags' => [$workTag->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($work->refresh()->tags->pluck('id'))->toContain($workTag->id);
});

it('narrows the Works table to works carrying the selected tag', function () {
    actingAsAdmin();

    $tagged = createWork(['title' => 'Tagged Work']);
    $untagged = createWork(['title' => 'Plain Work']);
    $workTag = WorkTag::factory()->create();
    $tagged->tags()->attach($workTag);

    Livewire::test(ListWorks::class)
        ->filterTable('tags', [$workTag->id])
        ->assertCanSeeTableRecords([$tagged])
        ->assertCanNotSeeTableRecords([$untagged]);
});
