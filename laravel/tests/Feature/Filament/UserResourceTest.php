<?php

use App\Models\User;

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
