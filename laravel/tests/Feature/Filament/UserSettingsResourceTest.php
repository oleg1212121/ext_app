<?php

use App\Filament\Resources\UserSettingsResource\Pages\CreateUserSettings;
use App\Filament\Resources\UserSettingsResource\Pages\EditUserSettings;
use App\Filament\Resources\UserSettingsResource\Pages\ListUserSettings;
use App\Models\Language;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(
        User::factory()->admin()->create()
    );
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('lists user settings for an authenticated admin', function () {
    $settings = User::factory()->create()->settings;

    Livewire::test(ListUserSettings::class)
        ->assertCanSeeTableRecords([$settings]);
});

it('creates user settings from the create page', function () {
    $user = User::query()->create([
        'name' => 'No Settings User',
        'email' => 'nosettings@example.com',
        'password' => Hash::make('password'),
        'role' => User::ROLE_USER,
        'is_approved' => true,
    ]);

    $russian = Language::create(['code' => 'ru', 'name' => 'Russian', 'is_enabled' => true, 'sort_order' => 1]);

    Livewire::test(CreateUserSettings::class)
        ->fillForm([
            'user_id' => $user->id,
            'native_language_id' => $russian->id,
        ])
        ->call('create')
        ->assertNotified()
        ->assertRedirect();

    expect($user->refresh()->settings->native_language_id)->toBe($russian->id);
});

it('edits user settings from the edit page', function () {
    $user = User::factory()->create();
    $russian = Language::create(['code' => 'ru', 'name' => 'Russian', 'is_enabled' => true, 'sort_order' => 1]);

    Livewire::test(EditUserSettings::class, ['record' => $user->settings->id])
        ->fillForm(['native_language_id' => $russian->id])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($user->settings->refresh()->native_language_id)->toBe($russian->id);
});
