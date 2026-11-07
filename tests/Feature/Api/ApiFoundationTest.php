<?php

use App\Actions\People\ResetUserPassword;
use App\Enums\Role;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Notify;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

function apiLogin(string $username, array $extra = []): string
{
    return test()->postJson('/api/v1/auth/login', [
        'username' => $username, 'password' => 'password', 'device_name' => 'Samsung A54', 'platform' => 'android', ...$extra,
    ])->assertCreated()->json('token');
}

test('login gives a device token; wrong passwords and inactive accounts are refused', function () {
    $student = Student::factory()->create();

    $response = $this->postJson('/api/v1/auth/login', ['username' => $student->user->username, 'password' => 'password', 'device_name' => 'Samsung A54'])
        ->assertCreated()
        ->assertJsonPath('user.username', $student->user->username)
        ->assertJsonPath('user.roles.0.value', 'student')
        ->assertJsonPath('user.active_role', null)
        ->assertJsonPath('user.student.nim', $student->nim);
    expect($response->json('token'))->toBeString();

    $this->postJson('/api/v1/auth/login', ['username' => $student->user->username, 'password' => 'salah', 'device_name' => 'x'])
        ->assertUnprocessable()->assertJsonStructure(['message', 'errors' => ['username']]);

    $inactive = User::factory()->inactive()->withRole(Role::Admin)->create();
    $this->postJson('/api/v1/auth/login', ['username' => $inactive->username, 'password' => 'password', 'device_name' => 'x'])
        ->assertUnprocessable()->assertJsonPath('errors.username.0', __('This account has been deactivated. Please contact the academic office.'));
});

test('the token works as a bearer token; logout ends only this device', function () {
    $student = Student::factory()->create();
    $phone = apiLogin($student->user->username);
    $tablet = apiLogin($student->user->username, ['device_name' => 'iPad', 'platform' => 'ios']);

    $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonStructure(['message']);
    $this->withToken($phone)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.active_role', 'student');

    $this->withToken($phone)->getJson('/api/v1/auth/devices')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['name' => 'iPad', 'platform' => 'ios', 'current' => false]);

    $this->withToken($phone)->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->withToken($phone)->getJson('/api/v1/me')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($tablet)->getJson('/api/v1/me')->assertOk();
});

test('users with several roles choose one with X-Role; a role they do not hold is refused', function () {
    $teacher = Teacher::factory()->create();
    $teacher->user->roles()->create(['role' => Role::AcademicMonitor]);
    $token = apiLogin($teacher->user->username);

    $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.active_role', null);
    $this->withToken($token)->withHeader('X-Role', 'academic_monitor')->getJson('/api/v1/me')->assertJsonPath('data.active_role', 'academic_monitor');
    $this->withToken($token)->withHeader('X-Role', 'superadmin')->getJson('/api/v1/me')
        ->assertForbidden()->assertJsonPath('code', 'role_not_held');
});

test('two-factor accounts send the authenticator code, or a recovery code', function () {
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->withRole(Role::Admin)->create([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['kode-cadangan-1', 'kode-cadangan-2'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $login = ['username' => $user->username, 'password' => 'password', 'device_name' => 'Pixel'];

    $this->postJson('/api/v1/auth/login', $login)->assertUnprocessable()->assertJsonPath('code', 'two_factor_required');
    $this->postJson('/api/v1/auth/login', [...$login, 'code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/auth/login', [...$login, 'code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertCreated();

    $this->postJson('/api/v1/auth/login', [...$login, 'recovery_code' => 'kode-cadangan-1'])->assertCreated();
    expect($user->fresh()->recoveryCodes())->not->toContain('kode-cadangan-1');
});

test('a temporary password must be changed first; changing it signs out the other devices', function () {
    $user = User::factory()->mustChangePassword()->withRole(Role::Guardian)->create();
    $old = apiLogin($user->username, ['device_name' => 'Old phone']);
    $token = apiLogin($user->username);

    $this->withToken($token)->getJson('/api/v1/notifications')->assertForbidden()->assertJsonPath('code', 'password_change_required');
    $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.must_change_password', true);

    $this->withToken($token)->putJson('/api/v1/password', ['current_password' => 'salah', 'password' => 'Baru-12345!', 'password_confirmation' => 'Baru-12345!'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->withToken($token)->putJson('/api/v1/password', ['current_password' => 'password', 'password' => 'Baru-12345!', 'password_confirmation' => 'Baru-12345!'])
        ->assertNoContent();

    expect($user->fresh()->must_change_password)->toBeFalse()->and($user->tokens()->pluck('name')->all())->toBe(['Samsung A54']);
    $this->withToken($token)->getJson('/api/v1/notifications')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($old)->getJson('/api/v1/me')->assertUnauthorized();
});

test('a deactivated account or a password reset by the Admin ends the app sessions', function () {
    $student = Student::factory()->create();
    $token = apiLogin($student->user->username);

    $student->user->update(['is_active' => false]);
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'account_inactive');
    expect($student->user->tokens()->count())->toBe(0);

    $student->user->update(['is_active' => true]);
    apiLogin($student->user->username);
    app(ResetUserPassword::class)->handle($student->user);
    expect($student->user->tokens()->count())->toBe(0);
});

test('the profile follows the role: mahasantri change their own fields and photo, not their name', function () {
    Storage::fake('local');
    $student = Student::factory()->create(['phone' => '0811']);
    $token = apiLogin($student->user->username);
    $name = $student->user->name;

    $this->withToken($token)->getJson('/api/v1/profile')->assertOk()
        ->assertJsonPath('data.kind', 'student')
        ->assertJsonPath('data.fields.nim', $student->nim)
        ->assertJsonPath('data.fields.phone', '0811');

    $this->withToken($token)->patch('/api/v1/profile', [
        'phone' => '0812-3456', 'name' => 'Nama Lain', 'photo' => UploadedFile::fake()->image('foto.jpg', 400, 400),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.fields.phone', '0812-3456');

    expect($student->fresh()->phone)->toBe('0812-3456')
        ->and($student->user->fresh()->name)->toBe($name)
        ->and($student->fresh()->photo_path)->not->toBeNull();

    // The photo comes as a signed link that works without the token, and only while it is valid.
    $url = $this->withToken($token)->getJson('/api/v1/me')->json('data.photo_url');
    $this->app['auth']->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->get($url)->assertOk();
    $this->get($url.'x')->assertForbidden();
    $this->travel(11)->minutes();
    $this->get($url)->assertForbidden();
});

test('staff change their name and email through the profile', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $token = apiLogin($admin->username);

    $this->withToken($token)->patchJson('/api/v1/profile', ['name' => 'Ustadz Admin', 'email' => 'bukan-email'])->assertUnprocessable();
    $this->withToken($token)->patchJson('/api/v1/profile', ['name' => 'Ustadz Admin', 'email' => 'admin@contoh.ac.id'])
        ->assertOk()->assertJsonPath('data.kind', 'staff')->assertJsonPath('data.fields.name', 'Ustadz Admin');
});

test('notifications: list with the unread count, read one, read all', function () {
    $student = Student::factory()->create();
    Notify::send([$student->user], 'test', 'Tugas baru', 'Fiqh Ibadah: Ringkasan bab thaharah', '/mahasantri/classes', 'clipboard');
    Notify::send([$student->user], 'test', 'Nilai final', 'Nahwu', '/mahasantri/khs', 'chart-bar');
    $token = apiLogin($student->user->username);

    $list = $this->withToken($token)->getJson('/api/v1/notifications')->assertOk()
        ->assertJsonPath('meta.unread', 2)
        ->assertJsonPath('data.0.read', false)
        ->assertJsonStructure(['data' => [['id', 'kind', 'title', 'body', 'link', 'icon', 'count', 'read', 'created_at']], 'links', 'meta']);

    $this->withToken($token)->postJson('/api/v1/notifications/'.$list->json('data.0.id').'/read')->assertNoContent();
    $this->withToken($token)->getJson('/api/v1/notifications?unread=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.unread', 1);
    $this->withToken($token)->postJson('/api/v1/notifications/read-all')->assertNoContent();
    expect($student->user->unreadNotifications()->count())->toBe(0);

    // Someone else's notification cannot be marked.
    $other = User::factory()->withRole(Role::Admin)->create();
    Notify::send([$other], 'test', 'Lain', 'x', '/dashboard', 'bell');
    $this->withToken($token)->postJson('/api/v1/notifications/'.$other->notifications()->value('id').'/read')->assertNotFound();
});

test('the app reads its name, images and institution without logging in, and stores a push token', function () {
    Setting::set('app.name', 'SIAKAD Contoh');
    Setting::set('institution.name', "Ma'had Aly Contoh");

    $this->getJson('/api/v1/app')->assertOk()
        ->assertJsonPath('data.app.name', 'SIAKAD Contoh')
        ->assertJsonPath('data.institution.name', "Ma'had Aly Contoh")
        ->assertJsonPath('data.push_enabled', false);

    $student = Student::factory()->create();
    $token = apiLogin($student->user->username);
    $this->withToken($token)->putJson('/api/v1/auth/push-token', ['fcm_token' => 'fcm-abc'])->assertNoContent();
    expect($student->user->tokens()->value('fcm_token'))->toBe('fcm-abc');
    $this->withToken($token)->getJson('/api/v1/auth/devices')->assertJsonPath('data.0.push_enabled', true);
});

test('login is limited to five tries a minute', function () {
    $user = User::factory()->withRole(Role::Admin)->create();

    foreach (range(1, 5) as $try) {
        $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => 'salah', 'device_name' => 'x'])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => 'password', 'device_name' => 'x'])->assertTooManyRequests();
});

test('users see and sign out their app devices in Settings', function () {
    $student = Student::factory()->create();
    apiLogin($student->user->username);
    apiLogin($student->user->username, ['device_name' => 'iPad']);

    $this->actingAs($student->user)->withSession(['active_role' => Role::Student->value]);
    $this->get(route('devices.edit'))->assertOk()->assertSee('Samsung A54')->assertSee('iPad');

    Livewire::test('pages::settings.devices')
        ->call('signOut', $student->user->tokens()->where('name', 'iPad')->value('id'))
        ->assertDontSee('iPad')
        ->call('signOutAll');
    expect($student->user->tokens()->count())->toBe(0);
});

test('the API docs are for Superadmin unless made public', function () {
    $this->app['env'] = 'production';

    $this->get('/docs/api')->assertForbidden();
    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create())->get('/docs/api')->assertOk();

    auth()->logout();
    config(['siakad.api_docs_public' => true]);
    $this->get('/docs/api.json')->assertOk()->assertJsonPath('info.title', 'SIAKAD API');
});
