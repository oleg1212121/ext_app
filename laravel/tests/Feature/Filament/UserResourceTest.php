<?php

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('admin can access admin panel', function () {
    $admin = User::factory()->admin()->approved()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk();
});

test('non-admin cannot access admin panel', function () {
    $user = User::factory()->approved()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertForbidden();
});

test('unapproved admin cannot access admin panel', function () {
    $admin = User::factory()->admin()->unapproved()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertForbidden();
});

test('admin can access users list', function () {
    $admin = User::factory()->admin()->approved()->create();

    $this->actingAs($admin)
        ->get('/admin/users')
        ->assertOk();
});

test('admin can access user edit page', function () {
    $admin = User::factory()->admin()->approved()->create();
    $user = User::factory()->approved()->create();

    $this->actingAs($admin)
        ->get('/admin/users/'.$user->getRouteKey().'/edit')
        ->assertOk();
});

test('admin can access user create page', function () {
    $admin = User::factory()->admin()->approved()->create();

    $this->actingAs($admin)
        ->get('/admin/users/create')
        ->assertOk();
});

test('non-admin cannot access users list', function () {
    $user = User::factory()->approved()->create();

    $this->actingAs($user)
        ->get('/admin/users')
        ->assertForbidden();
});

test('admin can create a user with a password', function () {
    $admin = User::factory()->admin()->approved()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'pepega',
            'email' => 'trutru@tru.tru',
            'role' => User::ROLE_USER,
            'is_approved' => true,
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    expect(User::query()->where('email', 'trutru@tru.tru')->exists())->toBeTrue();

    $user = User::where('email', 'trutru@tru.tru')->first();
    expect(Hash::check('secret-password', $user->password))->toBeTrue();
});

test('admin cannot create a user without a password', function () {
    $admin = User::factory()->admin()->approved()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'pepega',
            'email' => 'trutru@tru.tru',
            'role' => User::ROLE_USER,
            'is_approved' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);

    expect(User::query()->where('email', 'trutru@tru.tru')->exists())->toBeFalse();
});
