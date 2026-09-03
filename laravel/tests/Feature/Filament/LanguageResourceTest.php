<?php

use App\Filament\Resources\LanguageResource\Pages\CreateLanguage;
use App\Filament\Resources\LanguageResource\Pages\EditLanguage;
use App\Filament\Resources\LanguageResource\Pages\ListLanguages;
use App\Models\Language;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('lists languages for an authenticated admin', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $language = Language::create([
        'code' => 'fr',
        'name' => 'French',
        'native_name' => 'Français',
        'is_enabled' => true,
        'sort_order' => 2,
    ]);

    Livewire::test(ListLanguages::class)
        ->assertCanSeeTableRecords([$language]);
});

it('creates a language from the create page', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(CreateLanguage::class)
        ->fillForm([
            'code' => 'fr',
            'name' => 'French',
            'native_name' => 'Français',
            'is_enabled' => true,
            'sort_order' => 2,
        ])
        ->call('create')
        ->assertNotified()
        ->assertRedirect();

    expect(Language::query()->where('code', 'fr')->where('name', 'French')->exists())->toBeTrue();
});

it('rejects a duplicate language code', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Language::create(['code' => 'fr', 'name' => 'French', 'is_enabled' => true, 'sort_order' => 2]);

    Livewire::test(CreateLanguage::class)
        ->fillForm([
            'code' => 'fr',
            'name' => 'French duplicate',
            'is_enabled' => true,
            'sort_order' => 3,
        ])
        ->call('create')
        ->assertHasFormErrors(['code']);
});

it('rejects a non-lowercase language code', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(CreateLanguage::class)
        ->fillForm([
            'code' => 'FR',
            'name' => 'French',
            'is_enabled' => true,
            'sort_order' => 2,
        ])
        ->call('create')
        ->assertHasFormErrors(['code']);
});

it('edits a language from the edit page', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $language = Language::create(['code' => 'fr', 'name' => 'French', 'is_enabled' => true, 'sort_order' => 2]);

    Livewire::test(EditLanguage::class, ['record' => $language->id])
        ->fillForm(['name' => 'Français'])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($language->refresh()->name)->toBe('Français');
});

it('toggles is_enabled immediately from the list', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $language = Language::create(['code' => 'fr', 'name' => 'French', 'is_enabled' => true, 'sort_order' => 2]);

    Livewire::test(ListLanguages::class)
        ->callTableAction('toggleEnabled', $language);

    expect($language->refresh()->is_enabled)->toBeFalse();
});

it('deletes a language', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $language = Language::create(['code' => 'fr', 'name' => 'French', 'is_enabled' => true, 'sort_order' => 2]);

    Livewire::test(ListLanguages::class)
        ->callTableAction(DeleteAction::class, $language);

    expect(Language::query()->where('id', $language->id)->exists())->toBeFalse();
});
