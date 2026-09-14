<?php

use App\Filament\Resources\UiStringKeyResource\Pages\CreateUiStringKey;
use App\Filament\Resources\UiStringKeyResource\Pages\EditUiStringKey;
use App\Filament\Resources\UiStringKeyResource\Pages\ListUiStringKeys;
use App\Models\Language;
use App\Models\UiStringKey;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    createLanguages();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('lists ui string keys for an authenticated admin', function () {

    $key = UiStringKey::create(['key' => 'test.listed']);

    Livewire::test(ListUiStringKeys::class)
        ->assertCanSeeTableRecords([$key]);
});

it('creates a ui string key with translations from the create page', function () {

    $en = Language::where('code', 'en')->first();
    $ru = Language::where('code', 'ru')->first();

    Livewire::test(CreateUiStringKey::class)
        ->fillForm([
            'key' => 'test.created',
            'strings' => [
                ['language_id' => $en->id, 'text' => 'Created'],
                ['language_id' => $ru->id, 'text' => 'Создано'],
            ],
        ])
        ->call('create')
        ->assertNotified()
        ->assertRedirect();

    $key = UiStringKey::where('key', 'test.created')->first();
    expect($key)->not->toBeNull();
    expect($key->group)->toBe('test');
    expect($key->strings()->count())->toBe(2);
});

it('edits translations side by side from the edit page', function () {

    $ru = Language::where('code', 'ru')->first();
    $key = UiStringKey::create(['key' => 'test.editable']);
    $key->strings()->create(['language_id' => $ru->id, 'text' => 'Старое']);

    Livewire::test(EditUiStringKey::class, ['record' => $key->id])
        ->fillForm(['strings' => [['language_id' => $ru->id, 'text' => 'Новое']]])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    // The relationship repeater replaces rewritten rows delete-and-recreate.
    $key->refresh();
    expect($key->strings()->count())->toBe(1);
    expect($key->strings()->first()->text)->toBe('Новое');
});

it('rejects an invalid key format', function () {

    Livewire::test(CreateUiStringKey::class)
        ->fillForm([
            'key' => 'no-dot-key',
            'strings' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['key']);
});

it('forbids non-admins from the ui strings resource', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->get('/admin/ui-string-keys')->assertForbidden();
});
