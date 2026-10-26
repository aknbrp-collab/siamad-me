<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('users with a temporary password are sent to change it', function () {
    $user = User::factory()->mustChangePassword()->withRole(Role::Student)->create();

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertRedirect(route('password.change'));
    $this->get(route('mahasantri.dashboard'))->assertRedirect(route('password.change'));
    $this->get(route('profile.edit'))->assertRedirect(route('password.change'));
    $this->get(route('password.change'))->assertOk();
});

test('the temporary password can be replaced', function () {
    $user = User::factory()->mustChangePassword()->withRole(Role::Student)->create();

    $this->actingAs($user);

    Livewire::test('pages::auth.change-password')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->must_change_password)->toBeFalse();
    expect(Hash::check('new-password', $user->password))->toBeTrue();

    $this->get(route('mahasantri.dashboard'))->assertOk();
});

test('the new password must differ from the temporary one', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user);

    Livewire::test('pages::auth.change-password')
        ->set('current_password', 'password')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('changePassword')
        ->assertHasErrors(['password']);

    expect($user->refresh()->must_change_password)->toBeTrue();
});

test('the temporary password must be correct', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user);

    Livewire::test('pages::auth.change-password')
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('changePassword')
        ->assertHasErrors(['current_password']);
});
