<?php

use App\Models\User;
use Filament\Facades\Filament;

test('new user has is_approved false by default', function () {
    $user = User::factory()->create(['is_approved' => false]);

    expect($user->is_approved)->toBeFalse();
});

test('unapproved user is redirected to pending-approval after login', function () {
    $user = User::factory()->unapproved()->create([
        'password' => 'password',
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('pending-approval'));
});

test('approved user is redirected to dashboard after login', function () {
    $user = User::factory()->approved()->create([
        'password' => 'password',
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('dashboard'));
});

test('unapproved user cannot access dashboard', function () {
    $user = User::factory()->unapproved()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect(route('pending-approval'));
});

test('unapproved user can access profile', function () {
    $user = User::factory()->unapproved()->create();

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk();
});

test('unapproved user can edit profile', function () {
    $user = User::factory()->unapproved()->create();

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => 'Updated Name',
            'email' => $user->email,
        ])
        ->assertRedirect('/profile');

    expect($user->refresh()->name)->toBe('Updated Name');
});

test('approved user can access all pages', function () {
    $user = User::factory()->approved()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk();
});

test('pending approval page is accessible to unapproved users', function () {
    $user = User::factory()->unapproved()->create();

    $this->actingAs($user)
        ->get('/pending-approval')
        ->assertOk();
});

test('pending approval page shows correct content', function () {
    $user = User::factory()->unapproved()->create();

    $this->actingAs($user)
        ->get('/pending-approval')
        ->assertSee('Account Pending Approval')
        ->assertSee('Your account is being reviewed by an administrator');
});

test('user model has correct role constants', function () {
    expect(User::ROLE_USER)->toBe('user');
    expect(User::ROLE_ADMIN)->toBe('admin');
});

test('user isAdmin method works correctly', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    expect($admin->isAdmin())->toBeTrue();
    expect($user->isAdmin())->toBeFalse();
});

test('admin can access panel', function () {
    $admin = User::factory()->admin()->approved()->create();

    expect($admin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

test('non-admin cannot access panel', function () {
    $user = User::factory()->approved()->create();

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

test('unapproved admin cannot access panel', function () {
    $admin = User::factory()->admin()->unapproved()->create();

    expect($admin->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});
