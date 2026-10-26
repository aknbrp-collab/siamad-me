<?php

use App\Enums\Role;
use App\Models\User;
use Livewire\Livewire;

test('users with several roles must choose one', function () {
    $user = User::factory()->withRole(Role::Teacher, Role::AcademicMonitor)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('role.select'));

    $this->get(route('role.select'))
        ->assertOk()
        ->assertSee(Role::Teacher->label())
        ->assertSee(Role::AcademicMonitor->label())
        ->assertDontSee(Role::Admin->label());
});

test('choosing a role opens its dashboard', function () {
    $user = User::factory()->withRole(Role::Teacher, Role::AcademicMonitor)->create();

    $this->actingAs($user);

    Livewire::test('pages::auth.select-role')
        ->call('choose', Role::AcademicMonitor->value)
        ->assertRedirect(route('monitor.dashboard'));

    $this->get(route('dashboard'))->assertRedirect(route('monitor.dashboard'));
    $this->get(route('ustadz.dashboard'))->assertForbidden();
});

test('users can not choose a role they do not hold', function () {
    $user = User::factory()->withRole(Role::Teacher, Role::AcademicMonitor)->create();

    $this->actingAs($user);

    Livewire::test('pages::auth.select-role')
        ->call('choose', Role::Superadmin->value)
        ->assertForbidden();
});

test('a single role is selected automatically', function () {
    $user = User::factory()->withRole(Role::Guardian)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('wali.dashboard'));
});

test('a revoked role can no longer be used', function () {
    $user = User::factory()->withRole(Role::Teacher, Role::AcademicMonitor)->create();

    $this->actingAs($user);
    Livewire::test('pages::auth.select-role')->call('choose', Role::Teacher->value);

    $user->removeRole(Role::Teacher);

    $this->get(route('ustadz.dashboard'))->assertForbidden();
    $this->get(route('dashboard'))->assertRedirect(route('monitor.dashboard'));
});
