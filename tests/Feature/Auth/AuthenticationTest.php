<?php

use App\Enums\Role;
use App\Models\User;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk()->assertSee(__('ID number'));
});

test('users can authenticate with their ID number', function () {
    $user = User::factory()->withRole(Role::Student)->create(['username' => '2026110001']);

    $response = $this->post(route('login.store'), [
        'username' => '2026110001',
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

test('the ID number is matched case-insensitively', function () {
    $user = User::factory()->withRole(Role::Guardian)->create(['username' => 'WS-AB12CD34']);

    expect($user->username)->toBe('ws-ab12cd34');

    $this->post(route('login.store'), [
        'username' => 'WS-AB12CD34',
        'password' => 'password',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

test('users without an email can authenticate', function () {
    $user = User::factory()->withoutEmail()->withRole(Role::Student)->create();

    $this->post(route('login.store'), [
        'username' => $user->username,
        'password' => 'password',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

test('users can not authenticate with their email', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'username' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('username');

    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'username' => $user->username,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrorsIn('username');

    $this->assertGuest();
});

test('deactivated users can not authenticate', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('login.store'), [
        'username' => $user->username,
        'password' => 'password',
    ])->assertSessionHasErrors(['username' => __('This account has been deactivated. Please contact the academic office.')]);

    $this->assertGuest();
});

test('users deactivated during a session are logged out', function () {
    $user = User::factory()->withRole(Role::Student)->create();

    $this->actingAs($user)->get(route('mahasantri.dashboard'))->assertOk();

    $user->update(['is_active' => false]);

    $this->get(route('mahasantri.dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'username' => $user->username,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('registration is disabled', function () {
    expect(Route::has('register'))->toBeFalse();
    expect(Route::has('verification.notice'))->toBeFalse();
});
