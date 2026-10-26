<?php

use App\Enums\Role;
use App\Models\User;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->withRole(Role::Student)->create());

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertSee($user->username);
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
});

test('email is optional', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('email', '')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->refresh()->email)->toBeNull();
});

test('email must be unique', function () {
    $other = User::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('email', $other->email)
        ->call('updateProfileInformation')
        ->assertHasErrors(['email']);
});

test('users can not delete their own account', function () {
    $this->actingAs(User::factory()->withRole(Role::Student)->create());

    $this->get(route('profile.edit'))->assertDontSee('delete-user-button');
});
