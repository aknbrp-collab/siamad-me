<?php

use App\Enums\Role;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('each role is sent to its own dashboard', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route($role->homeRoute()));

    $this->get(route($role->homeRoute()))
        ->assertOk()
        ->assertSee($role->label());
})->with(Role::cases());

test('users can not open another role\'s area', function () {
    $student = User::factory()->withRole(Role::Student)->create();

    $this->actingAs($student)->get(route('finance.dashboard'))->assertForbidden();
    $this->get(route('superadmin.settings'))->assertForbidden();
});

test('superadmin can open the admin area', function () {
    $user = User::factory()->withRole(Role::Superadmin)->create();

    $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
});

test('users without a role are refused', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
});

test('the sidebar shows the active role\'s menu', function () {
    $user = User::factory()->withRole(Role::Superadmin)->create();

    $this->actingAs($user)
        ->get(route('superadmin.dashboard'))
        ->assertSee(route('superadmin.settings'))
        ->assertSee(route('superadmin.audit-logs'));
});

test('generic dashboards link to the role\'s menu instead of placeholders', function (Role $role, string $shortcut) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get(route($role->homeRoute()))
        ->assertOk()
        ->assertSee('data-test="shortcuts"', false)
        ->assertSee(route($shortcut))
        ->assertDontSee(__('Coming soon'));
})->with([
    [Role::Superadmin, 'superadmin.users.index'],
    [Role::Admin, 'admin.students.index'],
    [Role::Student, 'mahasantri.khs'],
    [Role::Guardian, 'wali.academic'],
]);
