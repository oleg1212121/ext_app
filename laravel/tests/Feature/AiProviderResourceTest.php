<?php

use App\Filament\Resources\AiProviderResource;
use App\Filament\Resources\AiProviderResource\Pages\EditAiProvider;
use App\Filament\Resources\AiProviderResource\Pages\ListAiProviders;
use App\Models\AiProvider;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('lists providers for an authenticated admin', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $provider = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);

    Livewire::test(ListAiProviders::class)
        ->assertCanSeeTableRecords([$provider]);
});

it('toggles is_enabled immediately from the list', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);

    Livewire::test(ListAiProviders::class)
        ->callTableAction('toggleEnabled', $provider);

    expect($provider->refresh()->is_enabled)->toBeFalse();
});

it('does not expose a create page and keeps an edit page', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $pages = AiProviderResource::getPages();

    expect($pages)->not->toHaveKey('create');
    expect($pages)->toHaveKey('edit');
});

it('keeps the key field disabled so it cannot be changed on edit', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $provider = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);

    Livewire::test(EditAiProvider::class, ['record' => $provider->id])
        ->fillForm(['name' => 'Renamed', 'key' => 'hacked'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($provider->refresh()->name)->toBe('Renamed');
    expect($provider->refresh()->key)->toBe('openrouter');
});
