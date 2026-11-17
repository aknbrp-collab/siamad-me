<?php

use App\Enums\Role;
use App\Models\User;

/*
 * v7 D45, D46: the sidebar menu scrolls on its own, and every app page carries the confirmation dialog
 * that resources/js/confirm-dialog.js uses for wire:confirm.
 */

test('app pages have the scrolling sidebar menu and the confirmation dialog', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('data-test="sidebar-menu"', false)
        ->assertSee('data-test="confirm-dialog"', false)
        ->assertSee(__('Yes, continue'));
});

test('the confirmation script is loaded with the app', function () {
    expect(file_get_contents(resource_path('js/app.js')))->toContain("import './confirm-dialog.js';")
        ->and(file_get_contents(resource_path('js/confirm-dialog.js')))->toContain("hook('directive.init'");
});
