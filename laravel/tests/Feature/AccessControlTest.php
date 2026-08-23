<?php

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('approved admin passes any gate via the before hook', function () {
    Gate::define('alwaysFalse', fn () => false);
    $admin = User::factory()->admin()->approved()->create();

    expect(Gate::forUser($admin)->allows('alwaysFalse'))->toBeTrue();
});

test('unapproved admin is not auto-passed by the before hook', function () {
    Gate::define('alwaysFalse', fn () => false);
    $admin = User::factory()->admin()->unapproved()->create();

    expect(Gate::forUser($admin)->allows('alwaysFalse'))->toBeFalse();
});

test('regular user is not auto-passed by the before hook', function () {
    Gate::define('alwaysFalse', fn () => false);
    $user = User::factory()->approved()->create();

    expect(Gate::forUser($user)->allows('alwaysFalse'))->toBeFalse();
});

test('accessAdminPanel allows approved admin', function () {
    $admin = User::factory()->admin()->approved()->create();

    expect(Gate::forUser($admin)->allows('accessAdminPanel'))->toBeTrue();
});

test('accessAdminPanel denies unapproved admin', function () {
    $admin = User::factory()->admin()->unapproved()->create();

    expect(Gate::forUser($admin)->allows('accessAdminPanel'))->toBeFalse();
});

test('accessAdminPanel denies regular user', function () {
    $user = User::factory()->approved()->create();

    expect(Gate::forUser($user)->allows('accessAdminPanel'))->toBeFalse();
});

test('accessAdminPanel denies guest', function () {
    expect(Gate::allows('accessAdminPanel'))->toBeFalse();
});

test('canAccessPanel delegates to the accessAdminPanel gate', function () {
    $approvedAdmin = User::factory()->admin()->approved()->create();
    $unapprovedAdmin = User::factory()->admin()->unapproved()->create();
    $user = User::factory()->approved()->create();

    expect($approvedAdmin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
    expect($unapprovedAdmin->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

test('inertia shares the accessAdminPanel ability for admins', function () {
    $admin = User::factory()->admin()->approved()->create();

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.can.accessAdminPanel', true));
});

test('inertia does not share the accessAdminPanel ability for regular users', function () {
    $user = User::factory()->approved()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.can.accessAdminPanel', false));
});

test('an admin cannot remove their own admin access', function () {
    $admin = User::factory()->admin()->approved()->create();
    $this->actingAs($admin);

    expect(fn () => $admin->update(['role' => User::ROLE_USER]))
        ->toThrow(ValidationException::class);
});

test('the sole approved admin cannot be demoted', function () {
    $admin = User::factory()->admin()->approved()->create();

    expect(fn () => $admin->update(['role' => User::ROLE_USER]))
        ->toThrow(ValidationException::class);

    expect($admin->refresh()->role)->toBe(User::ROLE_ADMIN);
});

test('the sole approved admin cannot be unapproved', function () {
    $admin = User::factory()->admin()->approved()->create();

    expect(fn () => $admin->update(['is_approved' => false]))
        ->toThrow(ValidationException::class);

    expect($admin->refresh()->is_approved)->toBeTrue();
});

test('an admin can be demoted when another approved admin remains', function () {
    User::factory()->admin()->approved()->create();
    $other = User::factory()->admin()->approved()->create();

    $other->update(['role' => User::ROLE_USER]);

    expect($other->refresh()->role)->toBe(User::ROLE_USER);
});

test('promoting and approving a user is allowed', function () {
    $user = User::factory()->approved()->create();

    $user->update(['role' => User::ROLE_ADMIN]);

    expect($user->refresh()->isAdmin())->toBeTrue();
});

test('an admin cannot weaken their own access in the panel', function () {
    $admin = User::factory()->admin()->approved()->create();
    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $admin->getKey()])
        ->assertFormFieldDisabled('role')
        ->assertFormFieldDisabled('is_approved');

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('toggleApproval', $admin)
        ->assertTableActionHidden('delete', $admin);
});

test('an admin can manage another admin who is not the last', function () {
    User::factory()->admin()->approved()->create();
    $other = User::factory()->admin()->approved()->create();
    $this->actingAs(User::factory()->admin()->approved()->create());

    Livewire::test(ListUsers::class)
        ->assertTableActionVisible('toggleApproval', $other)
        ->assertTableActionVisible('delete', $other);

    Livewire::test(EditUser::class, ['record' => $other->getKey()])
        ->assertFormFieldEnabled('role');
});
